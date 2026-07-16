<?php

namespace App\Console\Commands;

use App\Services\BenchmarkService;
use App\Services\MemoryGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Run the retrieval strategy benchmark.
 *
 * Usage:
 *   php artisan benchmark:retrieval
 *   php artisan benchmark:retrieval --strategies=recency,goal_graph
 *   php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_04_longhorizon_engineer.json
 *   php artisan benchmark:retrieval --keep
 *   php artisan benchmark:retrieval --ablate-goals
 *
 * Strategies (MemoryGraphService::STRATEGIES):
 *   recency: most recently created public nodes, no graph traversal.
 *   graph: BFS from weight-ranked seeds, no goal priority.
 *   goal_graph: BFS from goal seeds first, then weight-ranked seeds.
 *   query_lexical: top query-relevant nodes directly, no graph traversal.
 *   query_graph: BFS from seeds ranked by lexical relevance to the question.
 *   hybrid_query_graph: BFS from combined query/weight/recency seeds; goal
 *     seeds admitted only when lexically relevant to the question.
 *
 * --ablate-goals runs a second pass of each corpus with goal nodes excluded from
 * seeding. The report includes a comparison table showing how much goal nodes
 * contribute to goal_alignment and composite scores for goal_graph.
 */
class BenchmarkRetrieval extends Command
{
    protected $signature = 'benchmark:retrieval
                            {--strategies=recency,graph,goal_graph,query_lexical,query_graph,hybrid_query_graph : Comma-separated strategies to compare}
                            {--corpus= : Path to a single corpus JSON file (runs all corpora by default)}
                            {--limit=12 : Number of context nodes to retrieve per strategy}
                            {--keep : Do not clean up seeded benchmark data after the run}
                            {--ablate-goals : Re-run each corpus without goal nodes to measure their contribution}';

    protected $description = 'Compare memory retrieval strategies against synthetic corpora using LLM-as-judge scoring';

    public function handle(BenchmarkService $benchmark): int
    {
        $strategies = array_filter(array_map('trim', explode(',', $this->option('strategies'))));
        $validStrategies = MemoryGraphService::STRATEGIES;

        if (empty($strategies)) {
            $this->error('No strategies provided. Valid options: '.implode(', ', $validStrategies));

            return self::FAILURE;
        }

        foreach ($strategies as $s) {
            if (! in_array($s, $validStrategies, true)) {
                $this->error("Unknown strategy: {$s}. Valid options: ".implode(', ', $validStrategies));

                return self::FAILURE;
            }
        }

        $contextLimit = (int) $this->option('limit');
        if ($contextLimit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $keep = (bool) $this->option('keep');
        $ablateGoals = (bool) $this->option('ablate-goals');
        $corpusPaths = $this->resolveCorpusPaths();

        if (empty($corpusPaths)) {
            $this->error('No corpus files found in database/benchmarks/. Create at least one corpus_*.json file.');

            return self::FAILURE;
        }

        $this->info('OpenMemory Retrieval Benchmark');
        $this->line('Strategies : '.implode(', ', $strategies));
        $this->line('Corpora    : '.count($corpusPaths));
        $this->line('Limit      : '.$contextLimit.' context nodes per retrieval');
        $this->line('Cleanup    : '.($keep ? 'keep seeded benchmark data' : 'delete seeded benchmark data'));
        if ($ablateGoals) {
            $this->line('Ablation   : goal nodes excluded in second pass');
        }
        $this->newLine();

        // Normal pass.
        $allCorpusResults = [];

        foreach ($corpusPaths as $path) {
            $corpus = json_decode(File::get($path), true);

            if (! is_array($corpus)) {
                $this->warn("Skipping {$path}: invalid JSON.");

                continue;
            }

            $this->line("  Corpus: {$corpus['id']} ({$corpus['description']})");
            $this->line('  '.count($corpus['memories']).' memories, '.count($corpus['goals']).' goals, '.count($corpus['questions']).' questions');

            $bar = $this->output->createProgressBar(count($corpus['questions']) * count($strategies));
            $bar->start();

            $corpusResult = $benchmark->runCorpus(
                $corpus,
                $strategies,
                $contextLimit,
                $keep,
                fn () => $bar->advance(),
            );

            $bar->finish();
            $this->newLine();

            $this->printCorpusSummary($corpusResult['summary'], $strategies);
            $this->newLine();

            $allCorpusResults[] = $corpusResult;
        }

        if (empty($allCorpusResults)) {
            $this->error('No corpora were successfully evaluated.');

            return self::FAILURE;
        }

        $aggregated = $this->aggregateResults($allCorpusResults, $strategies);
        $judgeCalls = $this->aggregateJudgeCalls($allCorpusResults);
        $aggregateGroups = $this->aggregateGroups($allCorpusResults, $aggregated, $judgeCalls, $strategies);
        $this->printAggregateSummary($allCorpusResults, $aggregated, $strategies);

        // Ablation pass.
        $ablationResults = [];
        $ablationAggregated = [];
        $ablationJudgeCalls = ['expected' => 0, 'completed' => 0, 'failed' => 0, 'complete' => true];

        if ($ablateGoals) {
            $this->newLine();
            $this->info('Goal ablation pass (goal nodes excluded from seeding):');

            foreach ($corpusPaths as $path) {
                $corpus = json_decode(File::get($path), true);

                if (! is_array($corpus)) {
                    continue;
                }

                $this->line("  Corpus: {$corpus['id']} (ablated)");

                $bar = $this->output->createProgressBar(count($corpus['questions']) * count($strategies));
                $bar->start();

                $corpusResult = $benchmark->runCorpus(
                    $corpus,
                    $strategies,
                    $contextLimit,
                    $keep,
                    fn () => $bar->advance(),
                    true, // excludeGoals
                );

                $bar->finish();
                $this->newLine();

                $this->printCorpusSummary($corpusResult['summary'], $strategies);
                $this->newLine();

                $ablationResults[] = $corpusResult;
            }

            if (! empty($ablationResults)) {
                $ablationAggregated = $this->aggregateResults($ablationResults, $strategies);
                $ablationJudgeCalls = $this->aggregateJudgeCalls($ablationResults);
                $this->printAblationComparison($allCorpusResults, $ablationResults, $aggregated, $ablationAggregated, $strategies);
            }
        }

        // Write outputs.
        $outputDir = storage_path('benchmarks');
        File::ensureDirectoryExists($outputDir);

        $timestamp = Carbon::now()->format('Y-m-d_His');
        $jsonPath = "{$outputDir}/results-{$timestamp}.json";
        $mdPath = "{$outputDir}/report-{$timestamp}.md";

        $jsonData = [
            'run_at' => Carbon::now()->toIso8601String(),
            'strategies' => $strategies,
            'context_limit' => $contextLimit,
            'judge_model' => (string) config('services.llm.openrouter_model'),
            'judge_protocol' => 'single judge call per (question, strategy); no temperature override (provider default); JSON rubric output',
            'kept_seed_data' => $keep,
            'judge_calls' => $judgeCalls,
            'corpora' => $allCorpusResults,
            'aggregate' => $aggregated,
            'aggregate_groups' => $aggregateGroups,
        ];

        if ($ablateGoals && ! empty($ablationResults)) {
            $jsonData['ablation'] = [
                'judge_calls' => $ablationJudgeCalls,
                'corpora' => $ablationResults,
                'aggregate' => $ablationAggregated,
            ];
        }

        File::put($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT));

        File::put($mdPath, $this->buildMarkdownReport(
            $allCorpusResults,
            $aggregated,
            $strategies,
            $contextLimit,
            $judgeCalls,
            $aggregateGroups,
            $ablationResults,
            $ablationAggregated,
            $ablationJudgeCalls,
        ));

        $this->newLine();
        $this->info('Results written to:');
        $this->line("  JSON   : {$jsonPath}");
        $this->line("  Report : {$mdPath}");

        $anyFailed = ! $judgeCalls['complete'] || ($ablateGoals && ! $ablationJudgeCalls['complete']);

        if ($anyFailed) {
            $totalFailed = $judgeCalls['failed'] + $ablationJudgeCalls['failed'];
            $this->newLine();
            $this->warn("Benchmark completed with {$totalFailed} failed judge calls. Treat the report as incomplete.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // Corpus resolution.

    private function resolveCorpusPaths(): array
    {
        $single = $this->option('corpus');

        if ($single) {
            $abs = base_path($single);

            return File::exists($abs) ? [$abs] : [];
        }

        return File::glob(base_path('database/benchmarks/corpus_*.json')) ?: [];
    }

    // Console output.

    private function printCorpusSummary(array $summary, array $strategies): void
    {
        $this->line('  Strategy            Rel.  Comp.  Goal  Noise  Composite  Nodes  Tokens  Latency  Graph+  Theme');
        $this->line('  '.str_repeat('-', 104));

        foreach ($strategies as $s) {
            $m = $summary[$s] ?? null;
            if ($m === null) {
                $this->line("  {$s}  (no data)");

                continue;
            }

            $theme = $m['theme_coverage'] ?? null;

            if (($m['composite'] ?? null) === null) {
                $this->line(sprintf(
                    '  %-18s  (judge calls failed)  Theme coverage: %s',
                    $s,
                    $theme === null ? 'n/a' : number_format($theme, 2),
                ));

                continue;
            }

            $this->line(sprintf(
                '  %-18s  %-5s  %-5s  %-4s  %-5s  %-9s  %-5s  %-6s  %-7s  %-6s  %s',
                $s,
                $m['relevance'],
                $m['completeness'],
                $m['goal_alignment'],
                $m['noise_ratio'],
                $m['composite'],
                $m['avg_selected_nodes'] ?? 'n/a',
                $m['avg_tokens'],
                $m['avg_retrieval_latency_ms'] ?? 'n/a',
                $m['avg_graph_added_nodes'] ?? 'n/a',
                $theme === null ? 'n/a' : number_format($theme, 2),
            ));
        }
    }

    private function printAggregateSummary(array $corpusResults, array $aggregated, array $strategies): void
    {
        $this->info('Aggregate across all corpora:');
        $this->printCorpusSummary($aggregated, $strategies);

        $recency = $aggregated['recency'] ?? null;
        $graph = $aggregated['graph'] ?? null;

        $this->newLine();

        foreach (['graph', 'goal_graph', 'query_lexical', 'query_graph', 'hybrid_query_graph'] as $candidate) {
            if (! in_array($candidate, $strategies, true) || ! in_array('recency', $strategies, true)) {
                continue;
            }

            $m = $aggregated[$candidate] ?? null;

            if ($m && $recency && ($recency['composite'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, [$candidate, 'recency'])) {
                $lift = round(($m['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
                $this->line("  {$candidate} vs recency: composite lift = {$lift}%");
            } else {
                $this->line("  {$candidate} vs recency: unavailable; judge results are incomplete.");
            }
        }

        $goalGraph = $aggregated['goal_graph'] ?? null;

        if ($goalGraph && $graph && ($graph['goal_alignment'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'graph'])) {
            $gaLift = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
            $this->line("  goal_graph vs graph: goal_alignment lift = {$gaLift}%");
        } elseif (in_array('goal_graph', $strategies, true) && in_array('graph', $strategies, true)) {
            $this->line('  goal_graph vs graph: unavailable; judge results are incomplete.');
        }

        $lexical = $aggregated['query_lexical'] ?? null;
        $queryGraph = $aggregated['query_graph'] ?? null;

        if ($lexical && $queryGraph && ($lexical['composite'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, ['query_graph', 'query_lexical'])) {
            $lift = round(($queryGraph['composite'] - $lexical['composite']) / $lexical['composite'] * 100, 1);
            $this->line("  query_graph vs query_lexical: composite lift = {$lift}%");
        } elseif (in_array('query_graph', $strategies, true) && in_array('query_lexical', $strategies, true)) {
            $this->line('  query_graph vs query_lexical: unavailable; judge results are incomplete.');
        }
    }

    private function printAblationComparison(
        array $normalResults,
        array $ablationResults,
        array $normalAggregated,
        array $ablationAggregated,
        array $strategies,
    ): void {
        if (! in_array('goal_graph', $strategies, true)) {
            return;
        }

        $this->info('Goal ablation comparison (goal_graph: with goals vs without):');
        $this->line('  Corpus           GA (with)  GA (w/o)  GA Delta   Composite (with)  Composite (w/o)  Delta');
        $this->line('  '.str_repeat('-', 102));

        foreach ($normalResults as $i => $normal) {
            $ablated = $ablationResults[$i] ?? null;
            $nSummary = $normal['summary']['goal_graph'] ?? null;
            $aSummary = $ablated['summary']['goal_graph'] ?? null;

            if ($nSummary === null || $aSummary === null) {
                continue;
            }

            $gaDelta = round($nSummary['goal_alignment'] - $aSummary['goal_alignment'], 2);
            $compDelta = round($nSummary['composite'] - $aSummary['composite'], 2);

            $this->line(sprintf(
                '  %-16s  %-9s  %-8s  %+.2f      %-16s  %-15s  %+.2f',
                $normal['corpus_id'],
                $nSummary['goal_alignment'],
                $aSummary['goal_alignment'],
                $gaDelta,
                $nSummary['composite'],
                $aSummary['composite'],
                $compDelta,
            ));
        }

        $nAgg = $normalAggregated['goal_graph'] ?? null;
        $aAgg = $ablationAggregated['goal_graph'] ?? null;

        if ($nAgg && $aAgg) {
            $this->line('  '.str_repeat('-', 102));
            $this->line(sprintf(
                '  %-16s  %-9s  %-8s  %+.2f      %-16s  %-15s  %+.2f',
                'AGGREGATE',
                $nAgg['goal_alignment'],
                $aAgg['goal_alignment'],
                round($nAgg['goal_alignment'] - $aAgg['goal_alignment'], 2),
                $nAgg['composite'],
                $aAgg['composite'],
                round($nAgg['composite'] - $aAgg['composite'], 2),
            ));
        }
    }

    // Aggregation.

    private function aggregateResults(array $corpusResults, array $strategies): array
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

        foreach ($corpusResults as $corpusResult) {
            foreach ($strategies as $s) {
                $summary = $corpusResult['summary'][$s] ?? null;
                if ($summary === null) {
                    continue;
                }

                $tn = $summary['theme_question_count'] ?? 0;
                if ($tn > 0 && ($summary['theme_coverage'] ?? null) !== null) {
                    $themeTotals[$s] += $summary['theme_coverage'] * $tn;
                    $themeCounts[$s] += $tn;
                }

                $mn = $summary['metric_question_count'] ?? $summary['question_count'] ?? 0;
                if ($mn > 0) {
                    $metricTotals[$s]['selected_nodes'] += ($summary['avg_selected_nodes'] ?? 0) * $mn;
                    $metricTotals[$s]['retrieval_latency_ms'] += ($summary['avg_retrieval_latency_ms'] ?? 0) * $mn;
                    $metricTotals[$s]['direct_lexical_seed_nodes'] += ($summary['avg_direct_lexical_seed_nodes'] ?? 0) * $mn;
                    $metricTotals[$s]['graph_added_nodes'] += ($summary['avg_graph_added_nodes'] ?? 0) * $mn;
                    $metricTotals[$s]['traversal_depth'] += ($summary['avg_traversal_depth'] ?? 0) * $mn;
                    $metricTotals[$s]['traversed_edges'] += ($summary['avg_traversed_edges'] ?? 0) * $mn;
                    $metricTotals[$s]['expansion_context_ratio'] += ($summary['avg_expansion_context_ratio'] ?? 0) * $mn;
                    $metricCounts[$s] += $mn;
                }

                $judgeFailures[$s] += $summary['judge_failures'] ?? 0;

                $n = $summary['question_count'];
                if ($n === 0 || ($summary['composite'] ?? null) === null) {
                    continue;
                }

                $totals[$s]['relevance'] += $summary['relevance'] * $n;
                $totals[$s]['completeness'] += $summary['completeness'] * $n;
                $totals[$s]['goal_alignment'] += $summary['goal_alignment'] * $n;
                $totals[$s]['noise_ratio'] += $summary['noise_ratio'] * $n;
                $totals[$s]['composite'] += $summary['composite'] * $n;
                $totals[$s]['tokens'] += $summary['avg_tokens'] * $n;
                $totals[$s]['efficiency'] += $summary['efficiency'] * $n;
                $counts[$s] += $n;
            }
        }

        $aggregated = [];
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
                $aggregated[$s] = $themeMean === null ? null : array_merge([
                    'relevance' => null, 'completeness' => null, 'goal_alignment' => null,
                    'noise_ratio' => null, 'composite' => null, 'avg_tokens' => null,
                    'efficiency' => null, 'question_count' => 0,
                    'theme_coverage' => $themeMean,
                    'theme_question_count' => $themeCounts[$s],
                ], $metrics);

                continue;
            }

            $aggregated[$s] = array_merge([
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

        return $aggregated;
    }

    private function aggregateJudgeCalls(array $corpusResults): array
    {
        $totals = ['expected' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($corpusResults as $corpusResult) {
            $calls = $corpusResult['judge_calls'] ?? ['expected' => 0, 'completed' => 0, 'failed' => 0];
            $totals['expected'] += $calls['expected'];
            $totals['completed'] += $calls['completed'];
            $totals['failed'] += $calls['failed'];
        }

        $totals['complete'] = $totals['failed'] === 0;

        return $totals;
    }

    private function aggregateGroups(array $corpusResults, array $aggregated, array $judgeCalls, array $strategies): array
    {
        $original = array_values(array_filter(
            $corpusResults,
            static fn (array $corpusResult) => ($corpusResult['corpus_id'] ?? '') !== 'corpus_05',
        ));
        $new = array_values(array_filter(
            $corpusResults,
            static fn (array $corpusResult) => ($corpusResult['corpus_id'] ?? '') === 'corpus_05',
        ));

        return [
            'all_corpora' => [
                'corpus_count' => count($corpusResults),
                'question_count' => array_sum(array_column($corpusResults, 'question_count')),
                'judge_calls' => $judgeCalls,
                'summary' => $aggregated,
            ],
            'original_corpora_only' => $this->aggregateGroup($original, $strategies),
            'new_adversarial_corpus_only' => $this->aggregateGroup($new, $strategies),
        ];
    }

    private function aggregateGroup(array $corpusResults, array $strategies): ?array
    {
        if ($corpusResults === []) {
            return null;
        }

        return [
            'corpus_count' => count($corpusResults),
            'question_count' => array_sum(array_column($corpusResults, 'question_count')),
            'judge_calls' => $this->aggregateJudgeCalls($corpusResults),
            'summary' => $this->aggregateResults($corpusResults, $strategies),
        ];
    }

    /**
     * Group per-question results by question class and compute per-strategy means.
     *
     * Returns [] when no question in any corpus declares a class, so corpora
     * without the `class` field keep their existing report shape.
     *
     * @return array<string, array<string, array{composite: float|null, theme_coverage: float|null, count: int}>>
     */
    private function buildClassBreakdown(array $corpusResults, array $strategies): array
    {
        $byClass = [];

        foreach ($corpusResults as $corpusResult) {
            foreach ($corpusResult['results'] as $q) {
                $class = $q['question_class'] ?? null;
                if ($class === null || $class === '') {
                    continue;
                }

                foreach ($strategies as $s) {
                    $r = $q['strategies'][$s] ?? null;
                    if ($r === null) {
                        continue;
                    }

                    $byClass[$class][$s]['composites'][] = $r['composite'];
                    $byClass[$class][$s]['themes'][] = $r['theme_coverage'] ?? null;
                }
            }
        }

        $breakdown = [];

        foreach ($byClass as $class => $strategyData) {
            foreach ($strategies as $s) {
                $data = $strategyData[$s] ?? null;
                if ($data === null) {
                    $breakdown[$class][$s] = null;

                    continue;
                }

                $composites = array_values(array_filter($data['composites'], static fn ($v) => $v !== null));
                $themes = array_values(array_filter($data['themes'], static fn ($v) => $v !== null));

                $breakdown[$class][$s] = [
                    'composite' => $composites === [] ? null : round(array_sum($composites) / count($composites), 2),
                    'theme_coverage' => $themes === [] ? null : round(array_sum($themes) / count($themes), 2),
                    'count' => count($composites),
                ];
            }
        }

        return $breakdown;
    }

    private function comparisonComplete(array $corpusResults, array $strategies): bool
    {
        foreach ($corpusResults as $corpusResult) {
            foreach ($corpusResult['results'] as $q) {
                foreach ($strategies as $strategy) {
                    if (($q['strategies'][$strategy]['scores'] ?? null) === null) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    // Markdown report.

    private function buildMarkdownReport(
        array $corpusResults,
        array $aggregated,
        array $strategies,
        int $contextLimit,
        array $judgeCalls,
        array $aggregateGroups = [],
        array $ablationResults = [],
        array $ablationAggregated = [],
        array $ablationJudgeCalls = [],
    ): string {
        $runAt = Carbon::now()->toIso8601String();
        $totalQuestions = array_sum(array_column($corpusResults, 'question_count'));

        $lines = [];
        $lines[] = '# Memory Retrieval Benchmark';
        $lines[] = '';
        $lines[] = "Run at: {$runAt}";
        $lines[] = 'Judge model: '.(string) config('services.llm.openrouter_model');
        $lines[] = 'Corpora: '.count($corpusResults)." | Questions: {$totalQuestions} | Strategies: ".implode(', ', $strategies);

        $callsSummary = "Judge calls: {$judgeCalls['completed']}/{$judgeCalls['expected']} completed";
        if (! empty($ablationResults)) {
            $callsSummary .= " | Ablation: {$ablationJudgeCalls['completed']}/{$ablationJudgeCalls['expected']} completed";
        }
        $lines[] = $callsSummary;
        $lines[] = '';

        if (! $judgeCalls['complete']) {
            $lines[] = "**Incomplete run.** {$judgeCalls['failed']} judge calls failed. Do not treat aggregate comparisons as valid findings.";
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Aggregate Results';
        $lines[] = '';
        $lines[] = $this->mdTable($aggregated, $strategies);
        $lines[] = '';

        $recency = $aggregated['recency'] ?? null;
        $graph = $aggregated['graph'] ?? null;

        foreach (['graph', 'goal_graph', 'query_lexical', 'query_graph', 'hybrid_query_graph'] as $candidate) {
            if (! in_array($candidate, $strategies, true) || ! in_array('recency', $strategies, true)) {
                continue;
            }

            $m = $aggregated[$candidate] ?? null;

            if ($m && $recency && ($recency['composite'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, [$candidate, 'recency'])) {
                $lift = round(($m['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
                $lines[] = "- {$candidate} vs recency: composite lift = **{$lift}%**";
            } else {
                $lines[] = "- {$candidate} vs recency: unavailable; judge results are incomplete.";
            }
        }
        $lines[] = '';

        $goalGraph = $aggregated['goal_graph'] ?? null;

        if ($goalGraph && $graph && ($graph['goal_alignment'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'graph'])) {
            $gaLift = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
            $lines[] = "Goal alignment for goal_graph changed by **{$gaLift}%** relative to weight-only graph retrieval.";
            $lines[] = '';
        }

        $lexical = $aggregated['query_lexical'] ?? null;
        $queryGraph = $aggregated['query_graph'] ?? null;

        if ($lexical && $queryGraph && ($lexical['composite'] ?? 0) > 0 && $this->comparisonComplete($corpusResults, ['query_graph', 'query_lexical'])) {
            $lift = round(($queryGraph['composite'] - $lexical['composite']) / $lexical['composite'] * 100, 1);
            $lines[] = "query_graph changed composite by **{$lift}%** relative to query_lexical.";
            $lines[] = '';
        }

        if (($aggregateGroups['original_corpora_only'] ?? null) !== null || ($aggregateGroups['new_adversarial_corpus_only'] ?? null) !== null) {
            $lines[] = '## Aggregate Slices';
            $lines[] = '';

            foreach ([
                'original_corpora_only' => 'Original corpora only',
                'new_adversarial_corpus_only' => 'New adversarial corpus only',
            ] as $key => $title) {
                $group = $aggregateGroups[$key] ?? null;
                if ($group === null) {
                    continue;
                }

                $lines[] = "### {$title}";
                $lines[] = '';
                $lines[] = "Corpora: {$group['corpus_count']} | Questions: {$group['question_count']} | Judge calls: {$group['judge_calls']['completed']}/{$group['judge_calls']['expected']}";
                $lines[] = '';
                $lines[] = $this->mdTable($group['summary'], $strategies);
                $lines[] = '';
            }
        }

        $classBreakdown = $this->buildClassBreakdown($corpusResults, $strategies);
        if ($classBreakdown !== []) {
            $lines[] = '## Per-Question-Class Composite (LLM-judged) and Theme Coverage (deterministic)';
            $lines[] = '';
            $lines[] = 'Question classes come from the `class` field on corpus questions. Cells show';
            $lines[] = 'mean composite / mean theme coverage with the judged question count in parentheses.';
            $lines[] = 'Classes with fewer than three questions are shown for completeness but are too';
            $lines[] = 'small to support a comparative claim on their own.';
            $lines[] = '';
            $lines[] = '| Class | '.implode(' | ', $strategies).' |';
            $lines[] = '|---'.str_repeat('|---', count($strategies)).'|';

            foreach ($classBreakdown as $class => $cells) {
                $row = "| {$class} |";
                foreach ($strategies as $s) {
                    $cell = $cells[$s] ?? null;
                    if ($cell === null) {
                        $row .= ' n/a |';

                        continue;
                    }
                    $composite = $cell['composite'] === null ? 'n/a' : sprintf('%.2f', $cell['composite']);
                    $theme = $cell['theme_coverage'] === null ? 'n/a' : sprintf('%.2f', $cell['theme_coverage']);
                    $row .= " {$composite} / {$theme} ({$cell['count']}) |";
                }
                $lines[] = $row;
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Per-Corpus Results';
        $lines[] = '';

        foreach ($corpusResults as $cr) {
            $lines[] = "### {$cr['corpus_id']}";
            $lines[] = '';
            $lines[] = $cr['description'];
            $lines[] = '';
            if (! empty($cr['kept_seed_data'])) {
                $lines[] = "Seed partition retained: `{$cr['user_id']}`";
                $lines[] = '';
            }
            $lines[] = "{$cr['memory_count']} memories and goals | {$cr['question_count']} questions";
            $lines[] = '';
            $lines[] = $this->mdTable($cr['summary'], $strategies);
            $lines[] = '';

            foreach ($cr['results'] as $q) {
                $classSuffix = ! empty($q['question_class']) ? " `[{$q['question_class']}]`" : '';
                $lines[] = "**{$q['question_id']}**{$classSuffix}: {$q['question']}";
                $lines[] = '';

                foreach ($strategies as $s) {
                    $r = $q['strategies'][$s] ?? null;
                    $scores = $r['scores'] ?? null;

                    if ($r === null) {
                        continue;
                    }

                    $theme = ($r['theme_coverage'] ?? null) === null
                        ? 'n/a'
                        : sprintf('%.2f', $r['theme_coverage']);

                    if ($scores === null) {
                        $lines[] = "- {$s}: judge call failed | theme_coverage={$theme}";

                        continue;
                    }

                    $lines[] = sprintf(
                        '- **%s**: R=%d C=%d G=%d N=%d | composite=%.2f | theme_coverage=%s | tokens=%d',
                        $s,
                        $scores['relevance'],
                        $scores['completeness'],
                        $scores['goal_alignment'],
                        $scores['noise_ratio'],
                        $r['composite'],
                        $theme,
                        $r['token_estimate'],
                    );
                    if (! empty($scores['missing'])) {
                        $lines[] = "  - Missing: {$scores['missing']}";
                    }
                }
                $lines[] = '';
            }
        }

        // Ablation section.
        if (! empty($ablationResults) && in_array('goal_graph', $strategies, true)) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## Goal Ablation Analysis';
            $lines[] = '';
            $lines[] = 'Second pass with goal nodes excluded from each corpus. Measures how much explicit goal nodes contribute to retrieval quality.';
            $lines[] = '';
            $lines[] = '### goal_graph: With Goals vs Without Goals';
            $lines[] = '';
            $lines[] = '| Corpus | GA (with goals) | GA (no goals) | GA Delta | Composite (with) | Composite (no goals) | Composite Delta |';
            $lines[] = '|---|---|---|---|---|---|---|';

            foreach ($corpusResults as $i => $normal) {
                $ablated = $ablationResults[$i] ?? null;
                $nSummary = $normal['summary']['goal_graph'] ?? null;
                $aSummary = $ablated['summary']['goal_graph'] ?? null;

                if ($nSummary === null || $aSummary === null) {
                    $lines[] = "| {$normal['corpus_id']} | n/a | n/a | n/a | n/a | n/a | n/a |";

                    continue;
                }

                $gaDelta = round($nSummary['goal_alignment'] - $aSummary['goal_alignment'], 2);
                $compDelta = round($nSummary['composite'] - $aSummary['composite'], 2);

                $lines[] = sprintf(
                    '| %s | %.2f | %.2f | %+.2f | %.2f | %.2f | %+.2f |',
                    $normal['corpus_id'],
                    $nSummary['goal_alignment'],
                    $aSummary['goal_alignment'],
                    $gaDelta,
                    $nSummary['composite'],
                    $aSummary['composite'],
                    $compDelta,
                );
            }

            $nNormAgg = $aggregated['goal_graph'] ?? null;
            $nAgg = $ablationAggregated['goal_graph'] ?? null;

            if ($nNormAgg && $nAgg) {
                $lines[] = sprintf(
                    '| **Aggregate** | **%.2f** | **%.2f** | **%+.2f** | **%.2f** | **%.2f** | **%+.2f** |',
                    $nNormAgg['goal_alignment'],
                    $nAgg['goal_alignment'],
                    round($nNormAgg['goal_alignment'] - $nAgg['goal_alignment'], 2),
                    $nNormAgg['composite'],
                    $nAgg['composite'],
                    round($nNormAgg['composite'] - $nAgg['composite'], 2),
                );
            }

            $lines[] = '';
            $lines[] = '### Ablated corpus aggregate (all strategies, no goal nodes)';
            $lines[] = '';
            $lines[] = $this->mdTable($ablationAggregated, $strategies);
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Methodology';
        $lines[] = '';
        $lines[] = '- Scoring: LLM-as-judge, one call per (question, strategy), judge model recorded above';
        $lines[] = '- Deterministic companion metric: theme coverage, the fraction of expected themes whose';
        $lines[] = '  significant terms (at least half, rounded up) appear in the retrieved context. Lexical';
        $lines[] = '  presence only; it does not measure semantic entailment.';
        $lines[] = '- The question text is passed to retrieval as the query. recency, graph, and goal_graph';
        $lines[] = '  ignore it; query_lexical, query_graph, and hybrid_query_graph use it.';
        $lines[] = '- query_lexical is the query-aware non-graph control: same lexical scorer and public';
        $lines[] = '  candidate pool as query_graph, but no edge traversal, edge weight, or goal seeding.';
        $lines[] = '- Retrieval is deterministic given the seeded corpus, so retrieval variance across repeated';
        $lines[] = '  runs is zero; only judge scores vary between runs.';
        $lines[] = '- Each judged result stores a retrieval trace (query terms, seed IDs, seed score components,';
        $lines[] = '  fallbacks taken, retrieved node IDs, graph-added IDs, traversal depth, traversed edges) in the JSON output.';
        $lines[] = '- Context limit: '.$contextLimit.' nodes per retrieval';
        $lines[] = '- Strategies: '.implode(' | ', $strategies);
        $lines[] = '- Corpora: synthetic, persona-driven';
        $lines[] = '- Token estimate: character count / 4 (approximation)';
        $lines[] = '- Efficiency: composite_score / (token_estimate / 1000)';
        $lines[] = '';
        $lines[] = 'Score rubric (1-5):';
        $lines[] = '- R = relevance: do retrieved items address the question?';
        $lines[] = '- C = completeness: can a good answer be constructed from the context?';
        $lines[] = '- G = goal_alignment: does context surface active goals and priorities?';
        $lines[] = '- N = noise_ratio: how free is context from off-topic items? (5 = very clean)';

        return implode("\n", $lines)."\n";
    }

    private function mdTable(array $summary, array $strategies): string
    {
        $header = '| Strategy | Relevance | Completeness | Goal Alignment | Noise Ratio | Composite | Avg Nodes | Avg Tokens | Latency ms | Graph Added | Efficiency | Theme Coverage |';
        $sep = '|---|---|---|---|---|---|---|---|---|---|---|---|';

        $rows = [$header, $sep];

        foreach ($strategies as $s) {
            $m = $summary[$s] ?? null;
            if ($m === null) {
                $rows[] = "| {$s} | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |";

                continue;
            }

            $theme = ($m['theme_coverage'] ?? null) === null ? 'n/a' : sprintf('%.2f', $m['theme_coverage']);

            if (($m['composite'] ?? null) === null) {
                $rows[] = sprintf(
                    '| %s | n/a | n/a | n/a | n/a | n/a | %.2f | n/a | %.3f | %.2f | n/a | %s |',
                    $s,
                    $m['avg_selected_nodes'] ?? 0,
                    $m['avg_retrieval_latency_ms'] ?? 0,
                    $m['avg_graph_added_nodes'] ?? 0,
                    $theme,
                );

                continue;
            }

            $rows[] = sprintf(
                '| %s | %.2f | %.2f | %.2f | %.2f | %.2f | %.2f | %d | %.3f | %.2f | %.2f | %s |',
                $s,
                $m['relevance'],
                $m['completeness'],
                $m['goal_alignment'],
                $m['noise_ratio'],
                $m['composite'],
                $m['avg_selected_nodes'] ?? 0,
                $m['avg_tokens'],
                $m['avg_retrieval_latency_ms'] ?? 0,
                $m['avg_graph_added_nodes'] ?? 0,
                $m['efficiency'],
                $theme,
            );
        }

        return implode("\n", $rows);
    }
}
