<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\LLM\LlmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Benchmark harness for memory retrieval strategy comparison.
 *
 * Evaluates three retrieval strategies against synthetic but realistic user memory
 * corpora using an LLM-as-judge scoring protocol.
 *
 * Strategies are the ones defined in MemoryGraphService::STRATEGIES. The question
 * text is passed to retrieval as the query, so the query-aware strategies select
 * from the same question the judge later scores against. query_lexical is the
 * non-graph control for separating lexical selection from traversal value.
 *
 * Scoring dimensions (1-5 each):
 *   relevance: do the retrieved items address the question?
 *   completeness: could a good answer be constructed from this context?
 *   goal_alignment: does the context surface the user's active goals?
 *   noise_ratio: how free is the context from irrelevant items? (5 = very clean)
 *
 * Token efficiency = composite_score / (estimated_tokens / 1000)
 *
 * Alongside the LLM judge, a deterministic theme-coverage metric reports the
 * fraction of expected answer themes that are lexically present in the retrieved
 * context. It is model-free and repeatable, so strategy comparisons remain
 * possible even when judge calls fail or judge variance is in question.
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

    private readonly QueryRelevanceScorer $scorer;

    public function __construct(
        private readonly MemoryGraphService $graph,
        private readonly LlmService $llm,
        ?QueryRelevanceScorer $scorer = null,
    ) {
        $this->scorer = $scorer ?? new QueryRelevanceScorer;
    }

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
        bool $excludeGoals = false,
    ): array {
        if ($contextLimit < 1) {
            throw new \InvalidArgumentException('Context limit must be a positive integer.');
        }

        $corpusId = $corpus['id'];
        $userId = 'benchmark-'.$corpusId.'-'.uniqid();

        try {
            $this->seedCorpus($corpus, $userId, $excludeGoals);

            $results = [];

            foreach ($corpus['questions'] as $q) {
                $questionResults = [
                    'question_id' => $q['id'],
                    'question' => $q['question'],
                    'question_class' => $q['class'] ?? null,
                    'expected_themes' => $q['expected_themes'],
                    'strategies' => [],
                ];

                foreach ($strategies as $strategy) {
                    $retrievalStarted = hrtime(true);
                    $traced = $this->graph->retrieveContextTraced($userId, $contextLimit, $strategy, $q['question']);
                    $retrievalLatencyMs = round((hrtime(true) - $retrievalStarted) / 1_000_000, 3);
                    $context = $traced['records'];
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
                        'retrieval_latency_ms' => $retrievalLatencyMs,
                        'context' => $context,
                        'trace' => $traced['trace'],
                        'theme_coverage' => $this->themeCoverage($q['expected_themes'], $context),
                        'direct_seed_theme_coverage' => $this->themeCoverage(
                            $q['expected_themes'],
                            $this->contextSubset($context, $traced['trace']['direct_lexical_ids'] ?? []),
                        ),
                        'graph_added_theme_coverage' => $this->themeCoverage(
                            $q['expected_themes'],
                            $this->contextSubset($context, $traced['trace']['graph_added_ids'] ?? []),
                        ),
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
            $judgeCalls = $this->summariseJudgeCalls($results, $strategies);

            $memoryCount = count($corpus['memories']) + ($excludeGoals ? 0 : count($corpus['goals']));

            return [
                'corpus_id' => $corpusId,
                'user_id' => $userId,
                'kept_seed_data' => $keep,
                'goals_excluded' => $excludeGoals,
                'description' => $corpus['description'] ?? '',
                'question_count' => count($corpus['questions']),
                'memory_count' => $memoryCount,
                'judge_calls' => $judgeCalls,
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
    public function seedCorpus(array $corpus, string $userId, bool $excludeGoals = false): void
    {
        // Sort oldest-first so wireTagEdges() encounters earlier nodes when inserting later ones.
        $memories = collect($corpus['memories'])
            ->sortByDesc('created_days_ago')
            ->values();

        foreach ($memories as $item) {
            $extracted = [
                'type' => $item['type'] ?? 'memory',
                'sensitivity' => $item['sensitivity'] ?? 'public',
                'label' => $item['label'] ?? mb_substr($item['content'], 0, 80),
                'tags' => $item['tags'] ?? [],
                'people' => $item['people'] ?? [],
                'projects' => $item['projects'] ?? [],
            ];

            $node = $this->graph->storeNode($userId, $item['content'], $extracted, null, $item['source'] ?? 'chat');

            // Override auto-generated timestamps with corpus-specified recency.
            $nodeTime = Carbon::now()->subDays($item['created_days_ago']);
            $node->created_at = $nodeTime;
            $node->updated_at = $nodeTime;
            $node->saveQuietly();
        }

        if ($excludeGoals) {
            return;
        }

        // Add goal nodes after regular memories (recent by default, representing current active goals).
        foreach ($corpus['goals'] as $goal) {
            $extracted = [
                'type' => 'goal',
                'sensitivity' => 'public',
                'label' => $goal['label'],
                'tags' => $goal['tags'] ?? [],
                'people' => [],
                'projects' => [],
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
            return ($idx + 1).'. '.$item['content'];
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

    /**
     * Deterministic lexical proxy for context usefulness.
     *
     * A theme counts as covered when at least half of its significant terms
     * (rounded up) appear somewhere in the concatenated retrieved content.
     * Returns the covered fraction, or null when the question declares no
     * themes or no theme yields usable terms. This is a lexical approximation:
     * it detects surface presence, not semantic entailment, and is reported
     * alongside the LLM judge rather than instead of it.
     *
     * @param  string[]  $expectedThemes
     * @param  array<int, array{content: string}>  $context
     */
    public function themeCoverage(array $expectedThemes, array $context): ?float
    {
        if ($expectedThemes === []) {
            return null;
        }

        $haystack = mb_strtolower(implode("\n", array_column($context, 'content')));

        $scored = 0;
        $covered = 0;

        foreach ($expectedThemes as $theme) {
            $terms = $this->scorer->terms((string) $theme);
            if ($terms === []) {
                continue;
            }

            $scored++;

            $hits = count(array_filter($terms, static fn (string $term) => str_contains($haystack, $term)));

            if ($hits >= (int) ceil(count($terms) / 2)) {
                $covered++;
            }
        }

        return $scored === 0 ? null : round($covered / $scored, 4);
    }

    /**
     * @param  array<int, array{id: string, content: string, timestamp: string}>  $context
     * @param  string[]  $ids
     * @return array<int, array{id: string, content: string, timestamp: string}>
     */
    private function contextSubset(array $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $idSet = array_flip($ids);

        return array_values(array_filter(
            $context,
            static fn (array $item) => isset($idSet[$item['id']]),
        ));
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
        $themeTotals = [];
        $themeCounts = [];
        $metricTotals = [];
        $metricCounts = [];
        $judgeFailures = [];

        foreach ($strategies as $s) {
            $totals[$s] = ['relevance' => 0.0, 'completeness' => 0.0, 'goal_alignment' => 0.0, 'noise_ratio' => 0.0, 'composite' => 0.0, 'tokens' => 0, 'efficiency' => 0.0];
            $counts[$s] = 0;
            $themeTotals[$s] = 0.0;
            $themeCounts[$s] = 0;
            $metricTotals[$s] = [
                'selected_nodes' => 0.0,
                'retrieval_latency_ms' => 0.0,
                'direct_lexical_seed_nodes' => 0.0,
                'graph_added_nodes' => 0.0,
                'traversal_depth' => 0.0,
                'traversed_edges' => 0.0,
                'expansion_context_ratio' => 0.0,
            ];
            $metricCounts[$s] = 0;
            $judgeFailures[$s] = 0;
        }

        foreach ($results as $q) {
            foreach ($strategies as $s) {
                $r = $q['strategies'][$s] ?? null;
                if ($r === null) {
                    continue;
                }

                // Theme coverage is deterministic and survives judge failures,
                // so it is averaged independently of the judged dimensions.
                if (($r['theme_coverage'] ?? null) !== null) {
                    $themeTotals[$s] += $r['theme_coverage'];
                    $themeCounts[$s]++;
                }

                $retrievedCount = $r['retrieved_count'] ?? 0;
                $graphAddedCount = count($r['trace']['graph_added_ids'] ?? []);
                $metricTotals[$s]['selected_nodes'] += $retrievedCount;
                $metricTotals[$s]['retrieval_latency_ms'] += $r['retrieval_latency_ms'] ?? 0;
                $metricTotals[$s]['direct_lexical_seed_nodes'] += count($r['trace']['direct_lexical_ids'] ?? []);
                $metricTotals[$s]['graph_added_nodes'] += $graphAddedCount;
                $metricTotals[$s]['traversal_depth'] += $r['trace']['traversal_depth'] ?? 0;
                $metricTotals[$s]['traversed_edges'] += $r['trace']['traversed_edge_count'] ?? 0;
                $metricTotals[$s]['expansion_context_ratio'] += $retrievedCount > 0 ? $graphAddedCount / $retrievedCount : 0;
                $metricCounts[$s]++;

                if ($r['scores'] === null) {
                    $judgeFailures[$s]++;

                    continue;
                }

                $scores = $r['scores'];
                $totals[$s]['relevance'] += $scores['relevance'];
                $totals[$s]['completeness'] += $scores['completeness'];
                $totals[$s]['goal_alignment'] += $scores['goal_alignment'];
                $totals[$s]['noise_ratio'] += $scores['noise_ratio'];
                $totals[$s]['composite'] += $r['composite'] ?? 0;
                $totals[$s]['tokens'] += $r['token_estimate'] ?? 0;
                $totals[$s]['efficiency'] += $r['efficiency'] ?? 0;
                $counts[$s]++;
            }
        }

        $summary = [];
        foreach ($strategies as $s) {
            $n = $counts[$s];
            $themeMean = $themeCounts[$s] > 0 ? round($themeTotals[$s] / $themeCounts[$s], 4) : null;
            $mn = max(1, $metricCounts[$s]);
            $metrics = [
                'avg_selected_nodes' => round($metricTotals[$s]['selected_nodes'] / $mn, 2),
                'avg_retrieval_latency_ms' => round($metricTotals[$s]['retrieval_latency_ms'] / $mn, 3),
                'avg_direct_lexical_seed_nodes' => round($metricTotals[$s]['direct_lexical_seed_nodes'] / $mn, 2),
                'avg_graph_added_nodes' => round($metricTotals[$s]['graph_added_nodes'] / $mn, 2),
                'avg_traversal_depth' => round($metricTotals[$s]['traversal_depth'] / $mn, 2),
                'avg_traversed_edges' => round($metricTotals[$s]['traversed_edges'] / $mn, 2),
                'avg_expansion_context_ratio' => round($metricTotals[$s]['expansion_context_ratio'] / $mn, 4),
                'metric_question_count' => $metricCounts[$s],
                'judge_failures' => $judgeFailures[$s],
            ];

            if ($n === 0) {
                // Judge calls all failed. Theme coverage is still reportable.
                $summary[$s] = $themeMean === null ? null : array_merge([
                    'relevance' => null, 'completeness' => null, 'goal_alignment' => null,
                    'noise_ratio' => null, 'composite' => null, 'avg_tokens' => null,
                    'efficiency' => null, 'question_count' => 0,
                    'theme_coverage' => $themeMean,
                    'theme_question_count' => $themeCounts[$s],
                ], $metrics);

                continue;
            }

            $summary[$s] = array_merge([
                'relevance' => round($totals[$s]['relevance'] / $n, 2),
                'completeness' => round($totals[$s]['completeness'] / $n, 2),
                'goal_alignment' => round($totals[$s]['goal_alignment'] / $n, 2),
                'noise_ratio' => round($totals[$s]['noise_ratio'] / $n, 2),
                'composite' => round($totals[$s]['composite'] / $n, 2),
                'avg_tokens' => (int) round($totals[$s]['tokens'] / $n),
                'efficiency' => round($totals[$s]['efficiency'] / $n, 2),
                'question_count' => $n,
                'theme_coverage' => $themeMean,
                'theme_question_count' => $themeCounts[$s],
            ], $metrics);
        }

        return $summary;
    }

    /**
     * Count completed and failed judge calls for report validity checks.
     *
     * @param  string[]  $strategies
     * @return array{expected: int, completed: int, failed: int, complete: bool}
     */
    private function summariseJudgeCalls(array $results, array $strategies): array
    {
        $expected = count($results) * count($strategies);
        $completed = 0;

        foreach ($results as $q) {
            foreach ($strategies as $s) {
                $r = $q['strategies'][$s] ?? null;
                if ($r !== null && $r['scores'] !== null) {
                    $completed++;
                }
            }
        }

        $failed = $expected - $completed;

        return [
            'expected' => $expected,
            'completed' => $completed,
            'failed' => $failed,
            'complete' => $failed === 0,
        ];
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
        $end = strrpos($json, '}');
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

        $decoded['missing'] = (string) ($decoded['missing'] ?? '');
        $decoded['irrelevant'] = (string) ($decoded['irrelevant'] ?? '');

        return $decoded;
    }
}
