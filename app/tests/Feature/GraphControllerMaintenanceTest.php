<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\ClusterDetectionService;
use App\Services\ConsolidationService;
use App\Services\LLM\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * HTTP endpoint tests for the graph maintenance operations:
 *   POST /api/graph/consolidate
 *   POST /api/graph/prune
 *
 * Both endpoints require a session and operate only on the current user's data.
 */
class GraphControllerMaintenanceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = 'user-maintain-' . Str::random(6);
    }

    // ── /api/graph/consolidate ────────────────────────────────────────────────

    public function test_consolidate_returns_result_counts(): void
    {
        $detector = Mockery::mock(ClusterDetectionService::class);
        $detector->shouldReceive('detect')->andReturn([]);
        $this->app->instance(ClusterDetectionService::class, $detector);

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldIgnoreMissing();
        $this->app->instance(LlmService::class, $llm);

        $response = $this->withOwnerSession(['chat_user_id' => $this->userId])
            ->postJson('/api/graph/consolidate');

        $response->assertOk();
        $response->assertJsonStructure([
            'clusters_evaluated',
            'clusters_consolidated',
            'nodes_consolidated',
            'concept_nodes_created',
        ]);
        $response->assertJsonPath('clusters_consolidated', 0);
    }

    public function test_consolidate_creates_concept_node_for_qualifying_cluster(): void
    {
        // Build a cluster of 5 nodes with high internal edge weight.
        $nodes = [];
        for ($i = 0; $i < 5; $i++) {
            $nodes[] = MemoryNode::create([
                'user_id'     => $this->userId,
                'type'        => 'memory',
                'sensitivity' => 'public',
                'label'       => "Node {$i}",
                'content'     => "Fact {$i} about the project.",
                'tags'        => ['project'],
                'confidence'  => 1.0,
                'source'      => 'chat',
            ]);
        }

        for ($i = 0; $i < count($nodes); $i++) {
            for ($j = $i + 1; $j < count($nodes); $j++) {
                MemoryEdge::create([
                    'user_id'      => $this->userId,
                    'from_node_id' => $nodes[$i]->id,
                    'to_node_id'   => $nodes[$j]->id,
                    'relationship' => 'same_topic_as',
                    'weight'       => 0.5,
                ]);
            }
        }

        $detector = Mockery::mock(ClusterDetectionService::class);
        $detector->shouldReceive('detect')->andReturn([[
            'node_ids'    => array_map(fn ($n) => $n->id, $nodes),
            'node_count'  => 5,
            'mean_weight' => 0.5,
        ]]);
        $this->app->instance(ClusterDetectionService::class, $detector);

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->andReturn('User works on the project.');
        $this->app->instance(LlmService::class, $llm);

        $response = $this->withOwnerSession(['chat_user_id' => $this->userId])
            ->postJson('/api/graph/consolidate');

        $response->assertOk();
        $response->assertJsonPath('concept_nodes_created', 1);
        $response->assertJsonPath('nodes_consolidated', 5);

        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => $this->userId,
            'type'    => 'concept',
        ]);
    }

    // ── /api/graph/prune ─────────────────────────────────────────────────────

    public function test_prune_returns_zero_when_no_dormant_nodes_exist(): void
    {
        // A recently accessed node with an active edge — nothing to prune.
        $a = MemoryNode::create([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Active node',
            'content'          => 'Active content.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(5),
        ]);
        $b = MemoryNode::create([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Active node 2',
            'content'          => 'Active content 2.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(5),
        ]);
        MemoryEdge::create([
            'user_id'      => $this->userId,
            'from_node_id' => $a->id,
            'to_node_id'   => $b->id,
            'relationship' => 'same_topic_as',
            'weight'       => 0.6,
        ]);

        $response = $this->withOwnerSession(['chat_user_id' => $this->userId])
            ->postJson('/api/graph/prune');

        $response->assertOk();
        $response->assertJsonPath('nodes_pruned', 0);
        $response->assertJsonPath('edges_pruned', 0);
    }

    public function test_prune_deletes_dormant_nodes_and_returns_correct_counts(): void
    {
        // Two nodes idle 100 days with floor-weight edge.
        $a = MemoryNode::create([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Dormant A',
            'content'          => 'Old content A.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
        ]);
        $b = MemoryNode::create([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Dormant B',
            'content'          => 'Old content B.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
        ]);
        // Set created_at via DB to bypass Eloquent's automatic timestamp management.
        DB::table('memory_nodes')->whereIn('id', [$a->id, $b->id])
            ->update(['created_at' => now()->subDays(100)]);

        MemoryEdge::create([
            'user_id'      => $this->userId,
            'from_node_id' => $a->id,
            'to_node_id'   => $b->id,
            'relationship' => 'same_topic_as',
            'weight'       => 0.05,
        ]);

        $response = $this->withOwnerSession(['chat_user_id' => $this->userId])
            ->postJson('/api/graph/prune');

        $response->assertOk();
        $response->assertJsonPath('nodes_pruned', 2);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $a->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $b->id]);
    }

    public function test_prune_does_not_touch_other_users_data(): void
    {
        // Dormant node for this user — should be pruned.
        $own = MemoryNode::create([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Own dormant',
            'content'          => 'Own content.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
        ]);
        DB::table('memory_nodes')->where('id', $own->id)
            ->update(['created_at' => now()->subDays(100)]);

        // Dormant node for a different user — must be untouched.
        $other = MemoryNode::create([
            'user_id'          => 'different-user',
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Other dormant',
            'content'          => 'Other content.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
        ]);
        DB::table('memory_nodes')->where('id', $other->id)
            ->update(['created_at' => now()->subDays(100)]);

        $this->withOwnerSession(['chat_user_id' => $this->userId])
            ->postJson('/api/graph/prune')
            ->assertOk();

        // The other user's node must still exist — prune is scoped to the session user.
        $this->assertNotNull(MemoryNode::find($other->id),
            'Prune endpoint must only affect the session user\'s data.');
    }
}
