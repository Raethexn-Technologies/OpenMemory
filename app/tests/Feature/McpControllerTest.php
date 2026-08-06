<?php

namespace Tests\Feature;

use App\Models\MemoryNode;
use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class McpControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_mcp_store_redacts_bank_details_and_demotes_to_private(): void
    {
        config(['services.mcp.api_key' => 'test-key']);

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('mode')->andReturn('mock');
        $icp->shouldReceive('storeMemory')
            ->once()
            ->withArgs(function (string $userId, string $sessionId, string $content, ?string $metadata, string $sensitivity) {
                return $userId === 'user-mcp'
                    && str_starts_with($sessionId, 'mcp-user-mcp-')
                    && ! str_contains($content, '021000021')
                    && str_contains($content, 'BANK_ROUTING#')
                    && $metadata !== null
                    && str_contains($metadata, 'bank_routing')
                    && $sensitivity === 'private';
            })
            ->andReturn('mcp-memory-1');
        $this->app->instance(IcpMemoryService::class, $icp);

        $extractor = Mockery::mock(GraphExtractionService::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->with(
                Mockery::on(fn (string $content) => ! str_contains($content, '021000021') && str_contains($content, 'BANK_ROUTING#')),
                'private',
            )
            ->andReturn([
                'type' => 'memory',
                'label' => 'Bank routing placeholder',
                'tags' => ['banking'],
                'people' => [],
                'projects' => [],
                'sensitivity' => 'private',
            ]);
        $this->app->instance(GraphExtractionService::class, $extractor);

        $response = $this
            ->withHeader('X-OMA-API-Key', 'test-key')
            ->postJson('/mcp/store', [
                'content' => 'Use routing number 021000021 for reimbursement.',
                'sensitivity' => 'public',
                'user_id' => 'user-mcp',
                'context' => 'cli-write',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('id', 'mcp-memory-1');
        $response->assertJsonPath('sensitivity', 'private');
        $response->assertJsonPath('redaction.applied', true);
        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => 'user-mcp',
            'label' => 'Bank routing placeholder',
            'sensitivity' => 'private',
        ]);
        $this->assertStringNotContainsString('021000021', \App\Models\MemoryNode::first()->content);
    }

    public function test_mcp_prepare_redacts_before_a_live_client_signs_the_record(): void
    {
        config(['services.mcp.api_key' => 'test-key']);

        $response = $this
            ->withHeader('X-OMA-API-Key', 'test-key')
            ->postJson('/mcp/prepare', [
                'content' => 'Use routing number 021000021 for reimbursement.',
                'sensitivity' => 'public',
                'user_id' => 'principal-mcp',
            ]);

        $response->assertOk();
        $response->assertJsonPath('sensitivity', 'private');
        $response->assertJsonPath('redaction.applied', true);
        $this->assertStringNotContainsString('021000021', (string) $response->json('content'));
        $this->assertDatabaseCount('memory_nodes', 0);
    }

    public function test_mcp_search_returns_only_public_query_matches_without_recency_fallback(): void
    {
        config(['services.mcp.api_key' => 'test-key']);

        $matching = MemoryNode::create([
            'user_id' => 'user-mcp',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Postgres migration',
            'content' => 'The Postgres migration uses a reversible cutover.',
            'tags' => ['postgres', 'migration'],
            'source' => 'mcp',
        ]);
        MemoryNode::create([
            'user_id' => 'user-mcp',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private Postgres password',
            'content' => 'The Postgres password is private.',
            'tags' => ['postgres'],
            'source' => 'mcp',
        ]);
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other migration',
            'content' => 'Another Postgres migration.',
            'tags' => ['postgres'],
            'source' => 'mcp',
        ]);
        MemoryNode::create([
            'user_id' => 'user-mcp',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Unrelated incident',
            'content' => 'The incident review is complete.',
            'tags' => ['incident'],
            'source' => 'mcp',
        ]);

        $response = $this
            ->withHeader('X-OMA-API-Key', 'test-key')
            ->postJson('/mcp/search', [
                'user_id' => 'user-mcp',
                'query' => 'What is the Postgres migration plan?',
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'records');
        $response->assertJsonPath('records.0.id', $matching->id);

        $this
            ->withHeader('X-OMA-API-Key', 'test-key')
            ->postJson('/mcp/search', [
                'user_id' => 'user-mcp',
                'query' => 'kubernetes ingress controller',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'records');
    }
}
