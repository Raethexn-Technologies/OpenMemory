<?php

namespace App\Services;

use App\Models\MemoryNode;
use App\Services\LLM\LlmService;
use Illuminate\Support\Facades\Log;

/**
 * Determines whether a conversation turn contains a fact worth storing in the memory graph.
 *
 * Runs before MemorySummarizationService in the chat pipeline. If this service
 * returns 'skip', the entire memory storage pipeline is short-circuited for that turn,
 * preventing low-value episodic noise from accumulating in the graph over time.
 *
 * The classifier evaluates four criteria drawn from Craik and Lockhart (1972) levels
 * of processing and Tulving (1983) episodic vs. semantic memory theory:
 *
 *   1. Novelty      — Does this repeat a fact already stored?
 *   2. Significance — Is this a durable user fact, not small talk or filler?
 *   3. Durability   — Will this be relevant weeks or months from now?
 *   4. Connection   — Does this connect to existing nodes (enriches the graph)?
 *
 * Decisions:
 *   store_new       — Store as a new node. No close match exists.
 *   update_existing — The fact revises or extends an existing node. Returns the node_id.
 *   skip            — Nothing memorable. Do not enter the memory pipeline.
 */
class MemorabilityService
{
    // Max number of recent node summaries sent to the LLM for novelty checking.
    // Higher values improve duplicate detection at the cost of prompt size.
    private const CANDIDATE_LIMIT = 20;

    private const EVALUATE_PROMPT = <<<'PROMPT'
You are a memory filter for a long-term personal AI assistant.

Your job is to decide whether a conversation turn contains a fact worth storing in the user's long-term memory graph. Apply four criteria:

1. NOVELTY      — Is this new information not already covered by an existing memory?
2. SIGNIFICANCE — Is this a durable user fact (not small talk, greetings, or filler)?
3. DURABILITY   — Will this still be relevant weeks or months from now?
4. CONNECTION   — Does this enrich an existing memory node rather than duplicate it?

You will receive:
- The conversation turn (user message + assistant reply)
- A list of recent memory nodes with their IDs and content (may be empty)

Respond with EXACTLY one of:
  STORE_NEW
  UPDATE_EXISTING:<uuid-of-the-node-to-update>
  SKIP

Rules:
- STORE_NEW    when the turn contains a new durable fact with no close match
- UPDATE_EXISTING when the turn revises, extends, or adds detail to an existing node
- SKIP         when the turn is small talk, a question with no user-fact answer,
               a repeat of something already stored, or transient data (e.g. current time)
- Output ONLY the decision line — no explanation, no other text
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * Evaluate whether the conversation turn should produce a memory node.
     *
     * @return array{decision: 'store_new'|'update_existing'|'skip', node_id: string|null}
     */
    public function evaluate(string $userMessage, string $assistantResponse, string $userId): array
    {
        if (! \App\Services\LLM\ModelDisclosure::allows('chat')) {
            return ['decision' => 'skip', 'node_id' => null];
        }
        $recentNodes = MemoryNode::where('user_id', $userId)
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->latest()
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'label', 'content']);

        $nodeList = $recentNodes->isEmpty()
            ? '(no memories stored yet)'
            : $recentNodes->map(fn ($n) => "[{$n->id}] {$n->label}: {$n->content}")->implode("\n");

        $messages = [
            [
                'role'    => 'user',
                'content' => implode("\n\n", [
                    "Conversation turn:",
                    "User: \"{$userMessage}\"",
                    "Assistant: \"{$assistantResponse}\"",
                    "Existing memory nodes:",
                    $nodeList,
                ]),
            ],
        ];

        $result = trim($this->llm->chat(self::EVALUATE_PROMPT, \App\Services\LLM\EvidenceMessages::task('Evaluate this conversation turn.', $messages), 'chat'));

        if ($result === 'STORE_NEW') {
            return ['decision' => 'store_new', 'node_id' => null];
        }

        if ($result === 'SKIP') {
            return ['decision' => 'skip', 'node_id' => null];
        }

        if (preg_match('/^UPDATE_EXISTING:([0-9a-f\-]{36})$/i', $result, $m)) {
            $nodeId = $m[1];

            // Verify the node belongs to this user before trusting the ID.
            $exists = $recentNodes->contains('id', $nodeId);
            if ($exists) {
                return ['decision' => 'update_existing', 'node_id' => $nodeId];
            }

            // Node ID hallucinated or belongs to a different user — store as new.
            Log::warning('MemorabilityService: UPDATE_EXISTING node not found, storing as new', [
                'error_category' => 'invalid_candidate',
            ]);

            return ['decision' => 'store_new', 'node_id' => null];
        }

        // Unparseable response — default to skip to avoid polluting the graph.
        Log::warning('MemorabilityService: unparseable LLM response — skipping', [
            'error_category' => 'invalid_model_output',
        ]);

        return ['decision' => 'skip', 'node_id' => null];
    }
}
