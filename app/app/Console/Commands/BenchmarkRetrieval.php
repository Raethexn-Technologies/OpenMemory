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
 *   php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_01_software_developer.json
 *   php artisan benchmark:retrieval --keep   # do not clean up seeded data
 *
 * Each strategy is evaluated against every question in every corpus. The LLM judges
 * each (strategy, question) pair and scores on four dimensions (1-5). Results are
 * written to storage/benchmarks/ as JSON (full detail) and Markdown (readable report).
 *
 * Strategies:
 *   recency: most recently created public nodes, no graph traversal.
 *   graph: BFS from weight-ranked seeds, no goal priority.
 *   goal_graph: BFS from goal seeds first, then weight-ranked seeds.
 */
class BenchmarkRetrieval extends Command
{
    protected $signature = 'benchmark:retrieval
                            {--strategies=recency,graph,goal_graph : Comma-separated strategies to compare}
                            {--corpus= : Path to a single corpus JSON file (runs all corpora by default)}
                            {--limit=12 : Number of context nodes to retrieve per strategy}
                            {--keep : Do not clean up seeded benchmark data after the run}';

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

        $keep = (bool) $this->option('keep');
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
        $this->newLine();

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

        $aggregated = $this->aggregateResults($allCorpusResults, $strategies);
        $this->printAggregateSummary($aggregated, $strategies);

        $outputDir = storage_path('benchmarks');
        File::ensureDirectoryExists($outputDir);

        $timestamp = Carbon::now()->format('Y-m-d_His');
        $jsonPath  = "{$outputDir}/results-{$timestamp}.json";
        $mdPath    = "{$outputDir}/report-{$timestamp}.md";

        File::put($jsonPath, json_encode([
            'run_at'     => Carbon::now()->toIso8601String(),
            'strategies' => $strategies,
            'context_limit' => $contextLimit,
            'kept_seed_data' => $keep,
            'corpora'    => $allCorpusResults,
            'aggregate'  => $aggregated,
        ], JSON_PRETTY_PRINT));

        File::put($mdPath, $this->buildMarkdownReport($allCorpusResults, $aggregated, $strategies, $contextLimit));

        $this->newLine();
        $this->info('Results written to:');
        $this->line("  JSON   : {$jsonPath}");
        $this->line("  Report : {$mdPath}");

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

    private function printAggregateSummary(array $aggregated, array $strategies): void
    {
        $this->info('Aggregate across all corpora:');
        $this->printCorpusSummary($aggregated, $strategies);

        // Surface the key comparison numbers.
        $goalGraph = $aggregated['goal_graph'] ?? null;
        $recency   = $aggregated['recency'] ?? null;
        $graph     = $aggregated['graph'] ?? null;

        if ($goalGraph && $recency && $recency['composite'] > 0) {
            $lift = round(($goalGraph['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
            $this->newLine();
            $this->line("  goal_graph vs recency: composite lift = {$lift}%");
        }

        if ($goalGraph && $graph && $graph['goal_alignment'] > 0) {
            $gaLift = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
            $this->line("  goal_graph vs graph: goal_alignment lift = {$gaLift}%");
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

    // Markdown report.

    private function buildMarkdownReport(array $corpusResults, array $aggregated, array $strategies, int $contextLimit): string
    {
        $runAt = Carbon::now()->toIso8601String();
        $totalQuestions = array_sum(array_column($corpusResults, 'question_count'));

        $lines = [];
        $lines[] = "# Memory Retrieval Benchmark";
        $lines[] = "";
        $lines[] = "Run at: {$runAt}";
        $lines[] = "Corpora: " . count($corpusResults) . " | Questions: {$totalQuestions} | Strategies: " . implode(', ', $strategies);
        $lines[] = "";
        $lines[] = "---";
        $lines[] = "";
        $lines[] = "## Aggregate Results";
        $lines[] = "";
        $lines[] = $this->mdTable($aggregated, $strategies);
        $lines[] = "";

        // Key findings
        $goalGraph = $aggregated['goal_graph'] ?? null;
        $recency   = $aggregated['recency'] ?? null;
        $graph     = $aggregated['graph'] ?? null;

        if ($goalGraph && $recency && $recency['composite'] > 0) {
            $lift   = round(($goalGraph['composite'] - $recency['composite']) / $recency['composite'] * 100, 1);
            $lines[] = "Goal-biased graph retrieval improved composite score by **{$lift}%** over the recency baseline.";
            $lines[] = "";
        }

        if ($goalGraph && $graph && $graph['goal_alignment'] > 0) {
            $gaLift = round(($goalGraph['goal_alignment'] - $graph['goal_alignment']) / $graph['goal_alignment'] * 100, 1);
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
                    $r = $q['strategies'][$s] ?? null;
                    if ($r === null) {
                        continue;
                    }
                    $scores = $r['scores'];
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

        $lines[] = "---";
        $lines[] = "";
        $lines[] = "## Methodology";
        $lines[] = "";
        $lines[] = "- Scoring: LLM-as-judge";
        $lines[] = "- Context limit: " . $contextLimit . " nodes per retrieval";
        $lines[] = "- Strategies: " . implode(' | ', $strategies);
        $lines[] = "- Corpora: synthetic, persona-driven, 20-22 memories each";
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
