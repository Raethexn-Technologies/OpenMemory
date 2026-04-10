<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\LLM\LlmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Benchmark harness for memory retrieval strategy comparison.
 *
 * Evaluates three retrieval strategies against synthetic but realistic user memory
 * corpora using an LLM-as-judge scoring protocol.
 *
 * Strategies:
 *   recency: most recently created public nodes, no graph traversal.
 *   graph: BFS from weight-ranked seeds, goal nodes treated as ordinary candidates.
 *   goal_graph: BFS from goal-node seeds first, then weight-ranked seeds.
 *
 * Scoring dimensions (1-5 each):
 *   relevance: do the retrieved items address the question?
 *   completeness: could a good answer be constructed from this context?
 *   goal_alignment: does the context surface the user's active goals?
 *   noise_ratio: how free is the context from irrelevant items? (5 = very clean)
 *
 * Token efficiency = composite_score / (estimated_tokens / 1000)
 *
 * Each corpus run seeds a fresh isolated user partition, runs all strategies and
 * questions, scores results with the LLM judge, then cleans up unless --keep is set.
 */
class BenchmarkService
{
    // How many nodes to retrieve per strategy per question.
    private const CONTEXT_LIMIT = 12;

    private const JUDGE_SYSTEM = <<<'PROMPT'
You are a memory retrieval evaluator. Your job is to assess whether a retrieved set of memory records would enable a useful answer to a user question.

Be precise and critical. Low scores (1-2) are appropriate when the context clearly misses what the question requires. Reserve 5 for context that is genuinely comprehensive.

Always output valid JSON with no surrounding text, no markdown fences.
PROMPT;

    private const JUDGE_USER_TEMPLATE = <<<'TEMPLATE'
User question:
{{question}}

Expected answer themes (information a good answer should cover):
{{themes}}

Retrieved context ({{count}} items):
{{context}}

Score the retrieved context on each dimension from 1 to 5 (integers only).

relevance:       Do the retrieved items address the question directly?
                 5 = all items directly relevant  |  1 = mostly irrelevant items retrieved

completeness:    Could a thorough answer be constructed from this context?
                 5 = all needed information present  |  1 = critical information absent

goal_alignment:  Does the context surface the user's active goals and current priorities?
                 5 = goals clearly present and driving context  |  1 = no goals or priorities visible

noise_ratio:     How free is the context from off-topic items?
                 5 = very clean, little waste  |  1 = most items are off-topic

Output JSON only. No explanation.
{"relevance": N, "completeness": N, "goal_alignment": N, "noise_ratio": N, "missing": "what key info is absent", "irrelevant": "what items are clearly off-topic"}
TEMPLATE;

    public function __construct(
        private readonly MemoryGraphService $graph,
        private readonly LlmService $llm,
    ) {}

    /**
     * Run the full benchmark against one corpus file.
     *
     * @param  array  $corpus  Decoded corpus JSON
     * @param  string[]  $strategies
     * @return array{corpus_id: string, user_id: string, results: array, summary: array}
     */
    public function runCorpus(
        array $corpus,
        array $strategies,
        int $contextLimit = self::CONTEXT_LIMIT,
        bool $keep = false,
        ?callable $onJudged = null,
    ): array
    {
        if ($contextLimit < 1) {
            throw new \InvalidArgumentException('Context limit must be a positive integer.');
        }

        $corpusId = $corpus['id'];
        $userId = 'benchmark-' . $corpusId . '-' . uniqid();

        try {
            $this->seedCorpus($corpus, $userId);

            $results = [];

            foreach ($corpus['questions'] as $q) {
                $questionResults = [
                    'question_id' => $q['id'],
                    'question' => $q['question'],
                    'expected_themes' => $q['expected_themes'],
                    'strategies' => [],
                ];

                foreach ($strategies as $strategy) {
                    $context = $this->graph->retrieveContext($userId, $contextLimit, $strategy);
                    $scores = $this->judgeContext($q['question'], $q['expected_themes'], $context);

                    $charCount = array_sum(array_map(fn ($c) => mb_strlen($c['content']), $context));
                    $tokenEstimate = (int) ceil($charCount / 4);

                    $composite = $scores !== null
                        ? round(array_sum([
                            $scores['relevance'],
                            $scores['completeness'],
                            $scores['goal_alignment'],
                            $scores['noise_ratio'],
                        ]) / 4, 2)
                        : null;

                    $efficiency = ($composite !== null && $tokenEstimate > 0)
                        ? round($composite / ($tokenEstimate / 1000), 2)
                        : null;

                    $questionResults['strategies'][$strategy] = [
                        'retrieved_count' => count($context),
                        'token_estimate' => $tokenEstimate,
                        'context' => $context,
                        'scores' => $scores,
                        'composite' => $composite,
                        'efficiency' => $efficiency,
                    ];

                    if ($onJudged !== null) {
                        $onJudged($corpusId, $q['id'], $strategy);
                    }
                }

                $results[] = $questionResults;
            }

            $summary = $this->summariseCorpus($results, $strategies);

            return [
                'corpus_id' => $corpusId,
                'user_id' => $userId,
                'kept_seed_data' => $keep,
                'description' => $corpus['description'] ?? '',
                'question_count' => count($corpus['questions']),
                'memory_count' => count($corpus['memories']) + count($corpus['goals']),
                'results' => $results,
                'summary' => $summary,
            ];
        } finally {
            if (! $keep) {
                $this->cleanupCorpus($userId);
            }
        }
    }

    // Seeding.

    /**
     * Seed corpus memories into an isolated user partition.
     *
     * Nodes are inserted oldest-first so that tag edges form between older and newer
     * nodes as they would in real usage. Created-at timestamps are set after insertion
     * to match the corpus-specified recency distribution.
     */
    public function seedCorpus(array $corpus, string $userId): void
    {
        // Sort oldest-first so wireTagEdges() encounters earlier nodes when inserting later ones.
        $memories = collect($corpus['memories'])
            ->sortByDesc('created_days_ago')
            ->values();

        foreach ($memories as $item) {
            $extracted = [
                'type'        => $item['type'] ?? 'memory',
                'sensitivity' => $item['sensitivity'] ?? 'public',
                'label'       => $item['label'] ?? mb_substr($item['content'], 0, 80),
                'tags'        => $item['tags'] ?? [],
                'people'      => $item['people'] ?? [],
                'projects'    => $item['projects'] ?? [],
            ];

            $node = $this->graph->storeNode($userId, $item['content'], $extracted, null, $item['source'] ?? 'chat');

            // Override auto-generated timestamps with corpus-specified recency.
            $nodeTime = Carbon::now()->subDays($item['created_days_ago']);
            $node->created_at = $nodeTime;
            $node->updated_at = $nodeTime;
            $node->saveQuietly();
        }

        // Add goal nodes after regular memories (recent by default, representing current active goals).
        foreach ($corpus['goals'] as $goal) {
            $extracted = [
                'type'        => 'goal',
                'sensitivity' => 'public',
                'label'       => $goal['label'],
                'tags'        => $goal['tags'] ?? [],
                'people'      => [],
                'projects'    => [],
            ];

            $node = $this->graph->storeNode($userId, $goal['content'], $extracted, null, 'chat');

            $goalTime = Carbon::now()->subDays($goal['created_days_ago'] ?? 1);
            $node->created_at = $goalTime;
            $node->updated_at = $goalTime;
            $node->saveQuietly();
        }
    }

    // Cleanup.

    public function cleanupCorpus(string $userId): void
    {
        MemoryEdge::where('user_id', $userId)->delete();
        MemoryNode::where('user_id', $userId)->delete();
    }

    // Judging.

    /**
     * Ask the LLM to judge the quality of retrieved context for a given question.
     *
     * Returns an array of scores, or null if the LLM response cannot be parsed.
     *
     * @param  string[]  $expectedThemes
     * @param  array<int, array{id: string, content: string, timestamp: string}>  $context
     * @return array{relevance: int, completeness: int, goal_alignment: int, noise_ratio: int, missing: string, irrelevant: string}|null
     */
    public function judgeContext(string $question, array $expectedThemes, array $context): ?array
    {
        if (empty($context)) {
            return [
                'relevance' => 1, 'completeness' => 1,
                'goal_alignment' => 1, 'noise_ratio' => 5,
                'missing' => 'All context missing; empty retrieval result.',
                'irrelevant' => 'none',
            ];
        }

        $contextLines = array_map(function ($item, $idx) {
            return ($idx + 1) . '. ' . $item['content'];
        }, $context, array_keys($context));

        $userMessage = str_replace(
            ['{{question}}', '{{themes}}', '{{count}}', '{{context}}'],
            [
                $question,
                implode(', ', $expectedThemes),
                count($context),
                implode("\n", $contextLines),
            ],
            self::JUDGE_USER_TEMPLATE
        );

        $messages = [['role' => 'user', 'content' => $userMessage]];

        try {
            $raw = $this->llm->chat(self::JUDGE_SYSTEM, $messages);
            return $this->parseJudgeResponse($raw);
        } catch (\Throwable $e) {
            Log::warning('BenchmarkService: judge LLM call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // Summary.

    /**
     * Compute per-strategy means across all questions in a corpus.
     *
     * @return array<string, array{relevance: float, completeness: float, goal_alignment: float, noise_ratio: float, composite: float, avg_tokens: int, efficiency: float}>
     */
    private function summariseCorpus(array $results, array $strategies): array
    {
        $totals = [];
        $counts = [];

        foreach ($strategies as $s) {
            $totals[$s] = ['relevance' => 0.0, 'completeness' => 0.0, 'goal_alignment' => 0.0, 'noise_ratio' => 0.0, 'composite' => 0.0, 'tokens' => 0, 'efficiency' => 0.0];
            $counts[$s] = 0;
        }

        foreach ($results as $q) {
            foreach ($strategies as $s) {
                $r = $q['strategies'][$s] ?? null;
                if ($r === null || $r['scores'] === null) {
                    continue;
                }

                $scores = $r['scores'];
                $totals[$s]['relevance']      += $scores['relevance'];
                $totals[$s]['completeness']    += $scores['completeness'];
                $totals[$s]['goal_alignment']  += $scores['goal_alignment'];
                $totals[$s]['noise_ratio']     += $scores['noise_ratio'];
                $totals[$s]['composite']       += $r['composite'] ?? 0;
                $totals[$s]['tokens']          += $r['token_estimate'] ?? 0;
                $totals[$s]['efficiency']      += $r['efficiency'] ?? 0;
                $counts[$s]++;
            }
        }

        $summary = [];
        foreach ($strategies as $s) {
            $n = $counts[$s];
            if ($n === 0) {
                $summary[$s] = null;
                continue;
            }

            $summary[$s] = [
                'relevance'      => round($totals[$s]['relevance'] / $n, 2),
                'completeness'   => round($totals[$s]['completeness'] / $n, 2),
                'goal_alignment' => round($totals[$s]['goal_alignment'] / $n, 2),
                'noise_ratio'    => round($totals[$s]['noise_ratio'] / $n, 2),
                'composite'      => round($totals[$s]['composite'] / $n, 2),
                'avg_tokens'     => (int) round($totals[$s]['tokens'] / $n),
                'efficiency'     => round($totals[$s]['efficiency'] / $n, 2),
                'question_count' => $n,
            ];
        }

        return $summary;
    }

    // JSON parsing.

    private function parseJudgeResponse(string $raw): ?array
    {
        // Strip markdown code fences if present.
        $json = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $json = preg_replace('/\s*```$/m', '', $json);
        $json = trim($json);

        // Extract the first complete JSON object.
        $start = strpos($json, '{');
        $end   = strrpos($json, '}');
        if ($start === false || $end === false) {
            return null;
        }

        $json = substr($json, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Validate required integer fields.
        foreach (['relevance', 'completeness', 'goal_alignment', 'noise_ratio'] as $field) {
            if (! isset($decoded[$field]) || ! is_numeric($decoded[$field])) {
                return null;
            }
            $decoded[$field] = (int) $decoded[$field];
            if ($decoded[$field] < 1 || $decoded[$field] > 5) {
                return null;
            }
        }

        $decoded['missing']    = (string) ($decoded['missing'] ?? '');
        $decoded['irrelevant'] = (string) ($decoded['irrelevant'] ?? '');

        return $decoded;
    }
}
