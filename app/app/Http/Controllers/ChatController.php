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

        // identity_source tracks where the user_id came from.
        // 'browser' = browser principal from Internet Identity (set on first /chat/send with a principal).
        // 'session' = server-generated fallback used before the user has signed in.
        $userId = session()->get('chat_user_id', 'session_'.Str::random(8));
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
     * Identity flow:
     *   - The browser obtains its principal from Internet Identity (AuthClient
     *     delegation) and sends it as `principal`. Signed-out browsers omit it.
     *   - On first message, we store that principal as the user_id and mark the
     *     identity_source as 'browser'. Subsequent messages verify it matches.
     *   - If no principal is supplied (signed out, or a direct API call), the
     *     session-generated fallback is used and identity_source remains 'session'.
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
     *   - The principal still comes from the browser (II or empty); it just isn't cryptographically enforced.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'principal' => 'nullable|string|max:128|regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/',
        ]);

        $sessionId = session()->get('chat_session_id');
        if (! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        // Accept the Internet Identity principal sent by the browser on first
        // message; lock it in after that so later turns cannot silently re-bind.
        $userId = session()->get('chat_user_id');
        $identitySource = session()->get('identity_source', 'session');
        $incomingPrincipal = $validated['principal'] ?? null;

        if ($incomingPrincipal && $identitySource === 'session') {
            // First browser-principal message — adopt it and upgrade identity source.
            $userId = $incomingPrincipal;
            session()->put('chat_user_id', $userId);
            session()->put('identity_source', 'browser');
            $identitySource = 'browser';
        }

        if (! $userId) {
            return response()->json(['error' => 'No user identity. Please refresh.'], 422);
        }

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
        $graphContext = $this->graphService->retrieveContext($userId);
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
            ->orderBy('created_at')
            ->get(['role', 'content'])
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
        $aiResponse = $this->llm->chat($systemPrompt, $history);
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
            $graphMetadata = ($redactionMetadata = $this->combinedRedaction($userRedaction, $assistantRedaction, $memoryRedaction))
                ? ['redaction' => $redactionMetadata]
                : [];

            if ($this->icp->isMockMode() && ($memory['type'] ?? 'public') === 'public') {
                // Mock mode, public only: safe to write server-side without consent.
                $memoryId = $this->icp->storeMemory(
                    userId: $userId,
                    sessionId: $sessionId,
                    content: $memory['content'],
                    metadata: $metadata,
                    memoryType: 'public',
                );
                $this->syncMemoryGraph(
                    userId: $userId,
                    content: $memory['content'],
                    memoryType: 'public',
                    sessionId: $sessionId,
                    metadata: $graphMetadata,
                );
            }
            // Private / Sensitive (both modes) and all types in live ICP mode:
            //   The browser shows an approval UI and POSTs to /chat/store-memory (mock)
            //   or signs directly to the canister (live). The graph sync runs after that store succeeds.
        }

        $metadata ??= $this->memoryMetadata($userRedaction, $assistantRedaction, $memoryRedaction);

        return response()->json([
            'message' => $safeAiResponse,
            'memory_id' => $memoryId,
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
     * Store a browser-approved Private or Sensitive memory in mock mode.
     *
     * In live ICP mode the browser writes directly to the canister (browser-signed).
     * In mock mode there is no canister, so the browser POSTs here after the user
     * clicks "Sign & store" in the approval UI. This keeps the consent flow identical
     * between mock and live mode — the server never writes Private/Sensitive without approval.
     */
    public function storeMemory(Request $request)
    {
        if (! $this->icp->isMockMode()) {
            return response()->json(['error' => 'Only used in mock mode. In live mode the browser writes directly to the canister.'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
            'memory_type' => 'required|in:private,sensitive',
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
     * Forget the browser identity bound to this Laravel session.
     *
     * Called by the chat UI after the user signs out of Internet Identity.
     * Without this, the session keeps the previous chat_user_id and subsequent
     * /chat/send calls — even with `principal: null` in the body — continue to
     * retrieve and reinforce the prior principal's memory graph. The chat
     * transcript is also reset because messages addressed to the old principal
     * should not bleed into a new session.
     */
    public function identityLogout(Request $request)
    {
        $sessionId = session()->get('chat_session_id');
        if ($sessionId) {
            Message::where('session_id', $sessionId)->delete();
        }

        session()->forget('chat_user_id');
        session()->forget('identity_source');
        session()->forget('chat_session_id');

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
