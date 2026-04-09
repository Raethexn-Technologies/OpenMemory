<?php

namespace App\Services;

use App\Services\LLM\LlmService;
use Illuminate\Support\Facades\Log;

/**
 * Uses the LLM to extract structured node metadata from a memory fact.
 * The output drives auto-wiring of edges in MemoryGraphService.
 */
class GraphExtractionService
{
    private const EXTRACT_PROMPT = <<<'PROMPT'
You are a knowledge graph extraction agent building a brain-like memory graph.

Given a single memory fact, extract structured metadata to classify and connect it.

Respond with EXACTLY this format — no extra text, no explanation:
NODE_TYPE: <one of: memory|person|project|document|task|event|concept|goal>
LABEL: <3-7 word title that names this node>
TAGS: <3-7 comma-separated lowercase concept keywords>
PEOPLE: <comma-separated proper names of real people mentioned, or NONE>
PROJECTS: <comma-separated project or product names mentioned, or NONE>

Classification guide:
  memory    — a general recalled fact about the user
  person    — the fact is primarily about a specific named person
  project   — the fact is primarily about a named project or product
  document  — the fact refers to a specific file, document, or artifact
  task      — the fact is about something to do or in progress
  event     — the fact is about a specific event, meeting, or occurrence
  concept   — an abstract idea, preference, or belief
  goal      — a declared aspiration, outcome, or intention the user is working toward

Rules:
- TAGS should be specific and useful for finding related memories
- PEOPLE and PROJECTS must be proper nouns from the memory text
- LABEL should read naturally as a node title in a graph visualization
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * Extract graph metadata from a memory fact.
     *
     * Returns array with keys: type, label, tags, people, projects, sensitivity
     * Returns null if the LLM response cannot be parsed.
     */
    public function extract(string $content, string $sensitivityType): ?array
    {
        $messages = [
            ['role' => 'user', 'content' => "Memory fact: \"{$content}\""],
        ];

        $raw = trim($this->llm->chat(self::EXTRACT_PROMPT, $messages));

        $result = [];

        if (preg_match('/^NODE_TYPE:\s*(\w+)/m', $raw, $m)) {
            $valid = ['memory', 'person', 'project', 'document', 'task', 'event', 'concept', 'goal'];
            $result['type'] = in_array($m[1], $valid) ? $m[1] : 'memory';
        } else {
            Log::warning('GraphExtractionService: missing NODE_TYPE', ['raw' => mb_substr($raw, 0, 300)]);

            return null;
        }

        $result['label'] = preg_match('/^LABEL:\s*(.+)$/m', $raw, $m)
            ? trim($m[1])
            : mb_substr($content, 0, 60);

        $result['tags'] = $this->parseCsvLine($raw, 'TAGS');

        $result['people'] = $this->parseCsvLine($raw, 'PEOPLE');

        $result['projects'] = $this->parseCsvLine($raw, 'PROJECTS');

        $result['sensitivity'] = $sensitivityType;

        return $result;
    }

    /**
     * Parse a comma-separated line from the LLM output.
     *
     * @return array<int, string>
     */
    private function parseCsvLine(string $raw, string $field): array
    {
        if (! preg_match("/^{$field}:\s*(.+)$/m", $raw, $m)) {
            return [];
        }

        $value = trim($m[1]);
        if ($value === '' || strtoupper($value) === 'NONE') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item) => trim($field === 'TAGS' ? mb_strtolower($item) : $item),
            explode(',', $value),
        ))));
    }
}
