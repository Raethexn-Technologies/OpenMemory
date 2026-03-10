<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\IcpMemoryService;
use App\Services\LLM\LlmService;
use App\Services\MemorySummarizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly IcpMemoryService $icp,
        private readonly MemorySummarizationService $summarizer,
    ) {}

    /**
     * Show the chat UI.
     */
    public function index(): Response
    {
        $sessionId = session()->get('chat_session_id', (string) Str::uuid());
        session()->put('chat_session_id', $sessionId);

        // identity_source tracks where the user_id came from.
        // 'browser' = browser-derived Ed25519 principal (set on first /chat/send with a principal).
        // 'session' = legacy server-generated fallback (first page load before any message).
        $userId = session()->get('chat_user_id', 'session_' . Str::random(8));
        session()->put('chat_user_id', $userId);

        $messages = Message::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at'])
            ->toArray();

        return Inertia::render('Chat/Index', [
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'identity_source' => session()->get('identity_source', 'session'),
            'messages'        => $messages,
            'llm_provider'    => $this->llm->provider(),
            'icp_mode'        => $this->icp->mode(),
        ]);
    }

    /**
     * Handle a new chat message.
     *
     * Identity flow:
     *   - The browser generates an Ed25519 principal and sends it as `principal`.
     *   - On first message, we store that principal as the user_id and mark the
     *     identity_source as 'browser'. Subsequent messages verify it matches.
     *   - If no principal is supplied (e.g. direct API call), the session-generated
     *     fallback is used and identity_source remains 'session'.
     *
     * Memory write flow (live ICP mode):
     *   - Laravel returns the memory_summary to the browser.
     *   - The browser calls the canister directly with the user's Ed25519 identity.
     *   - msg.caller on the canister == the user's principal (cryptographically verified).
     *   - The server cannot write under the user's principal in live mode.
     *
     * Memory write flow (mock mode):
     *   - Laravel writes server-side to the file cache (no canister available).
     *   - The principal is still browser-derived; it just isn't cryptographically enforced.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'message'   => 'required|string|max:2000',
            'principal' => 'nullable|string|max:128|regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/',
        ]);

        $sessionId = session()->get('chat_session_id');
        if (! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        // Accept browser-derived principal on first message; lock it in after that.
        $userId         = session()->get('chat_user_id');
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

        // Persist user message
        Message::create([
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $validated['message'],
        ]);

        // Retrieve only Public memories for LLM context.
        // Private and Sensitive records are never fed to the LLM — see IcpMemoryService::getPublicMemories().
        $memories = $this->icp->getPublicMemories($userId);

        // Build prompt with memory context
        $systemPrompt = $this->llm->buildSystemPrompt($memories);

        // Get recent conversation history for context
        $history = Message::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Generate AI response
        $aiResponse = $this->llm->chat($systemPrompt, $history);

        // Persist assistant message
        Message::create([
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $aiResponse,
        ]);

        // Summarize the exchange into a durable fact with a sensitivity classification.
        // Returns ['content' => '...', 'type' => 'public'|'private'|'sensitive'] or null.
        $memory   = $this->summarizer->extract($validated['message'], $aiResponse);
        $memoryId = null;
        $metadata = json_encode(['source' => 'chat', 'provider' => $this->llm->provider()]);

        if ($memory) {
            if ($this->icp->isMockMode() && ($memory['type'] ?? 'public') === 'public') {
                // Mock mode, public only: safe to write server-side without consent.
                $memoryId = $this->icp->storeMemory(
                    userId: $userId,
                    sessionId: $sessionId,
                    content: $memory['content'],
                    metadata: $metadata,
                    memoryType: 'public',
                );
            }
            // Private / Sensitive (both modes) and all types in live ICP mode:
            //   The browser shows an approval UI and POSTs to /chat/store-memory (mock)
            //   or signs directly to the canister (live). The server steps back.
        }

        return response()->json([
            'message'         => $aiResponse,
            'memory_id'       => $memoryId,
            'memory'          => $memory['content'] ?? null,
            'memory_type'     => $memory['type']    ?? null,
            'memory_metadata' => $metadata,
            'identity_source' => $identitySource,
            'user_id'         => $userId,
            'provider'        => $this->llm->provider(),
            'icp_mode'        => $this->icp->mode(),
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
            'content'     => 'required|string|max:2000',
            'memory_type' => 'required|in:private,sensitive',
            'metadata'    => 'nullable|string|max:1000',
        ]);

        $userId    = session()->get('chat_user_id');
        $sessionId = session()->get('chat_session_id');

        if (! $userId || ! $sessionId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        $id = $this->icp->mockStoreApproved(
            userId: $userId,
            sessionId: $sessionId,
            content: $validated['content'],
            metadata: $validated['metadata'] ?? null,
            memoryType: $validated['memory_type'],
        );

        return response()->json(['id' => $id]);
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
}
