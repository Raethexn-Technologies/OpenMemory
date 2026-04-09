<?php

namespace Tests\Feature;

use App\Models\MemoryNode;
use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    // ── POST /api/documents/ingest ────────────────────────────────────────────

    public function test_ingest_requires_session(): void
    {
        $this->mockIcp();

        $response = $this->postJson('/api/documents/ingest', [
            'title' => 'My Goals',
            'text'  => $this->sampleText(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'No user identity. Please refresh.');
    }

    public function test_ingest_requires_title(): void
    {
        $this->mockIcp();

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->postJson('/api/documents/ingest', ['text' => $this->sampleText()]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_ingest_requires_text_or_file(): void
    {
        $this->mockIcp();

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->postJson('/api/documents/ingest', ['title' => 'Empty']);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Provide either text or a file upload.');
    }

    public function test_ingest_text_creates_nodes_and_returns_stats(): void
    {
        // Use private sensitivity so icp->storeMemory() is not triggered,
        // keeping this test focused on node creation rather than ICP write behaviour.
        $this->mockIcp();
        $this->mockExtractor();

        $response = $this->withSession([
            'chat_user_id'    => 'user-1',
            'chat_session_id' => 'session-1',
        ])->postJson('/api/documents/ingest', [
            'title'       => 'My Goals',
            'text'        => $this->sampleText(),
            'sensitivity' => 'private',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['document_node_id', 'document_label', 'chunks_total', 'nodes_created', 'chunks_skipped']);
        $this->assertGreaterThan(0, $response->json('nodes_created'));
    }

    public function test_ingest_calls_icp_store_for_public_mock_mode(): void
    {
        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $icp->shouldReceive('storeMemory')->once()->andReturn('icp-id-1');
        $this->app->instance(IcpMemoryService::class, $icp);

        $this->mockExtractor();

        $response = $this->withSession([
            'chat_user_id'    => 'user-1',
            'chat_session_id' => 'session-1',
        ])->postJson('/api/documents/ingest', [
            'title'       => 'Public Doc',
            'text'        => $this->sampleText(),
            'sensitivity' => 'public',
        ]);

        $response->assertStatus(201);
    }

    public function test_ingest_skips_icp_store_for_private_documents(): void
    {
        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        // storeMemory must NOT be called for private sensitivity.
        $icp->shouldNotReceive('storeMemory');
        $this->app->instance(IcpMemoryService::class, $icp);

        $this->mockExtractor();

        $response = $this->withSession([
            'chat_user_id'    => 'user-1',
            'chat_session_id' => 'session-1',
        ])->postJson('/api/documents/ingest', [
            'title'       => 'Private Doc',
            'text'        => $this->sampleText(),
            'sensitivity' => 'private',
        ]);

        $response->assertStatus(201);
    }

    public function test_ingest_defaults_to_public_sensitivity(): void
    {
        // shouldIgnoreMissing() lets storeMemory() be called silently (default=public triggers it).
        // The assertion here is on node sensitivity, not on whether storeMemory was called.
        $this->mockIcp();
        $this->mockExtractor();

        $this->withSession([
            'chat_user_id'    => 'user-1',
            'chat_session_id' => 'session-1',
        ])->postJson('/api/documents/ingest', [
            'title' => 'Default Sensitivity',
            'text'  => $this->sampleText(),
        ]);

        $nodes = MemoryNode::where('user_id', 'user-1')->get();
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $this->assertSame('public', $node->sensitivity, "Expected public but got {$node->sensitivity} for node '{$node->label}'");
        }
    }

    // ── GET /api/documents ────────────────────────────────────────────────────

    public function test_index_requires_session(): void
    {
        $response = $this->getJson('/api/documents');
        $response->assertStatus(422);
    }

    public function test_index_returns_only_anchor_nodes(): void
    {
        // Anchor node — should appear in the list.
        MemoryNode::create([
            'user_id'     => 'user-1',
            'type'        => 'document',
            'sensitivity' => 'public',
            'label'       => 'Anchor Node',
            'content'     => 'Document: Anchor Node.',
            'tags'        => [],
            'confidence'  => 1.0,
            'source'      => 'document_anchor',
        ]);

        // Chunk the LLM classified as type='document' — must NOT appear in the list.
        MemoryNode::create([
            'user_id'     => 'user-1',
            'type'        => 'document',
            'sensitivity' => 'public',
            'label'       => 'Chunk Classified As Document',
            'content'     => 'Some chunk content the extractor classified as a document node.',
            'tags'        => ['document'],
            'confidence'  => 1.0,
            'source'      => 'document',
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/documents');

        $response->assertOk();
        $documents = $response->json('documents');
        $this->assertCount(1, $documents);
        $this->assertSame('Anchor Node', $documents[0]['label']);
    }

    public function test_index_excludes_other_users_documents(): void
    {
        MemoryNode::create([
            'user_id'     => 'user-other',
            'type'        => 'document',
            'sensitivity' => 'public',
            'label'       => 'Other User Doc',
            'content'     => 'Document: Other User Doc.',
            'tags'        => [],
            'confidence'  => 1.0,
            'source'      => 'document_anchor',
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/documents');

        $response->assertOk();
        $this->assertCount(0, $response->json('documents'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build an ICP mock that handles Inertia middleware's mode() call silently.
     * shouldIgnoreMissing() absorbs any unexpected method calls so tests focused
     * on other behaviour are not broken by middleware side-effects.
     */
    private function mockIcp(): void
    {
        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $this->app->instance(IcpMemoryService::class, $icp);
    }

    private function mockExtractor(): void
    {
        $extractor = Mockery::mock(GraphExtractionService::class);
        $extractor->shouldReceive('extract')->andReturnUsing(
            fn (string $content, string $sensitivity) => [
                'type'        => 'concept',
                'label'       => mb_substr($content, 0, 40),
                'tags'        => ['goal', 'memory'],
                'people'      => [],
                'projects'    => [],
                'sensitivity' => $sensitivity,
            ]
        );
        $this->app->instance(GraphExtractionService::class, $extractor);
    }

    private function sampleText(): string
    {
        return implode("\n\n", [
            'The first goal is to build a personal AI memory system that persists across tools and sessions without relying on vendor-controlled storage.',
            'The second goal is to make memory portable via open protocols so that any AI client can read and write to the same knowledge graph.',
            'The third goal is to demonstrate that Physarum polycephalum dynamics produce scale-free network topology in discrete graph structures.',
        ]);
    }
}
