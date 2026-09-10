<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationImport;
use App\Models\ConversationMessage;
use App\Models\ConversationRawRecord;
use App\Services\Conversations\ConversationAskService;
use App\Services\Conversations\ConversationEvidenceRetrievalService;
use App\Services\Conversations\CorpusOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review and interrogation surface for imported AI history.
 *
 * Every route here is owner-scoped through ownerId(). Imported history is the
 * most sensitive data in the application, and the corpus is single-owner by
 * construction: there is no route that returns another identity's conversations,
 * and no filter parameter that can widen the scope.
 *
 * The raw endpoint is separate from everything else on purpose. Normalized
 * message text is redacted and is what the list, detail, search, and Ask paths
 * read. The unredacted source is reachable only by explicitly asking for one
 * conversation's raw record, which is a deliberate act by the owner rather than
 * something that happens as a side effect of browsing.
 */
class ConversationHistoryController extends Controller
{
    public function __construct(
        private readonly CorpusOverviewService $overview,
        private readonly ConversationEvidenceRetrievalService $retrieval,
        private readonly ConversationAskService $ask,
    ) {}

    public function index(Request $request): Response
    {
        $userId = $this->ownerId($request);

        return Inertia::render('History/Index', [
            'user_id' => $userId,
            'overview' => $this->overview->overview($userId),
            'suggested_subjects' => $this->overview->frequentTitleTerms($userId, 16),
            'imports' => $this->importRows($userId),
            'conversations' => $this->conversationPage($request, $userId),
            'filters' => $this->filters($request),
            'answer_generation_enabled' => (bool) config('conversations.ask.generate_answer', true),
        ]);
    }

    public function show(Request $request, string $conversationId): Response
    {
        $userId = $this->ownerId($request);

        $conversation = Conversation::query()
            ->where('user_id', $userId)
            ->whereKey($conversationId)
            ->firstOrFail();

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (ConversationMessage $message) => $this->messagePayload($message))
            ->values();

        return Inertia::render('History/Show', [
            'user_id' => $userId,
            'conversation' => $this->conversationPayload($conversation),
            'messages' => $messages,
            'has_raw_record' => ConversationRawRecord::where('conversation_id', $conversation->id)->exists(),
            'import' => $conversation->last_import_id
                ? ConversationImport::query()->whereKey($conversation->last_import_id)->first(['id', 'source_label', 'source_sha256', 'adapter_version', 'finished_at'])
                : null,
        ]);
    }

    /**
     * Paginated conversation list as JSON, for filter changes without a reload.
     */
    public function list(Request $request): JsonResponse
    {
        $userId = $this->ownerId($request);

        return response()->json([
            'conversations' => $this->conversationPage($request, $userId),
            'filters' => $this->filters($request),
        ]);
    }

    /**
     * Message-level search across the corpus, returning resolvable evidence.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'providers' => ['nullable', 'array'],
            'providers.*' => ['string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json($this->retrieval->retrieve(
            $this->ownerId($request),
            $validated['query'],
            (int) ($validated['limit'] ?? 20),
            [
                'providers' => $validated['providers'] ?? [],
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
        ));
    }

    /**
     * Ask a question of the corpus and receive a cited answer plus its evidence.
     */
    public function askQuestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'providers' => ['nullable', 'array'],
            'providers.*' => ['string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'generate' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->ask->ask(
            $this->ownerId($request),
            $validated['question'],
            [
                'providers' => $validated['providers'] ?? [],
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'generate' => $validated['generate'] ?? null,
            ],
        ));
    }

    /**
     * Deterministic timeline for one subject, with the conversations behind it.
     */
    public function timeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:2', 'max:200'],
            'providers' => ['nullable', 'array'],
            'providers.*' => ['string', 'max:32'],
        ]);

        return response()->json($this->overview->themeTimeline(
            $this->ownerId($request),
            $validated['subject'],
            ['providers' => $validated['providers'] ?? []],
        ));
    }

    /**
     * Return the preserved provider JSON for one conversation.
     *
     * This is the only path that serves unredacted imported content, and it
     * serves exactly one conversation at a time to the owner who imported it.
     */
    public function raw(Request $request, string $conversationId): JsonResponse
    {
        $userId = $this->ownerId($request);

        $conversation = Conversation::query()
            ->where('user_id', $userId)
            ->whereKey($conversationId)
            ->firstOrFail();

        $record = ConversationRawRecord::query()
            ->where('user_id', $userId)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($record === null) {
            return response()->json([
                'error' => 'No raw record was stored for this conversation.',
            ], 404);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'provider' => $record->provider,
            'payload_sha256' => $record->payload_sha256,
            'payload_bytes' => $record->payload_bytes,
            'payload' => $record->payload,
        ]);
    }

    /**
     * Delete one conversation, its messages, and its preserved source.
     *
     * Deletion has to be real for the ownership claim to mean anything. The
     * message and raw rows cascade from the conversation row, so nothing is left
     * behind holding the text.
     */
    public function destroy(Request $request, string $conversationId): JsonResponse
    {
        $userId = $this->ownerId($request);

        $conversation = Conversation::query()
            ->where('user_id', $userId)
            ->whereKey($conversationId)
            ->firstOrFail();

        ConversationRawRecord::where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return response()->json(['deleted' => true, 'conversation_id' => $conversationId]);
    }

    /**
     * Resolve the owner of imported history.
     *
     * A configured local identity wins, because that is what makes a CLI import
     * and a browser session look at the same corpus. Without one, the session
     * identity is used, which keeps the surface working out of the box while
     * making it obvious why an import run from a terminal is not visible.
     */
    private function ownerId(Request $request): string
    {
        $configured = config('conversations.local_user_id');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $sessionUser = $request->session()->get('chat_user_id');

        if (is_string($sessionUser) && $sessionUser !== '') {
            return $sessionUser;
        }

        $generated = 'session_' . Str::random(8);
        $request->session()->put('chat_user_id', $generated);

        return $generated;
    }

    /**
     * @return array{provider: string|null, search: string|null, from: string|null, to: string|null, page: int}
     */
    private function filters(Request $request): array
    {
        return [
            'provider' => $request->string('provider')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'page' => max(1, (int) $request->integer('page', 1)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPage(Request $request, string $userId): array
    {
        $filters = $this->filters($request);
        $perPage = 25;

        $query = Conversation::query()->where('user_id', $userId);

        if ($filters['provider'] !== null) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['search'] !== null) {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['search']);
            $query->where('title', 'like', "%{$needle}%");
        }

        if ($filters['from'] !== null) {
            $query->where('first_message_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== null) {
            $query->where('first_message_at', '<=', $filters['to']);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByRaw('CASE WHEN first_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('first_message_at')
            ->orderBy('id')
            ->forPage($filters['page'], $perPage)
            ->get();

        return [
            'total' => $total,
            'page' => $filters['page'],
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'data' => $rows->map(fn (Conversation $row) => $this->conversationPayload($row))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'provider' => $conversation->provider,
            'provider_conversation_id' => $conversation->provider_conversation_id,
            'title' => $conversation->title,
            'message_count' => $conversation->message_count,
            'first_message_at' => $conversation->first_message_at?->toIso8601String(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'provider_created_at' => $conversation->provider_created_at?->toIso8601String(),
            'models' => $conversation->models ?? [],
            'workspace_label' => $conversation->workspace_label,
            'visibility' => $conversation->visibility,
            'parser_version' => $conversation->parser_version,
            'grain' => $conversation->provider_metadata['grain'] ?? 'conversation',
            'redaction' => $conversation->redaction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(ConversationMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'author_name' => $message->author_name,
            'content_text' => $message->content_text,
            'content_type' => $message->content_type,
            'content_blocks' => $message->content_blocks ?? [],
            'model_slug' => $message->model_slug,
            'occurred_at' => $message->provider_created_at?->toIso8601String(),
            'sequence' => $message->sequence,
            'on_active_path' => (bool) $message->on_active_path,
            'attachments' => $message->attachments ?? [],
            'redaction' => $message->redaction,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importRows(string $userId): array
    {
        return ConversationImport::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (ConversationImport $import) => [
                'id' => $import->id,
                'provider' => $import->provider,
                'source_label' => $import->source_label,
                'source_sha256' => $import->source_sha256,
                'adapter_version' => $import->adapter_version,
                'status' => $import->status,
                'finished_at' => $import->finished_at?->toIso8601String(),
                'stats' => $import->stats,
                'warnings' => $import->warnings ?? [],
                'error' => $import->error,
            ])
            ->values()
            ->all();
    }
}
