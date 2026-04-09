<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\DocumentChunkerService;
use App\Services\DocumentIngestionService;
use App\Services\GraphExtractionService;
use App\Services\MemoryGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class DocumentIngestionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_ingest_creates_anchor_node_with_document_anchor_source(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'My Goals', $this->multiParagraphText(), 'public');

        $this->assertArrayHasKey('document_node_id', $result);
        $this->assertDatabaseHas('memory_nodes', [
            'id'     => $result['document_node_id'],
            'source' => 'document_anchor',
            'type'   => 'document',
            'user_id' => 'user-1',
        ]);
    }

    public function test_anchor_node_has_empty_tags_to_prevent_false_edges(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'My Goals', $this->multiParagraphText(), 'public');

        $anchor = MemoryNode::find($result['document_node_id']);
        $this->assertEmpty($anchor->tags);
    }

    public function test_ingest_creates_chunk_nodes_with_document_source(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'My Goals', $this->multiParagraphText(), 'public');

        $this->assertGreaterThan(0, $result['nodes_created']);

        $chunkNodes = MemoryNode::where('user_id', 'user-1')
            ->where('source', 'document')
            ->get();

        $this->assertCount($result['nodes_created'], $chunkNodes);
    }

    public function test_chunk_nodes_have_part_of_edges_to_anchor(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'My Goals', $this->multiParagraphText(), 'public');

        $anchorId = $result['document_node_id'];
        $chunkNodes = MemoryNode::where('user_id', 'user-1')
            ->where('source', 'document')
            ->pluck('id');

        foreach ($chunkNodes as $chunkId) {
            $this->assertDatabaseHas('memory_edges', [
                'from_node_id' => $chunkId,
                'to_node_id'   => $anchorId,
                'relationship' => 'part_of',
            ]);
        }
    }

    public function test_chunk_metadata_records_source_document_id_and_index(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'My Goals', $this->multiParagraphText(), 'public');

        $anchorId = $result['document_node_id'];
        $chunk = MemoryNode::where('user_id', 'user-1')
            ->where('source', 'document')
            ->first();

        $this->assertSame($anchorId, $chunk->metadata['source_document_id']);
        $this->assertArrayHasKey('chunk_index', $chunk->metadata);
    }

    public function test_failed_extraction_skips_chunk_and_increments_skipped_count(): void
    {
        $extractor = Mockery::mock(GraphExtractionService::class);
        // Extractor returns null for every chunk — simulates unparseable LLM response.
        $extractor->shouldReceive('extract')->andReturn(null);

        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'Bad Doc', $this->multiParagraphText(), 'public');

        $this->assertSame(0, $result['nodes_created']);
        $this->assertGreaterThan(0, $result['chunks_skipped']);
        // Only the anchor node is in the DB — no chunk nodes.
        $this->assertDatabaseCount('memory_nodes', 1);
    }

    public function test_sensitivity_is_applied_to_all_nodes(): void
    {
        $extractor = $this->mockExtractor('concept');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $service->ingest('user-1', 'Private Doc', $this->multiParagraphText(), 'private');

        $nodes = MemoryNode::where('user_id', 'user-1')->get();
        foreach ($nodes as $node) {
            $this->assertSame('private', $node->sensitivity);
        }
    }

    public function test_chunks_total_matches_actual_chunk_count(): void
    {
        $extractor = $this->mockExtractor('memory');
        $service = new DocumentIngestionService(new DocumentChunkerService(), $extractor, new MemoryGraphService());

        $result = $service->ingest('user-1', 'Multi', $this->multiParagraphText(), 'public');

        $this->assertSame($result['chunks_total'], $result['nodes_created'] + $result['chunks_skipped']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mockExtractor(string $type): GraphExtractionService
    {
        $extractor = Mockery::mock(GraphExtractionService::class);
        $extractor->shouldReceive('extract')->andReturnUsing(
            fn (string $content, string $sensitivity) => [
                'type'        => $type,
                'label'       => mb_substr($content, 0, 40),
                'tags'        => ['test', 'ingested'],
                'people'      => [],
                'projects'    => [],
                'sensitivity' => $sensitivity,
            ]
        );

        return $extractor;
    }

    private function multiParagraphText(): string
    {
        return implode("\n\n", [
            'The first goal is to build a personal AI memory system that persists across tools and sessions without relying on vendor-controlled storage.',
            'The second goal is to make memory portable via open protocols so that any AI client can read and write to the same knowledge graph.',
            'The third goal is to demonstrate that Physarum polycephalum dynamics produce scale-free network topology in discrete graph structures.',
            'The fourth goal is to prove that multi-agent collective memory encodes knowledge at the group level that no individual agent holds alone.',
        ]);
    }
}
