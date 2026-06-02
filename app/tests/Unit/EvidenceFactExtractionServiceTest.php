<?php

namespace Tests\Unit;

use App\Models\EvidenceFact;
use App\Models\MemoryNode;
use App\Services\EvidenceFactExtractionService;
use App\Services\LLM\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class EvidenceFactExtractionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_extract_parses_json_facts(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chatFor')
            ->once()
            ->with(LlmService::TASK_REASON, Mockery::any(), Mockery::any())
            ->andReturn(json_encode([
                'facts' => [
                    [
                        'fact_text' => 'OpenMemory stores source-backed evidence facts.',
                        'quote' => 'source-backed evidence facts',
                        'confidence' => 0.87,
                    ],
                ],
            ]));

        $service = new EvidenceFactExtractionService($llm);

        $facts = $service->extract('The document describes source-backed evidence facts for grounded retrieval.');

        $this->assertCount(1, $facts);
        $this->assertSame('OpenMemory stores source-backed evidence facts.', $facts[0]['fact_text']);
        $this->assertSame('source-backed evidence facts', $facts[0]['quote']);
    }

    public function test_extract_and_store_derives_spans_from_exact_quote(): void
    {
        $chunk = 'The grounded mode refuses unsupported answers and cites every source-backed fact.';
        $quote = 'refuses unsupported answers';

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chatFor')
            ->once()
            ->andReturn(json_encode([
                'facts' => [
                    [
                        'fact_text' => 'The grounded mode refuses unsupported answers.',
                        'quote' => $quote,
                        'confidence' => 0.95,
                    ],
                ],
            ]));

        $sourceDocument = $this->makeNode('user-1', 'document_anchor', 'document');
        $sourceNode = $this->makeNode('user-1', 'document', 'concept');

        $service = new EvidenceFactExtractionService($llm);
        $stored = $service->extractAndStoreForNode('user-1', $sourceNode, $sourceDocument, $chunk, 2);

        $this->assertSame(1, $stored);

        $fact = EvidenceFact::firstOrFail();
        $expectedStart = mb_strpos($chunk, $quote);

        $this->assertSame('user-1', $fact->user_id);
        $this->assertSame($sourceNode->id, $fact->source_node_id);
        $this->assertSame($sourceDocument->id, $fact->source_document_id);
        $this->assertSame($expectedStart, $fact->span_start);
        $this->assertSame($expectedStart + mb_strlen($quote), $fact->span_end);
        $this->assertSame(2, $fact->metadata['chunk_index']);
        $this->assertSame('exact_quote', $fact->metadata['span_source']);
        $this->assertEqualsWithDelta(0.95, $fact->confidence, 0.0001);
    }

    public function test_unmatched_quote_stores_fact_without_span(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chatFor')
            ->once()
            ->andReturn(json_encode([
                'facts' => [
                    [
                        'fact_text' => 'The fact is supported but the quote did not match exactly.',
                        'quote' => 'not present in chunk',
                        'confidence' => 0.5,
                    ],
                ],
            ]));

        $sourceNode = $this->makeNode('user-1', 'document', 'concept');

        $service = new EvidenceFactExtractionService($llm);
        $stored = $service->extractAndStoreForNode('user-1', $sourceNode, null, 'Actual source text is different.', 0);

        $this->assertSame(1, $stored);

        $fact = EvidenceFact::firstOrFail();
        $this->assertNull($fact->span_start);
        $this->assertNull($fact->span_end);
        $this->assertSame('unmatched_quote', $fact->metadata['span_source']);
    }

    public function test_unparseable_response_returns_no_facts(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chatFor')->once()->andReturn('not json');

        $service = new EvidenceFactExtractionService($llm);

        $this->assertSame([], $service->extract('This chunk should not produce parsed facts.'));
    }

    private function makeNode(string $userId, string $source, string $type): MemoryNode
    {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => $type,
            'sensitivity' => 'public',
            'label' => "{$source} node",
            'content' => "{$source} node content",
            'tags' => [],
            'confidence' => 1.0,
            'source' => $source,
        ]);
    }
}
