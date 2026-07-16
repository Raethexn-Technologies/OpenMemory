<?php

namespace Tests\Feature;

use App\Models\MemoryNode;
use App\Services\BenchmarkService;
use App\Services\LLM\LlmService;
use App\Services\MemoryGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class BenchmarkServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_run_corpus_respects_context_limit_and_cleans_up_by_default(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['recency'], 1);

        $strategy = $result['results'][0]['strategies']['recency'];
        $this->assertSame(1, $strategy['retrieved_count']);
        $this->assertCount(1, $strategy['context']);
        $this->assertSame(['expected' => 1, 'completed' => 1, 'failed' => 0, 'complete' => true], $result['judge_calls']);
        $this->assertFalse($result['kept_seed_data']);
        $this->assertSame(0, MemoryNode::where('user_id', $result['user_id'])->count());
    }

    public function test_run_corpus_can_keep_seeded_partition_for_manual_inspection(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['recency'], 2, true);

        $this->assertTrue($result['kept_seed_data']);
        $this->assertGreaterThan(0, MemoryNode::where('user_id', $result['user_id'])->count());
    }

    public function test_run_corpus_calls_progress_callback_after_each_judge_call(): void
    {
        $service = $this->makeService();
        $calls = 0;

        $service->runCorpus(
            $this->corpus(),
            ['recency', 'goal_graph'],
            2,
            false,
            function () use (&$calls): void {
                $calls++;
            },
        );

        $this->assertSame(2, $calls);
    }

    public function test_run_corpus_marks_failed_judge_calls_as_incomplete(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->andThrow(new \RuntimeException('quota exhausted'));

        $service = new BenchmarkService(app(MemoryGraphService::class), $llm);

        $result = $service->runCorpus($this->corpus(), ['recency', 'goal_graph'], 2);

        $this->assertSame([
            'expected' => 2,
            'completed' => 0,
            'failed' => 2,
            'complete' => false,
        ], $result['judge_calls']);
        $this->assertNull($result['results'][0]['strategies']['recency']['scores']);
        $this->assertNull($result['results'][0]['strategies']['goal_graph']['scores']);
    }

    public function test_seed_corpus_excludes_goal_nodes_when_flag_is_true(): void
    {
        $service = $this->makeService();
        $userId = 'test-ablation-'.uniqid();

        $service->seedCorpus($this->corpus(), $userId, excludeGoals: true);

        $goalCount = MemoryNode::where('user_id', $userId)
            ->where('type', 'goal')
            ->count();

        $this->assertSame(0, $goalCount);

        $service->cleanupCorpus($userId);
    }

    public function test_seed_corpus_includes_goal_nodes_by_default(): void
    {
        $service = $this->makeService();
        $userId = 'test-goals-present-'.uniqid();

        $service->seedCorpus($this->corpus(), $userId);

        $goalCount = MemoryNode::where('user_id', $userId)
            ->where('type', 'goal')
            ->count();

        $this->assertGreaterThan(0, $goalCount);

        $service->cleanupCorpus($userId);
    }

    public function test_run_corpus_sets_goals_excluded_flag_when_requested(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['recency'], 2, false, null, true);

        $this->assertTrue($result['goals_excluded']);
        $this->assertSame(count($this->corpus()['memories']), $result['memory_count']);
    }

    public function test_run_corpus_goals_excluded_false_by_default(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['recency'], 2);

        $this->assertFalse($result['goals_excluded']);
        $this->assertSame(count($this->corpus()['memories']) + count($this->corpus()['goals']), $result['memory_count']);
    }

    public function test_run_corpus_supports_query_aware_strategies_and_stores_traces(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['query_lexical', 'query_graph', 'hybrid_query_graph'], 2);

        foreach (['query_lexical', 'query_graph', 'hybrid_query_graph'] as $strategy) {
            $r = $result['results'][0]['strategies'][$strategy];

            $this->assertGreaterThan(0, $r['retrieved_count']);
            $this->assertSame($strategy, $r['trace']['strategy']);
            $this->assertNotEmpty($r['trace']['query_terms'], 'The question text must reach retrieval as the query.');
            $this->assertSame(array_column($r['context'], 'id'), $r['trace']['retrieved_ids']);
            $this->assertArrayHasKey('retrieval_latency_ms', $r);
            $this->assertArrayHasKey('graph_added_ids', $r['trace']);
        }
    }

    public function test_theme_coverage_is_deterministic_and_lexical(): void
    {
        $service = $this->makeService();

        $context = [
            ['id' => 'a', 'content' => 'Improving retrieval quality is the current focus.', 'timestamp' => 'now'],
        ];

        $covered = $service->themeCoverage(['retrieval quality'], $context);
        $missed = $service->themeCoverage(['kubernetes autoscaling'], $context);
        $mixed = $service->themeCoverage(['retrieval quality', 'kubernetes autoscaling'], $context);

        $this->assertSame(1.0, $covered);
        $this->assertSame(0.0, $missed);
        $this->assertSame(0.5, $mixed);
        $this->assertNull($service->themeCoverage([], $context));
    }

    public function test_theme_coverage_survives_judge_failure(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->andThrow(new \RuntimeException('quota exhausted'));

        $service = new BenchmarkService(app(MemoryGraphService::class), $llm);

        $result = $service->runCorpus($this->corpus(), ['recency'], 2);

        $r = $result['results'][0]['strategies']['recency'];
        $this->assertNull($r['scores']);
        $this->assertNotNull($r['theme_coverage']);
        $this->assertNotNull($result['summary']['recency']['theme_coverage']);
        $this->assertSame(0, $result['summary']['recency']['question_count']);
    }

    public function test_question_class_is_recorded_when_present(): void
    {
        $service = $this->makeService();

        $corpus = $this->corpus();
        $corpus['questions'][0]['class'] = 'planning';

        $result = $service->runCorpus($corpus, ['recency'], 2);

        $this->assertSame('planning', $result['results'][0]['question_class']);
    }

    public function test_question_class_is_null_when_absent(): void
    {
        $service = $this->makeService();

        $result = $service->runCorpus($this->corpus(), ['recency'], 2);

        $this->assertNull($result['results'][0]['question_class']);
    }

    private function makeService(): BenchmarkService
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')
            ->andReturn('{"relevance": 4, "completeness": 4, "goal_alignment": 5, "noise_ratio": 4, "missing": "none", "irrelevant": "none"}');

        return new BenchmarkService(app(MemoryGraphService::class), $llm);
    }

    private function corpus(): array
    {
        return [
            'id' => 'test',
            'description' => 'Small benchmark fixture',
            'memories' => [
                [
                    'content' => 'Working on OpenMemory graph retrieval.',
                    'type' => 'memory',
                    'label' => 'Graph retrieval',
                    'tags' => ['openmemory', 'retrieval'],
                    'created_days_ago' => 5,
                ],
                [
                    'content' => 'Document ingestion now stores chunks in the graph.',
                    'type' => 'memory',
                    'label' => 'Document ingestion',
                    'tags' => ['openmemory', 'documents'],
                    'created_days_ago' => 2,
                ],
            ],
            'goals' => [
                [
                    'content' => 'Goal: improve OpenMemory retrieval quality.',
                    'label' => 'Improve retrieval quality',
                    'tags' => ['openmemory', 'retrieval'],
                    'created_days_ago' => 1,
                ],
            ],
            'questions' => [
                [
                    'id' => 'q1',
                    'question' => 'What should I work on next?',
                    'expected_themes' => ['retrieval quality'],
                ],
            ],
        ];
    }
}
