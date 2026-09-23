<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\EvidenceRetrievalService;
use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\LLM\LlmService;
use App\Services\MemorabilityService;
use App\Services\MemoryGraphService;
use App\Services\MemorySummarizationService;
use App\Services\RedactionResult;
use App\Services\RedactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly IcpMemoryService $icp,
        private readonly MemorabilityService $memorability,
        private readonly MemorySummarizationService $summarizer,
        private readonly GraphExtractionService $graphExtractor,
        private readonly MemoryGraphService $graphService,
        private readonly RedactionService $redactor,
        private readonly EvidenceRetrievalService $evidenceRetrieval,
    ) {}

    /**
     * Show the chat UI.
     */
    public function index(): Response
    {
        $sessionId = session()->get('chat_session_id', (string) Str::uuid());
        session()->put('chat_session_id', $sessionId);

        $userId = auth('web')->user()->corpusOwnerKey();
        session()->put('chat_user_id', $userId);

        $messages = Message::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at'])
            ->toArray();

        return Inertia::render('Chat/Index', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'identity_source' => session()->get('identity_source', 'session'),
            'messages' => $messages,
            'llm_provider' => $this->llm->provider(),
            'icp_mode' => $this->icp->mode(),
            'canister_id' => $this->icp->canisterId(),
            'browser_host' => $this->icp->browserHost(),
            'ii_provider_url' => $this->icp->iiProviderUrl(),
        ]);
    }

    /**
     * Handle a new chat message.
     *
     * Laravel ownership comes only from the authenticated OpenMemory account.
     * A browser principal is an external identifier, not authentication proof.
     *
     * Memory write flow (live ICP mode):
     *   - Laravel returns the memory_summary to the browser.
     *   - The browser calls the canister directly using the II delegation.
     *   - msg.caller on the canister == the user's II principal (cryptographically verified).
     *   - The canister rejects anonymous callers, so a signed-out browser cannot
     *     write at all and the server cannot write under any user's principal.
     *
     * Memory write flow (mock mode):
     *   - Laravel writes server-side to the file cache (no canister available).
     *   - The authenticated account owns server-side mock records.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'principal' => 'nullable|string|max:128|regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/',
        ]);

        \App\Services\LLM\ModelDisclosure::authorize('chat');
        $sessionId = session()->get('chat_session_id');
        if (! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        // External principal strings never select a local ownership namespace.
        $userId = $request->user('web')->corpusOwnerKey();
        $identitySource = 'openmemory';

        // Redact before the message is persisted or sent to any LLM call. This
        // keeps hard-floor secrets out of the transcript, prompt history, memory
        // summarizer, graph extractor, and downstream storage paths.
        $userRedaction = $this->redactor->redact($validated['message'], $userId);
        $safeUserMessage = $userRedaction->text;

        // Persist user message
        Message::create([
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => $safeUserMessage,
        ]);

        // Graph-guided retrieval: use the Physarum neighbourhood seeded from the
        // highest-weight nodes rather than loading the entire flat public set.
        // Only the retrieved neighbourhood is reinforced, so edge weights reflect
        // genuine relevance rather than uniform co-occurrence across all public memories.
        //
        // Cold start (no graph nodes yet): fall back to flat ICP recall so the first
        // few turns still inject memory context while the graph is being built.
        //
        // The strategy comes from RETRIEVAL_STRATEGY. The redacted user message is
        // passed as the query so the query-aware strategies can seed from it; the
        // raw message never reaches seed selection. An unrecognized configured
        // strategy falls back to the goal_graph default with a structured warning
        // instead of failing the turn silently.
        $strategy = (string) config('services.retrieval.strategy', 'goal_graph');
        if (! in_array($strategy, MemoryGraphService::STRATEGIES, true)) {
            Log::warning('Invalid RETRIEVAL_STRATEGY configured; falling back to default.', [
                'key' => 'RETRIEVAL_STRATEGY',
                'fallback' => 'goal_graph',
            ]);
            $strategy = 'goal_graph';
        }

        $graphContext = $this->graphService->retrieveContext($userId, 12, $strategy, $safeUserMessage);
        $groundedMode = (bool) config('services.grounded.enabled', false);
        $groundedEvidence = [];

        if (! empty($graphContext)) {
            $loadedNodeIds = array_column($graphContext, 'id');
            $this->graphService->reinforce($loadedNodeIds, $userId);

            if ($groundedMode) {
                $groundedEvidence = $this->evidenceRetrieval->retrieve(
                    userId: $userId,
                    query: $safeUserMessage,
                    sourceNodeIds: $loadedNodeIds,
                    limit: max(1, (int) config('services.grounded.evidence_limit', 8)),
                );
                $systemPrompt = $this->llm->buildGroundedSystemPrompt($groundedEvidence);
            } else {
                $graphContext = $this->redactMemoryRecords($graphContext, $userId);
                $systemPrompt = $this->llm->buildSystemPrompt($graphContext);
            }
        } else {
            // Cold start: graph is empty; fall back to flat ICP recall.
            $memories = $this->redactMemoryRecords($this->icp->getPublicMemories($userId), $userId);
            $loadedNodeIds = $this->graphService->reinforceFromMemories($memories, $userId);
            $systemPrompt = $groundedMode
                ? $this->llm->buildGroundedSystemPrompt([])
                : $this->llm->buildSystemPrompt($memories);
        }

        // Get recent conversation history for context
        $history = Message::where('session_id', $sessionId)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'role' => $m->role,
                'content' => $this->redactor->redact($m->content, $userId)->text,
            ])
            ->toArray();

        if ($groundedMode) {
            $history = [[
                'role' => 'user',
                'content' => $safeUserMessage,
            ]];
        }

        // Generate AI response
        $selectedEvidence = array_map(static fn (array $record) => array_intersect_key($record, array_flip([
            'id', 'content', 'timestamp', 'fact_id', 'fact_text', 'source_label', 'span_start', 'span_end',
        ])), $groundedMode ? $groundedEvidence : ($graphContext ?: array_slice($memories ?? [], 0, 12)));
        $history = \App\Services\LLM\EvidenceMessages::attach($history, $selectedEvidence);
        $aiResponse = $this->llm->chat($systemPrompt, $history, 'chat');
        $assistantRedaction = $this->redactor->redact($aiResponse, $userId);
        $safeAiResponse = $assistantRedaction->text;

        // Persist assistant message
        Message::create([
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $safeAiResponse,
        ]);

        // Storage trigger: evaluate whether this turn contains a fact worth storing.
        // MemorabilityService filters out small talk, repetition, and transient data
        // before the summarization LLM call, preventing low-value node accumulation.
        $memorability = $this->memorability->evaluate($safeUserMessage, $safeAiResponse, $userId);

        // Summarize the exchange into a durable fact with a sensitivity classification.
        // Returns ['content' => '...', 'type' => 'public'|'private'|'sensitive'] or null.
        // Skipped entirely when the memorability filter returns 'skip'.
        $memory = $memorability['decision'] !== 'skip'
            ? $this->summarizer->extract($safeUserMessage, $safeAiResponse)
            : null;
        $memoryId = null;
        $memoryRedaction = null;

        if ($memory) {
            $memoryRedaction = $this->redactor->redact($memory['content'], $userId);
            $memory['content'] = $memoryRedaction->text;
            $memory['type'] = $this->redactor->enforceSensitivity(
                $memory['type'] ?? 'public',
                $userRedaction,
                $assistantRedaction,
                $memoryRedaction,
            );

            $metadata = $this->memoryMetadata($userRedaction, $assistantRedaction, $memoryRedaction);

            // Classification is not consent to publish. Every proposed memory
            // waits for the existing owner approval flow in both storage modes.
        }

        $metadata ??= $this->memoryMetadata($userRedaction, $assistantRedaction, $memoryRedaction);

        return response()->json([
            'message' => $safeAiResponse,
            'memory_id' => $memoryId,
            'memory_requires_approval' => $memory !== null,
            'memory' => $memory['content'] ?? null,
            'memory_type' => $memory['type'] ?? null,
            'memory_metadata' => $metadata,
            'redacted_message' => $safeUserMessage,
            'redaction' => $this->combinedRedaction($userRedaction, $assistantRedaction, $memoryRedaction) ?? ['applied' => false],
            'identity_source' => $identitySource,
            'user_id' => $userId,
            'provider' => $this->llm->provider(),
            'icp_mode' => $this->icp->mode(),
            // IDs of graph nodes loaded into the LLM context this turn.
            // The Three.js visualization uses these to highlight active nodes
            // and the graph API uses them to show which memories were retrieved.
            'active_node_ids' => $loadedNodeIds,
            'grounded_retrieval' => $groundedMode,
            'evidence_fact_ids' => array_column($groundedEvidence, 'fact_id'),
        ]);
    }

    /**
     * Store a browser-approved memory in mock mode.
     *
     * In live ICP mode the browser writes directly to the canister (browser-signed).
     * In mock mode there is no canister, so the browser POSTs here after the user
     * clicks "Sign & store" in the approval UI. This keeps the consent flow identical
     * between mock and live mode — the server never writes a proposed memory without approval.
     */
    public function storeMemory(Request $request)
    {
        if (! $this->icp->isMockMode()) {
            return response()->json(['error' => 'Only used in mock mode. In live mode the browser writes directly to the canister.'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
            'memory_type' => 'required|in:public,private,sensitive',
            'metadata' => 'nullable|string|max:1000',
        ]);

        $userId = session()->get('chat_user_id');
        $sessionId = session()->get('chat_session_id');

        if (! $userId || ! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        $contentRedaction = $this->redactor->redact($validated['content'], $userId);
        $content = $contentRedaction->text;
        $memoryType = $this->redactor->enforceSensitivity($validated['memory_type'], $contentRedaction);
        $metadata = $this->appendRedactionMetadata($validated['metadata'] ?? null, $contentRedaction);

        $id = $this->icp->mockStoreApproved(
            userId: $userId,
            sessionId: $sessionId,
            content: $content,
            metadata: $metadata,
            memoryType: $memoryType,
        );
        $this->syncMemoryGraph(
            userId: $userId,
            content: $content,
            memoryType: $memoryType,
            sessionId: $sessionId,
            metadata: $contentRedaction->applied() ? ['redaction' => $contentRedaction->toMetadata()] : [],
        );

        return response()->json([
            'id' => $id,
            'memory_type' => $memoryType,
            'redaction' => $contentRedaction->applied() ? $contentRedaction->toMetadata() : ['applied' => false],
        ]);
    }

    /**
     * Sync a browser-written memory into the local graph after the canister write succeeds.
     */
    public function syncGraphMemory(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:2000',
            'memory_type' => 'required|in:public,private,sensitive',
        ]);

        $userId = session()->get('chat_user_id');
        $sessionId = session()->get('chat_session_id');

        if (! $userId || ! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        $contentRedaction = $this->redactor->redact($validated['content'], $userId);
        $memoryType = $this->redactor->enforceSensitivity($validated['memory_type'], $contentRedaction);

        $this->syncMemoryGraph($userId, $contentRedaction->text, $memoryType, $sessionId);

        return response()->json([
            'ok' => true,
            'memory_type' => $memoryType,
            'redaction' => $contentRedaction->applied() ? $contentRedaction->toMetadata() : ['applied' => false],
        ]);
    }

    /**
     * Reset the current chat session (transcript only).
     * User identity is preserved so memory recall still works after reset.
     */
    public function reset(Request $request)
    {
        $sessionId = session()->get('chat_session_id');

        if ($sessionId) {
            Message::where('session_id', $sessionId)->delete();
        }

        // Only forget the session transcript ID — NOT the user identity.
        // Forgetting user_id would break the core memory-recall demo.
        session()->forget('chat_session_id');

        return redirect()->route('chat');
    }

    /**
     * External sign-out does not change the authenticated OpenMemory account.
     */
    public function identityLogout(Request $request)
    {
        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function syncMemoryGraph(
        string $userId,
        string $content,
        string $memoryType,
        ?string $sessionId = null,
        array $metadata = [],
    ): void {
        $contentRedaction = $this->redactor->redact($content, $userId);
        $content = $contentRedaction->text;
        $memoryType = $this->redactor->enforceSensitivity($memoryType, $contentRedaction);

        if ($redaction = $this->combinedRedaction($contentRedaction)) {
            $metadata['redaction'] = $redaction;
        }

        $extracted = $this->graphExtractor->extract($content, $memoryType);
        if ($extracted) {
            $this->graphService->storeNode(
                userId: $userId,
                content: $content,
                extracted: $this->sanitizeExtractedMetadata($extracted, $userId),
                sessionId: $sessionId,
                source: 'chat',
                metadata: $metadata,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function redactMemoryRecords(array $records, string $userId): array
    {
        return array_map(function (array $record) use ($userId) {
            if (isset($record['content']) && is_string($record['content'])) {
                $record['content'] = $this->redactor->redact($record['content'], $userId)->text;
            }

            return $record;
        }, $records);
    }

    private function memoryMetadata(?RedactionResult ...$results): string
    {
        $payload = ['source' => 'chat', 'provider' => $this->llm->provider()];

        if ($redaction = $this->combinedRedaction(...$results)) {
            $payload['redaction'] = $redaction;
        }

        return json_encode($payload);
    }

    private function appendRedactionMetadata(?string $metadata, RedactionResult ...$results): ?string
    {
        $payload = [];

        if ($metadata) {
            $decoded = json_decode($metadata, true);
            $payload = is_array($decoded) ? $decoded : ['metadata' => $metadata];
        }

        if ($redaction = $this->combinedRedaction(...$results)) {
            $payload['redaction'] = $redaction;
        }

        return $payload === [] ? null : json_encode($payload);
    }

    /**
     * @return array{applied: bool, policy: string, categories: array<int, string>, counts: array<string, int>, minimum_sensitivity: string}|null
     */
    private function combinedRedaction(?RedactionResult ...$results): ?array
    {
        $results = array_values(array_filter($results));

        return $results === [] ? null : $this->redactor->metadata(...$results);
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function sanitizeExtractedMetadata(array $extracted, string $userId): array
    {
        if (isset($extracted['label']) && is_string($extracted['label'])) {
            $extracted['label'] = $this->redactor->redact($extracted['label'], $userId)->text;
        }

        foreach (['tags', 'people', 'projects'] as $field) {
            $values = $extracted[$field] ?? [];
            if (! is_array($values)) {
                $extracted[$field] = [];

                continue;
            }

            $redactedValues = array_map(
                fn ($value) => is_string($value) ? trim($this->redactor->redact($value, $userId)->text) : '',
                $values,
            );

            $extracted[$field] = array_values(array_unique(array_filter(
                $redactedValues,
                static fn (string $value) => $value !== '' && ! str_contains($value, '['),
            )));
        }

        return $extracted;
    }
}
