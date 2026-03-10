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

        // Retrieve existing memories from ICP (query call — no authentication required)
        $memories = $this->icp->getMemories($userId);

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
            if ($this->icp->isMockMode()) {
                // Mock mode: server writes to file cache.
                // Sensitive memories are still written server-side here — the user
                // approval flow only applies in live ICP mode (where the browser signs).
                $memoryId = $this->icp->storeMemory(
                    userId: $userId,
                    sessionId: $sessionId,
                    content: $memory['content'],
                    metadata: $metadata,
                    memoryType: $memory['type'] ?? 'public',
                );
            }
            // Live ICP mode:
            //   public/private → browser auto-signs and stores.
            //   sensitive      → browser shows approval UI first.
            // The server returns the summary + type and steps back.
            // msg.caller on the canister will be the user's Ed25519 principal.
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
