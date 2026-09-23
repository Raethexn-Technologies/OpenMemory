<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationImport;
use App\Models\ConversationMessage;
use App\Models\ConversationRawRecord;
use App\Services\Conversations\Adapters\ConversationArchiveAdapter;
use App\Services\Conversations\Archive\ArchiveException;
use App\Services\Conversations\Archive\ArchiveLimits;
use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\Archive\ArchiveSourceFactory;
use App\Services\RedactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports a provider archive into the normalized conversation model.
 *
 * The whole path is local. Parsing, hashing, normalization, redaction, and
 * persistence involve no network call and no model call. That is not an
 * incidental property: an archive of someone's years of conversations is
 * probably the most sensitive file on their machine, and sending it anywhere to
 * be parsed would betray the reason for importing it in the first place.
 *
 * Idempotency is the other governing rule. People re-export their history
 * repeatedly, and each new archive is mostly the previous one. Importing the
 * same archive twice must converge rather than duplicate, and importing a newer
 * archive must merge. Both fall out of two decisions: identity keyed on the
 * provider's own conversation and message identifiers, and content hashes that
 * distinguish "changed" from "seen again".
 *
 * Messages that exist locally but are absent from a newer archive are kept. A
 * conversation the user deleted at the provider is still part of their history,
 * and an importer that quietly mirrored provider deletions would make the
 * corpus less durable than the silo it was meant to outlast. The retention is
 * reported so it is visible rather than assumed.
 */
class ConversationImportService
{
    public function __construct(
        private readonly ArchiveSourceFactory $sources,
        private readonly ConversationArchiveRegistry $registry,
        private readonly RedactionService $redactor,
    ) {}

    /**
     * Import an archive from a local path.
     *
     * @param  array{provider?: string|null, dry_run?: bool, limit?: int|null, store_raw?: bool|null, limits?: ArchiveLimits|null, on_progress?: callable|null}  $options
     * @return array{import: ConversationImport|null, report: ImportReport, provider: string, adapter: string}
     *
     * @throws ArchiveException when the archive cannot be opened or no adapter supports it.
     */
    public function importPath(string $userId, string $path, array $options = []): array
    {
        $limits = $options['limits'] ?? ArchiveLimits::fromConfig();
        $source = $this->sources->open($path, $limits);

        try {
            return $this->importSource($userId, $source, $options + ['limits' => $limits]);
        } finally {
            $source->close();
        }
    }

    /**
     * @param  array{provider?: string|null, dry_run?: bool, limit?: int|null, store_raw?: bool|null, limits?: ArchiveLimits|null, on_progress?: callable|null}  $options
     * @return array{import: ConversationImport|null, report: ImportReport, provider: string, adapter: string}
     */
    public function importSource(string $userId, ArchiveSource $source, array $options = []): array
    {
        $limits = $options['limits'] ?? ArchiveLimits::fromConfig();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $options['limit'] ?? null;
        $storeRaw = $options['store_raw'] ?? (bool) config('conversations.store_raw', true);
        $onProgress = $options['on_progress'] ?? null;

        [$adapter, $detection] = $this->registry->detect($source, $options['provider'] ?? null);

        if ($adapter === null) {
            throw new ArchiveException($detection->reason ?? 'No adapter could read this archive.');
        }

        $report = new ImportReport();

        // Entry names the archive layer refused are reported, not hidden. An
        // archive containing a traversal entry is worth knowing about even when
        // the importer was never going to touch it.
        if (method_exists($source, 'refusedEntries')) {
            foreach ($source->refusedEntries() as $refused) {
                $report->warn(sprintf(
                    'Archive entry %s was refused (%s) and not read.',
                    mb_substr((string) $refused['name'], 0, 120),
                    $refused['reason'],
                ));
            }
        }

        $import = $dryRun ? null : $this->createImportRow($userId, $source, $adapter);

        try {
            foreach ($adapter->conversations($source, $detection) as $conversation) {
                if ($limit !== null && $report->conversationsSeen >= $limit) {
                    $report->warn("Import stopped at the requested limit of {$limit} conversations.");
                    break;
                }

                $report->conversationsSeen++;
                $report->warnAll($conversation->warnings);

                if ($dryRun) {
                    $report->messagesSeen += count($conversation->messages);
                } else {
                    $this->persistConversation($userId, $conversation, $adapter, $import, $report, $limits, $storeRaw);
                }

                if (is_callable($onProgress)) {
                    $onProgress($report->conversationsSeen, $conversation);
                }
            }

            $report->warnAll($adapter->warnings());
        } catch (Throwable $exception) {
            $report->warnAll($adapter->warnings());

            if ($import !== null) {
                $this->finishImportRow($import, ConversationImport::STATUS_FAILED, $report, 'Import failed.');
            }

            throw $exception;
        }

        if ($import !== null) {
            $this->finishImportRow($import, ConversationImport::STATUS_COMPLETED, $report, null);
        }

        return [
            'import' => $import,
            'report' => $report,
            'provider' => $adapter->provider(),
            'adapter' => $adapter->displayName(),
        ];
    }

    /**
     * Persist one conversation and its messages in a single transaction.
     *
     * Per-conversation transactions rather than one transaction for the whole
     * archive: a single transaction spanning tens of thousands of messages would
     * hold locks for the length of the import and would lose everything on a
     * failure near the end. Because the import is idempotent, a crashed run is
     * resumed by simply running it again.
     */
    private function persistConversation(
        string $userId,
        NormalizedConversation $conversation,
        ConversationArchiveAdapter $adapter,
        ?ConversationImport $import,
        ImportReport $report,
        ArchiveLimits $limits,
        bool $storeRaw,
    ): void {
        DB::transaction(function () use ($userId, $conversation, $adapter, $import, $report, $limits, $storeRaw) {
            $existing = Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', $conversation->provider)
                ->where('provider_conversation_id', $conversation->providerConversationId)
                ->first();

            $hash = $conversation->contentHash();
            $isNew = $existing === null;
            $changed = ! $isNew && $existing->content_hash !== $hash;

            $model = $existing ?? new Conversation([
                'user_id' => $userId,
                'provider' => $conversation->provider,
                'provider_conversation_id' => $conversation->providerConversationId,
                'visibility' => (string) config('conversations.default_visibility', Conversation::VISIBILITY_PRIVATE),
                'first_import_id' => $import?->id,
            ]);

            $model->title = $conversation->title;
            $model->source_account_label = $conversation->sourceAccountLabel;
            $model->workspace_label = $conversation->workspaceLabel;
            $model->provider_created_at = $conversation->createdAt;
            $model->provider_updated_at = $conversation->updatedAt;
            $model->first_message_at = $conversation->firstMessageAt();
            $model->last_message_at = $conversation->lastMessageAt();
            $model->models = $conversation->models() ?: null;
            $model->content_hash = $hash;
            $model->parser_version = $adapter->parserVersion();
            $model->last_import_id = $import?->id;
            $model->provider_metadata = $conversation->providerMetadata ?: null;
            $model->save();

            $redactionCategories = $this->syncMessages($userId, $model, $conversation, $report, $limits);

            $model->message_count = ConversationMessage::where('conversation_id', $model->id)->count();
            $model->redaction = $redactionCategories === [] ? null : ['categories' => $redactionCategories];
            $model->save();

            if ($isNew) {
                $report->conversationsNew++;
            } elseif ($changed) {
                $report->conversationsUpdated++;
            } else {
                $report->conversationsUnchanged++;
            }

            if ($storeRaw && $conversation->rawPayload !== null) {
                $this->storeRawRecord($userId, $model, $conversation, $import, $report);
            }
        });
    }

    /**
     * Insert, update, or leave each message alone, and report which happened.
     *
     * @return array<int, string> Redaction categories seen across this conversation.
     */
    private function syncMessages(
        string $userId,
        Conversation $model,
        NormalizedConversation $conversation,
        ImportReport $report,
        ArchiveLimits $limits,
    ): array {
        $existing = ConversationMessage::where('conversation_id', $model->id)
            ->get()
            ->keyBy('provider_message_id');

        $categories = [];
        $seenIds = [];

        foreach ($conversation->messages as $message) {
            $report->messagesSeen++;

            if ($message->isEmpty()) {
                $report->messagesSkipped++;

                continue;
            }

            if (isset($seenIds[$message->providerMessageId])) {
                // Two records claiming the same provider identifier. Keeping the
                // first is the only choice that stays deterministic across runs.
                $report->messagesSkipped++;
                $report->warn('An archive contained duplicate message identifiers within one conversation; later duplicates were skipped.');

                continue;
            }

            $seenIds[$message->providerMessageId] = true;

            [$text, $truncated] = $this->truncate($message->text, $limits->maxMessageChars);

            if ($truncated) {
                $report->warn('One or more messages exceeded the configured length limit and were truncated in the normalized copy. The raw record still holds the full text.');
            }

            $redaction = $this->redactor->redact($text, $userId);

            if ($redaction->applied()) {
                $report->redactedMessages++;
                $categories = array_values(array_unique([...$categories, ...$redaction->categories()]));
            }

            $hash = $message->contentHash();
            $row = $existing->get($message->providerMessageId);

            $attributes = [
                'conversation_id' => $model->id,
                'user_id' => $userId,
                'provider' => $conversation->provider,
                'provider_message_id' => $message->providerMessageId,
                'parent_provider_message_id' => $message->parentProviderMessageId,
                'sequence' => $message->sequence,
                'on_active_path' => $message->onActivePath,
                'role' => $message->role,
                'author_name' => $message->authorName,
                'content_type' => $message->contentType,
                'content_text' => $redaction->text,
                'content_blocks' => $message->contentBlocks ?: null,
                'model_slug' => $message->modelSlug,
                'provider_created_at' => $message->createdAt,
                'char_count' => mb_strlen($redaction->text),
                'attachments' => $message->attachments === []
                    ? null
                    : array_map(static fn (NormalizedAttachment $a) => $a->toArray(), $message->attachments),
                'provider_metadata' => $this->messageMetadata($message, $truncated),
                'redaction' => $redaction->applied() ? $redaction->toMetadata() : null,
                'content_hash' => $hash,
            ];

            if ($row === null) {
                ConversationMessage::create($attributes);
                $report->messagesNew++;

                continue;
            }

            if ($row->content_hash === $hash) {
                // The hash covers the source message, not its redacted
                // projection, so that tightening a redaction policy does not
                // make every message look edited. Two things can still legitimately
                // differ on an unchanged message: its position, when branches move
                // around it, and its redacted text, when the policy changed since
                // the last import. Both are refreshed without calling it updated.
                $dirty = false;

                if ($row->sequence !== $message->sequence || $row->on_active_path !== $message->onActivePath) {
                    $row->sequence = $message->sequence;
                    $row->on_active_path = $message->onActivePath;
                    $dirty = true;
                }

                if ($row->content_text !== $redaction->text) {
                    $row->content_text = $redaction->text;
                    $row->char_count = mb_strlen($redaction->text);
                    $row->redaction = $redaction->applied() ? $redaction->toMetadata() : null;
                    $dirty = true;
                }

                if ($dirty) {
                    $row->save();
                }

                $report->messagesUnchanged++;

                continue;
            }

            $row->fill($attributes)->save();
            $report->messagesUpdated++;
        }

        $retained = $existing->keys()->reject(static fn ($id) => isset($seenIds[$id]))->count();

        if ($retained > 0) {
            $report->warn("{$retained} previously imported message(s) were absent from this archive and were kept rather than deleted.");
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function messageMetadata(NormalizedMessage $message, bool $truncated): ?array
    {
        $metadata = $message->providerMetadata;

        if ($truncated) {
            $metadata['truncated'] = true;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function truncate(string $text, int $maxChars): array
    {
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return [$text, false];
        }

        return [mb_substr($text, 0, $maxChars) . "\n\n[truncated during import]", true];
    }

    private function storeRawRecord(
        string $userId,
        Conversation $model,
        NormalizedConversation $conversation,
        ?ConversationImport $import,
        ImportReport $report,
    ): void {
        $payload = (string) $conversation->rawPayload;
        $sha = hash('sha256', $payload);

        $existing = ConversationRawRecord::query()
            ->where('user_id', $userId)
            ->where('provider', $conversation->provider)
            ->where('payload_sha256', $sha)
            ->first();

        if ($existing !== null) {
            // The same source bytes arriving again is the ordinary case for a
            // re-export. Attach it to the conversation if it was not already.
            if ($existing->conversation_id === null) {
                $existing->conversation_id = $model->id;
                $existing->save();
            }

            $report->rawRecordsAlreadyPresent++;

            return;
        }

        ConversationRawRecord::create([
            'import_id' => $import?->id,
            'user_id' => $userId,
            'provider' => $conversation->provider,
            'provider_conversation_id' => $conversation->providerConversationId,
            'conversation_id' => $model->id,
            'record_type' => 'conversation',
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
            'payload_sha256' => $sha,
        ]);

        $report->rawRecordsStored++;
    }

    private function createImportRow(string $userId, ArchiveSource $source, ConversationArchiveAdapter $adapter): ConversationImport
    {
        return ConversationImport::create([
            'user_id' => $userId,
            'provider' => $adapter->provider(),
            'adapter_version' => $adapter->parserVersion(),
            'source_label' => $source->label(),
            'source_path' => $source->path(),
            'source_bytes' => $source->totalBytes(),
            'source_sha256' => $source->sha256(),
            'status' => ConversationImport::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);
    }

    private function finishImportRow(ConversationImport $import, string $status, ImportReport $report, ?string $error): void
    {
        $import->status = $status;
        $import->finished_at = Carbon::now();
        $import->stats = $report->toArray();
        $import->warnings = $report->warnings() ?: null;
        $import->error = $error;
        $import->save();
    }
}
