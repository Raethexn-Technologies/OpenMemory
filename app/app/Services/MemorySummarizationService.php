<?php

namespace App\Services;

use App\Services\LLM\LlmService;
use Illuminate\Support\Facades\Log;

class MemorySummarizationService
{
    private const SUMMARIZE_PROMPT = <<<PROMPT
You are a memory extraction and classification agent.

Given a conversation exchange, extract a durable fact about the user AND classify its sensitivity.

Respond with exactly one of these formats:
  PUBLIC: <one compact sentence, max 20 words>
  PRIVATE: <one compact sentence, max 20 words>
  SENSITIVE: <one compact sentence, max 20 words>
  NO_MEMORY

Classification guide:
  PUBLIC    — safe general facts that can be recalled by any agent
              (name, profession, interests, hobbies, general goals, skills)
  PRIVATE   — personal but not acutely sensitive; for the user to review
              (relationships, general location, habits, opinions, health preferences)
  SENSITIVE — potentially harmful if exposed without consent; requires user approval before storing
              (financial details, salary, medical conditions, precise location, credentials, anything the user framed as confidential)
  NO_MEMORY — no durable user fact in this exchange

Rules:
- Extract only facts about the USER, not the assistant
- When in doubt between PUBLIC and PRIVATE, choose PRIVATE
- When in doubt between PRIVATE and SENSITIVE, choose SENSITIVE
- Output ONLY the classification line — no explanation, no other text
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * Extract a durable memory from a conversation turn with a sensitivity classification.
     *
     * Returns an array ['content' => string, 'type' => 'public'|'private'|'sensitive']
     * or null if nothing memorable was shared.
     *
     * The 'type' determines:
     *   public    — stored automatically, readable by the agent and the public HTTP endpoint
     *   private   — stored automatically, readable only by the owner's authenticated principal
     *   sensitive — returned to the browser for user approval before signing and storing
     */
    public function extract(string $userMessage, string $assistantResponse): ?array
    {
        $messages = [
            [
                'role'    => 'user',
                'content' => "User said: \"{$userMessage}\"\nAssistant replied: \"{$assistantResponse}\"",
            ],
        ];

        $result = trim($this->llm->chat(self::SUMMARIZE_PROMPT, $messages));

        if ($result === 'NO_MEMORY' || empty($result)) {
            return null;
        }

        if (preg_match('/^(PUBLIC|PRIVATE|SENSITIVE):\s*(.+)$/s', $result, $m)) {
            return [
                'type'    => strtolower($m[1]),
                'content' => trim($m[2]),
            ];
        }

        // LLM responded with something we can't parse — discard rather than silently downgrade
        // a potentially Sensitive or Private classification to Public.
        Log::warning('MemorySummarizationService: unparseable LLM response — discarding', [
            'raw' => mb_substr($result, 0, 200),
        ]);
        return null;
    }
}
