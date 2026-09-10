<?php

namespace App\Services\Conversations\Adapters;

use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\Json\JsonArrayStreamReader;
use App\Services\Conversations\NormalizedAttachment;
use App\Services\Conversations\NormalizedConversation;
use App\Services\Conversations\NormalizedMessage;
use Generator;

/**
 * Parses an Anthropic Claude data export.
 *
 * How to obtain one, as of September 2026: in Claude on the web or in Claude
 * Desktop, open Settings, then Privacy, then Export data. Anthropic emails a
 * link that expires after 24 hours. Exports are available on the Free, Pro, and
 * Max plans; Team and Enterprise exports are run by an organization owner.
 * Mobile apps cannot start an export.
 *
 * Anthropic documents how to request the export and states that it contains
 * conversation data and account data. It does not publish the JSON schema, so
 * this parser is written against observed archives and is defensive throughout.
 *
 * The shape is flatter than ChatGPT's. A conversation is an object with uuid,
 * name, created_at, updated_at, and a chat_messages array. Each message carries
 * uuid, sender ("human" or "assistant"), created_at, an optional legacy text
 * field, and a content array of typed blocks: text, thinking, tool_use, and
 * tool_result. Branching, where present, is expressed by parent_message_uuid
 * rather than by a mapping tree.
 *
 * Two decisions worth naming. First, thinking blocks are recorded structurally
 * but their text is not folded into the message body: extended reasoning is not
 * something the person said or was shown as the answer, and mixing it into the
 * transcript would distort any later analysis of how someone actually writes.
 * Second, tool_use and tool_result blocks are summarized rather than inlined,
 * because tool payloads are frequently large, frequently machine-generated, and
 * frequently contain content the user never read.
 */
final class ClaudeArchiveAdapter extends AbstractArchiveAdapter
{
    public const PROVIDER = 'claude';

    private const PARSER_VERSION = 'claude-1';

    private const CONVERSATIONS_ENTRY = 'conversations.json';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function displayName(): string
    {
        return 'Claude';
    }

    public function parserVersion(): string
    {
        return self::PARSER_VERSION;
    }

    public function detect(ArchiveSource $source): AdapterDetection
    {
        $entry = $this->findEntry($source, self::CONVERSATIONS_ENTRY);

        if ($entry === null) {
            return AdapterDetection::noMatch();
        }

        $probe = $this->probe($source, $entry);

        if ($probe === null) {
            return AdapterDetection::noMatch();
        }

        // chat_messages is the discriminator against the ChatGPT file of the
        // same name, which uses a mapping tree instead.
        if (! array_key_exists('chat_messages', $probe) || ! is_array($probe['chat_messages'])) {
            return AdapterDetection::noMatch();
        }

        return AdapterDetection::supported($entry, 95);
    }

    public function conversations(ArchiveSource $source, AdapterDetection $detection): Generator
    {
        $entry = $detection->entry ?? $this->findEntry($source, self::CONVERSATIONS_ENTRY);

        if ($entry === null) {
            return;
        }

        $stream = $source->stream($entry);

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $index => $raw) {
                $decoded = json_decode($raw, true);

                if (! is_array($decoded)) {
                    $this->warn("Skipped conversation at position {$index}: the record is not valid JSON.");

                    continue;
                }

                yield $this->normalizeConversation($decoded, $raw, $index);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function normalizeConversation(array $record, string $raw, int $index): NormalizedConversation
    {
        $warnings = [];
        $conversationId = $this->conversationId($record, $raw);
        $rawMessages = is_array($record['chat_messages'] ?? null) ? $record['chat_messages'] : [];

        if ($rawMessages === []) {
            $warnings[] = 'Conversation contains no readable messages.';
        }

        $messages = [];
        $sequence = 0;

        foreach ($rawMessages as $position => $rawMessage) {
            if (! is_array($rawMessage)) {
                $warnings[] = "Message at position {$position} was not an object and was skipped.";

                continue;
            }

            $message = $this->normalizeMessage($rawMessage, $conversationId, $position, $sequence, $warnings);

            if ($message === null) {
                continue;
            }

            $messages[] = $message;
            $sequence++;
        }

        return new NormalizedConversation(
            provider: self::PROVIDER,
            providerConversationId: $conversationId,
            messages: $messages,
            title: $this->stringOrNull($record['name'] ?? $record['title'] ?? null),
            createdAt: $this->parseIso($record['created_at'] ?? null),
            updatedAt: $this->parseIso($record['updated_at'] ?? null),
            sourceAccountLabel: $this->accountLabel($record),
            workspaceLabel: $this->workspaceLabel($record),
            providerMetadata: $this->pickMetadata($record, [
                'summary',
                'model',
                'settings',
                'is_starred',
                'current_leaf_message_uuid',
                'project_uuid',
            ]),
            rawPayload: $raw,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $warnings
     */
    private function normalizeMessage(array $record, string $conversationId, int|string $position, int $sequence, array &$warnings): ?NormalizedMessage
    {
        $role = NormalizedMessage::normalizeRole(
            is_string($record['sender'] ?? null) ? $record['sender'] : ($record['role'] ?? null),
        );

        if ($role === NormalizedMessage::ROLE_UNKNOWN) {
            $warnings[] = "Message at position {$position} had an unrecognized sender and was imported as role \"unknown\".";
        }

        [$text, $blocks] = $this->readContent($record);
        $attachments = $this->readAttachments($record);

        if ($text === '' && $attachments === []) {
            return null;
        }

        $providerMessageId = $this->stringOrNull($record['uuid'] ?? $record['id'] ?? null, 255)
            ?? $this->synthesizeId('om', self::PROVIDER, $conversationId, (string) $position, $role, $text);

        return new NormalizedMessage(
            providerMessageId: $providerMessageId,
            role: $role,
            text: $text,
            parentProviderMessageId: $this->stringOrNull($record['parent_message_uuid'] ?? null, 255),
            sequence: $sequence,
            onActivePath: true,
            contentType: $blocks === [] ? null : (string) ($blocks[0]['type'] ?? 'text'),
            createdAt: $this->parseIso($record['created_at'] ?? null),
            contentBlocks: $blocks,
            attachments: $attachments,
            providerMetadata: $this->pickMetadata($record, ['stop_reason', 'truncated', 'index']),
        );
    }

    /**
     * Read the message body from typed content blocks, falling back to text.
     *
     * @param  array<string, mixed>  $record
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function readContent(array $record): array
    {
        $content = $record['content'] ?? null;
        $blocks = [];
        $pieces = [];

        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_string($block)) {
                    $clean = $this->cleanText($block);

                    if ($clean !== '') {
                        $pieces[] = $clean;
                        $blocks[] = ['type' => 'text', 'chars' => mb_strlen($clean)];
                    }

                    continue;
                }

                if (! is_array($block)) {
                    continue;
                }

                $type = is_string($block['type'] ?? null) ? $block['type'] : 'unknown';

                if ($type === 'text' && is_string($block['text'] ?? null)) {
                    $clean = $this->cleanText($block['text']);

                    if ($clean !== '') {
                        $pieces[] = $clean;
                        $blocks[] = ['type' => 'text', 'chars' => mb_strlen($clean)];
                    }

                    continue;
                }

                // Reasoning and tool traffic are recorded, not inlined.
                $blocks[] = [
                    'type' => $type,
                    'chars' => $this->blockLength($block),
                    'name' => is_string($block['name'] ?? null) ? mb_substr($block['name'], 0, 120) : null,
                ];
            }
        }

        if ($pieces === []) {
            $legacy = $record['text'] ?? null;

            if (is_string($legacy)) {
                $clean = $this->cleanText($legacy);

                if ($clean !== '') {
                    $pieces[] = $clean;

                    if ($blocks === []) {
                        $blocks[] = ['type' => 'text', 'chars' => mb_strlen($clean), 'source' => 'legacy_text'];
                    }
                }
            }
        }

        return [implode("\n\n", $pieces), $blocks];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockLength(array $block): int
    {
        foreach (['text', 'thinking', 'content'] as $key) {
            if (is_string($block[$key] ?? null)) {
                return mb_strlen($block[$key]);
            }
        }

        $encoded = json_encode($block);

        return is_string($encoded) ? strlen($encoded) : 0;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, NormalizedAttachment>
     */
    private function readAttachments(array $record): array
    {
        $attachments = [];

        foreach (['attachments', 'files'] as $key) {
            $items = $record[$key] ?? null;

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $attachments[] = new NormalizedAttachment(
                    id: $this->stringOrNull($item['id'] ?? $item['file_uuid'] ?? null, 128),
                    name: $this->stringOrNull($item['file_name'] ?? $item['name'] ?? null, 255),
                    mediaType: $this->stringOrNull($item['file_type'] ?? $item['mime_type'] ?? null, 128),
                    sizeBytes: is_int($item['file_size'] ?? null) ? $item['file_size'] : null,
                );
            }
        }

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function accountLabel(array $record): ?string
    {
        $account = $record['account'] ?? null;

        if (is_array($account)) {
            return $this->stringOrNull($account['uuid'] ?? $account['name'] ?? null, 255);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function workspaceLabel(array $record): ?string
    {
        $project = $record['project'] ?? null;

        if (is_array($project)) {
            return $this->stringOrNull($project['name'] ?? $project['uuid'] ?? null, 255);
        }

        return $this->stringOrNull($record['project_uuid'] ?? null, 255);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function conversationId(array $record, string $raw): string
    {
        foreach (['uuid', 'id'] as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        $this->warn('A conversation had no provider identifier; a deterministic one was derived from its content.');

        return $this->synthesizeId('om', self::PROVIDER, $raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function probe(ArchiveSource $source, string $entry): ?array
    {
        $stream = $source->stream($entry);

        try {
            $reader = new JsonArrayStreamReader($stream);

            foreach ($reader->elements() as $raw) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : null;
            }
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return null;
    }
}
