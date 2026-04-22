<?php

namespace App\Console\Commands;

use App\Services\BenchmarkService;
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
 * Strategies:
 *   recency: most recently created public nodes, no graph traversal.
 *   graph: BFS from weight-ranked seeds, no goal priority.
 *   goal_graph: BFS from goal seeds first, then weight-ranked seeds.
 *
 * --ablate-goals runs a second pass of each corpus with goal nodes excluded from
 * seeding. The report includes a comparison table showing how much goal nodes
 * contribute to goal_alignment and composite scores for goal_graph.
 */
class BenchmarkRetrieval extends Command
{
    protected $signature = 'benchmark:retrieval
                            {--strategies=recency,graph,goal_graph : Comma-separated strategies to compare}
                            {--corpus= : Path to a single corpus JSON file (runs all corpora by default)}
                            {--limit=12 : Number of context nodes to retrieve per strategy}
                            {--keep : Do not clean up seeded benchmark data after the run}
                            {--ablate-goals : Re-run each corpus without goal nodes to measure their contribution}';

    protected $description = 'Compare memory retrieval strategies against synthetic corpora using LLM-as-judge scoring';

    public function handle(BenchmarkService $benchmark): int
    {
        $strategies = array_filter(array_map('trim', explode(',', $this->option('strategies'))));
        $validStrategies = ['recency', 'graph', 'goal_graph'];

        if (empty($strategies)) {
            $this->error('No strategies provided. Valid options: ' . implode(', ', $validStrategies));
            return self::FAILURE;
        }

        foreach ($strategies as $s) {
            if (! in_array($s, $validStrategies, true)) {
                $this->error("Unknown strategy: {$s}. Valid options: " . implode(', ', $validStrategies));
                return self::FAILURE;
            }
        }

        $contextLimit = (int) $this->option('limit');
        if ($contextLimit < 1) {
            $this->error('The --limit option must be a positive integer.');
            return self::FAILURE;
        }

        $keep        = (bool) $this->option('keep');
        $ablateGoals = (bool) $this->option('ablate-goals');
        $corpusPaths = $this->resolveCorpusPaths();

        if (empty($corpusPaths)) {
            $this->error('No corpus files found in database/benchmarks/. Create at least one corpus_*.json file.');
            return self::FAILURE;
        }

        $this->info('OpenMemory Retrieval Benchmark');
        $this->line('Strategies : ' . implode(', ', $strategies));
        $this->line('Corpora    : ' . count($corpusPaths));
        $this->line('Limit      : ' . $contextLimit . ' context nodes per retrieval');
        $this->line('Cleanup    : ' . ($keep ? 'keep seeded benchmark data' : 'delete seeded benchmark data'));
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
            $this->line('  ' . count($corpus['memories']) . ' memories, ' . count($corpus['goals']) . ' goals, ' . count($corpus['questions']) . ' questions');

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

        $aggregated  = $this->aggregateResults($allCorpusResults, $strategies);
        $judgeCalls  = $this->aggregateJudgeCalls($allCorpusResults);
        $this->printAggregateSummary($allCorpusResults, $aggregated, $strategies);

        // Ablation pass.
        $ablationResults    = [];
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
        $jsonPath  = "{$outputDir}/results-{$timestamp}.json";
        $mdPath    = "{$outputDir}/report-{$timestamp}.md";

        $jsonData = [
            'run_at'         => Carbon::now()->toIso8601String(),
            'strategies'     => $strategies,
            'context_limit'  => $contextLimit,
            'kept_seed_data' => $keep,
            'judge_calls'    => $judgeCalls,
            'corpora'        => $allCorpusResults,
            'aggregate'      => $aggregated,
        ];

        if ($ablateGoals && ! empty($ablationResults)) {
            $jsonData['ablation'] = [
                'judge_calls' => $ablationJudgeCalls,
                'corpora'     => $ablationResults,
                'aggregate'   => $ablationAggregated,
            ];
        }

        File::put($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT));

        File::put($mdPath, $this->buildMarkdownReport(
            $allCorpusResults,
            $aggregated,
            $strategies,
            $contextLimit,
            $judgeCalls,
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
        $this->line('  Strategy         Relevance  Completeness  Goal Align.  Noise Ratio  Composite  Tokens');
        $this->line('  ' . str_repeat('-', 88));

        foreach ($strategies as $s) {
            $m = $summary[$s] ?? null;
            if ($m === null) {
                $this->line("  {$s}  (no data)");
                continue;
            }

            $this->line(sprintf(
                '  %-16s  %-9s  %-12s  %-11s  %-11s  %-9s  %s',
                $s,
                $m['relevance'],
                $m['completeness'],
                $m['goal_alignment'],
                $m['noise_ratio'],
                $m['composite'],
                $m['avg_tokens'],
            ));
        }
    }

    private function printAggregateSummary(array $corpusResults, array $aggregated, array $strategies): void
    {
        $this->info('Aggregate across all corpora:');
        $this->printCorpusSummary($aggregated, $strategies);

        $goalGraph = $aggregated['goal_graph'] ?? null;
        $recency   = $aggregated['recency'] ?? null;
        $graph     = $aggregated['graph'] ?? null;

        if ($goalGraph && $recency && $recency['composite'] > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'recency'])) {
            $lift = round(($goalGraph['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
            $this->newLine();
            $this->line("  goal_graph vs recency: composite lift = {$lift}%");
        } elseif (in_array('goal_graph', $strategies, true) && in_array('recency', $strategies, true)) {
            $this->newLine();
            $this->line('  goal_graph vs recency: unavailable; judge results are incomplete.');
        }

        if ($goalGraph && $graph && $graph['goal_alignment'] > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'graph'])) {
            $gaLift = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
            $this->line("  goal_graph vs graph: goal_alignment lift = {$gaLift}%");
        } elseif (in_array('goal_graph', $strategies, true) && in_array('graph', $strategies, true)) {
            $this->line('  goal_graph vs graph: unavailable; judge results are incomplete.');
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
        $this->line('  ' . str_repeat('-', 102));

        foreach ($normalResults as $i => $normal) {
            $ablated  = $ablationResults[$i] ?? null;
            $nSummary = $normal['summary']['goal_graph'] ?? null;
            $aSummary = $ablated['summary']['goal_graph'] ?? null;

            if ($nSummary === null || $aSummary === null) {
                continue;
            }

            $gaDelta   = round($nSummary['goal_alignment'] - $aSummary['goal_alignment'], 2);
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
            $this->line('  ' . str_repeat('-', 102));
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

        foreach ($strategies as $s) {
            $totals[$s] = ['relevance' => 0.0, 'completeness' => 0.0, 'goal_alignment' => 0.0, 'noise_ratio' => 0.0, 'composite' => 0.0, 'tokens' => 0, 'efficiency' => 0.0];
            $counts[$s] = 0;
        }

        foreach ($corpusResults as $corpusResult) {
            foreach ($strategies as $s) {
                $summary = $corpusResult['summary'][$s] ?? null;
                if ($summary === null) {
                    continue;
                }

                $n = $summary['question_count'];
                $totals[$s]['relevance']      += $summary['relevance'] * $n;
                $totals[$s]['completeness']   += $summary['completeness'] * $n;
                $totals[$s]['goal_alignment'] += $summary['goal_alignment'] * $n;
                $totals[$s]['noise_ratio']    += $summary['noise_ratio'] * $n;
                $totals[$s]['composite']      += $summary['composite'] * $n;
                $totals[$s]['tokens']         += $summary['avg_tokens'] * $n;
                $totals[$s]['efficiency']     += $summary['efficiency'] * $n;
                $counts[$s] += $n;
            }
        }

        $aggregated = [];
        foreach ($strategies as $s) {
            $n = $counts[$s];
            if ($n === 0) {
                $aggregated[$s] = null;
                continue;
            }

            $aggregated[$s] = [
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

        return $aggregated;
    }

    private function aggregateJudgeCalls(array $corpusResults): array
    {
        $totals = ['expected' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($corpusResults as $corpusResult) {
            $calls = $corpusResult['judge_calls'] ?? ['expected' => 0, 'completed' => 0, 'failed' => 0];
            $totals['expected']  += $calls['expected'];
            $totals['completed'] += $calls['completed'];
            $totals['failed']    += $calls['failed'];
        }

        $totals['complete'] = $totals['failed'] === 0;

        return $totals;
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
        array $ablationResults = [],
        array $ablationAggregated = [],
        array $ablationJudgeCalls = [],
    ): string {
        $runAt          = Carbon::now()->toIso8601String();
        $totalQuestions = array_sum(array_column($corpusResults, 'question_count'));

        $lines   = [];
        $lines[] = "# Memory Retrieval Benchmark";
        $lines[] = "";
        $lines[] = "Run at: {$runAt}";
        $lines[] = "Corpora: " . count($corpusResults) . " | Questions: {$totalQuestions} | Strategies: " . implode(', ', $strategies);

        $callsSummary = "Judge calls: {$judgeCalls['completed']}/{$judgeCalls['expected']} completed";
        if (! empty($ablationResults)) {
            $callsSummary .= " | Ablation: {$ablationJudgeCalls['completed']}/{$ablationJudgeCalls['expected']} completed";
        }
        $lines[] = $callsSummary;
        $lines[] = "";

        if (! $judgeCalls['complete']) {
            $lines[] = "**Incomplete run.** {$judgeCalls['failed']} judge calls failed. Do not treat aggregate comparisons as valid findings.";
            $lines[] = "";
        }

        $lines[] = "---";
        $lines[] = "";
        $lines[] = "## Aggregate Results";
        $lines[] = "";
        $lines[] = $this->mdTable($aggregated, $strategies);
        $lines[] = "";

        $goalGraph = $aggregated['goal_graph'] ?? null;
        $recency   = $aggregated['recency'] ?? null;
        $graph     = $aggregated['graph'] ?? null;

        if ($goalGraph && $recency && $recency['composite'] > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'recency'])) {
            $lift    = round(($goalGraph['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
            if ($lift > 0) {
                $lines[] = "Goal-biased graph retrieval improved composite score by **{$lift}%** over the recency baseline.";
            } elseif ($lift < 0) {
                $lines[] = "Goal-biased graph retrieval reduced composite score by **" . abs($lift) . "%** versus the recency baseline.";
            } else {
                $lines[] = "Goal-biased graph retrieval matched the recency baseline on composite score.";
            }
            $lines[] = "";
        }

        if ($goalGraph && $graph && $graph['goal_alignment'] > 0 && $this->comparisonComplete($corpusResults, ['goal_graph', 'graph'])) {
            $gaLift  = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
            $lines[] = "Goal alignment improved by **{$gaLift}%** over weight-only graph retrieval.";
            $lines[] = "";
        }

        $lines[] = "---";
        $lines[] = "";
        $lines[] = "## Per-Corpus Results";
        $lines[] = "";

        foreach ($corpusResults as $cr) {
            $lines[] = "### {$cr['corpus_id']}";
            $lines[] = "";
            $lines[] = $cr['description'];
            $lines[] = "";
            if (! empty($cr['kept_seed_data'])) {
                $lines[] = "Seed partition retained: `{$cr['user_id']}`";
                $lines[] = "";
            }
            $lines[] = "{$cr['memory_count']} memories and goals | {$cr['question_count']} questions";
            $lines[] = "";
            $lines[] = $this->mdTable($cr['summary'], $strategies);
            $lines[] = "";

            foreach ($cr['results'] as $q) {
                $lines[] = "**{$q['question_id']}**: {$q['question']}";
                $lines[] = "";

                foreach ($strategies as $s) {
                    $r      = $q['strategies'][$s] ?? null;
                    $scores = $r['scores'] ?? null;

                    if ($r === null) {
                        continue;
                    }
                    if ($scores === null) {
                        $lines[] = "- {$s}: judge call failed";
                        continue;
                    }

                    $lines[] = sprintf(
                        "- **%s**: R=%d C=%d G=%d N=%d | composite=%.2f | tokens=%d",
                        $s,
                        $scores['relevance'],
                        $scores['completeness'],
                        $scores['goal_alignment'],
                        $scores['noise_ratio'],
                        $r['composite'],
                        $r['token_estimate'],
                    );
                    if (! empty($scores['missing'])) {
                        $lines[] = "  - Missing: {$scores['missing']}";
                    }
                }
                $lines[] = "";
            }
        }

        // Ablation section.
        if (! empty($ablationResults) && in_array('goal_graph', $strategies, true)) {
            $lines[] = "---";
            $lines[] = "";
            $lines[] = "## Goal Ablation Analysis";
            $lines[] = "";
            $lines[] = "Second pass with goal nodes excluded from each corpus. Measures how much explicit goal nodes contribute to retrieval quality.";
            $lines[] = "";
            $lines[] = "### goal_graph: With Goals vs Without Goals";
            $lines[] = "";
            $lines[] = "| Corpus | GA (with goals) | GA (no goals) | GA Delta | Composite (with) | Composite (no goals) | Composite Delta |";
            $lines[] = "|---|---|---|---|---|---|---|";

            foreach ($corpusResults as $i => $normal) {
                $ablated  = $ablationResults[$i] ?? null;
                $nSummary = $normal['summary']['goal_graph'] ?? null;
                $aSummary = $ablated['summary']['goal_graph'] ?? null;

                if ($nSummary === null || $aSummary === null) {
                    $lines[] = "| {$normal['corpus_id']} | n/a | n/a | n/a | n/a | n/a | n/a |";
                    continue;
                }

                $gaDelta   = round($nSummary['goal_alignment'] - $aSummary['goal_alignment'], 2);
                $compDelta = round($nSummary['composite'] - $aSummary['composite'], 2);

                $lines[] = sprintf(
                    "| %s | %.2f | %.2f | %+.2f | %.2f | %.2f | %+.2f |",
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
            $nAgg     = $ablationAggregated['goal_graph'] ?? null;

            if ($nNormAgg && $nAgg) {
                $lines[] = sprintf(
                    "| **Aggregate** | **%.2f** | **%.2f** | **%+.2f** | **%.2f** | **%.2f** | **%+.2f** |",
                    $nNormAgg['goal_alignment'],
                    $nAgg['goal_alignment'],
                    round($nNormAgg['goal_alignment'] - $nAgg['goal_alignment'], 2),
                    $nNormAgg['composite'],
                    $nAgg['composite'],
                    round($nNormAgg['composite'] - $nAgg['composite'], 2),
                );
            }

            $lines[] = "";
            $lines[] = "### Ablated corpus aggregate (all strategies, no goal nodes)";
            $lines[] = "";
            $lines[] = $this->mdTable($ablationAggregated, $strategies);
            $lines[] = "";
        }

        $lines[] = "---";
        $lines[] = "";
        $lines[] = "## Methodology";
        $lines[] = "";
        $lines[] = "- Scoring: LLM-as-judge";
        $lines[] = "- Context limit: " . $contextLimit . " nodes per retrieval";
        $lines[] = "- Strategies: " . implode(' | ', $strategies);
        $lines[] = "- Corpora: synthetic, persona-driven";
        $lines[] = "- Token estimate: character count / 4 (approximation)";
        $lines[] = "- Efficiency: composite_score / (token_estimate / 1000)";
        $lines[] = "";
        $lines[] = "Score rubric (1-5):";
        $lines[] = "- R = relevance: do retrieved items address the question?";
        $lines[] = "- C = completeness: can a good answer be constructed from the context?";
        $lines[] = "- G = goal_alignment: does context surface active goals and priorities?";
        $lines[] = "- N = noise_ratio: how free is context from off-topic items? (5 = very clean)";

        return implode("\n", $lines) . "\n";
    }

    private function mdTable(array $summary, array $strategies): string
    {
        $header = "| Strategy | Relevance | Completeness | Goal Alignment | Noise Ratio | Composite | Avg Tokens | Efficiency |";
        $sep    = "|---|---|---|---|---|---|---|---|";

        $rows = [$header, $sep];

        foreach ($strategies as $s) {
            $m = $summary[$s] ?? null;
            if ($m === null) {
                $rows[] = "| {$s} | n/a | n/a | n/a | n/a | n/a | n/a | n/a |";
                continue;
            }

            $rows[] = sprintf(
                '| %s | %.2f | %.2f | %.2f | %.2f | %.2f | %d | %.2f |',
                $s,
                $m['relevance'],
                $m['completeness'],
                $m['goal_alignment'],
                $m['noise_ratio'],
                $m['composite'],
                $m['avg_tokens'],
                $m['efficiency'],
            );
        }

        return implode("\n", $rows);
    }
}
