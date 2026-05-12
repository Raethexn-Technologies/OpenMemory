<?php

namespace Tests\Feature;

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
}
