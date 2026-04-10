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
