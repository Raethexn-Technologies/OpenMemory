<?php

namespace App\Services\Ingest;

use App\Services\LLM\LlmService;
use Illuminate\Support\Facades\Log;

/**
 * Distil a single ingested item (commit, issue, calendar event, file diff)
 * into one durable memory line plus a sensitivity classification.
 *
 * This is the ingest-side counterpart to MemorySummarizationService. The
 * chat summariser takes a two-turn exchange and asks "what fact did the user
 * just share". The ingest summariser takes one arbitrary text payload and
 * asks "what is the user worth remembering about this artefact". Different
 * shapes of input, same output contract: PUBLIC/PRIVATE/SENSITIVE/NO_MEMORY.
 *
 * Routed to the cheap classify model — these calls fire repeatedly during
 * an ingest sweep and accuracy matters less than throughput. The expensive
 * downstream step (graph extraction, if the caller chooses to wire one)
 * runs after this filter, so noise gets dropped before it reaches reasoning.
 */
class IngestSummarizer
{
    private const PROMPT = <<<'PROMPT'
You are a memory extraction agent processing ingested content from a user's connected sources (git commits, calendar events, files, messages).

Given one ingested item, decide whether it represents a durable fact about the user's work or life that would be worth recalling later. If it does, extract that fact and classify its sensitivity.

Respond with EXACTLY one of these formats:
  PUBLIC: <one compact sentence, max 25 words>
  PRIVATE: <one compact sentence, max 25 words>
  SENSITIVE: <one compact sentence, max 25 words>
  NO_MEMORY

Classification guide:
  PUBLIC    — work artefacts safe for any agent to recall (project names, technical decisions, public commits, public events)
  PRIVATE   — personal context that is the user's to control (relationships, locations, opinions, internal team detail)
  SENSITIVE — would harm the user if exposed (credentials, salary, medical, precise location, anything marked confidential)
  NO_MEMORY — routine, duplicate, or low-signal content (typo fixes, merge commits, empty events)

Rules:
- Write the memory from the user's perspective ("user is working on X", "user committed Y")
- Bias toward NO_MEMORY for trivial items — duplicates clog the memory layer
- Redaction tokens like [EMAIL] or [BANK#token] must be preserved, never restored
- When in doubt between PUBLIC and PRIVATE, choose PRIVATE
- Output ONLY the classification line — no preamble, no explanation
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * @return array{type: 'public'|'private'|'sensitive', content: string}|null
     */
    public function extract(string $sourceLabel, string $itemText): ?array
    {
        if (trim($itemText) === '') {
            return null;
        }

        $messages = [
            [
                'role'    => 'user',
                'content' => "Source: {$sourceLabel}\n\nItem:\n{$itemText}",
            ],
        ];

        $result = trim($this->llm->chatFor(LlmService::TASK_SUMMARIZE, self::PROMPT, $messages));

        if ($result === '' || $result === 'NO_MEMORY') {
            return null;
        }

        if (preg_match('/^(PUBLIC|PRIVATE|SENSITIVE):\s*(.+)$/s', $result, $m)) {
            return [
                'type'    => strtolower($m[1]),
                'content' => trim($m[2]),
            ];
        }

        // Same policy as MemorySummarizationService: an unparseable response
        // must not silently downgrade an unknown classification to public.
        Log::warning('IngestSummarizer: unparseable LLM response — discarding', [
            'source' => $sourceLabel,
            'raw'    => mb_substr($result, 0, 200),
        ]);

        return null;
    }
}
