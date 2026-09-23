<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\ClusterDetectionService;
use App\Services\ConsolidationService;
use App\Services\LLM\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Correctness tests for the episodic-to-semantic consolidation pipeline.
 *
 * ConsolidationService compresses dense episodic clusters into semantic concept
 * nodes when two conditions hold:
 *   - Mean internal edge weight >= 0.30  (cluster has been frequently co-activated)
 *   - Cluster size >= 5 unconsolidated nodes
 *
 * The LLM is mocked to return a fixed summary string. ClusterDetectionService
 * is mocked to return controlled cluster payloads so tests are deterministic.
 *
 * What is verified here:
 *   - Clusters below size threshold are skipped
 *   - Clusters below weight threshold are skipped
 *   - Qualifying clusters produce exactly one concept node
 *   - All episodic nodes receive consolidated_at
 *   - supersedes edges connect the concept to all absorbed nodes
 *   - External edges are re-wired to the concept node
 *   - Already-consolidated nodes do not enter a second consolidation pass
 */
class ConsolidationServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = Str::uuid()->toString();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCluster(int $size, float $meanWeight): array
    {
        $nodes = [];
        for ($i = 0; $i < $size; $i++) {
            $nodes[] = MemoryNode::create([
                'user_id'     => $this->userId,
                'type'        => 'memory',
                'sensitivity' => 'public',
                'label'       => "Episodic node {$i}",
                'content'     => "Fact number {$i} about the topic.",
                'tags'        => ['topic'],
                'confidence'  => 1.0,
                'source'      => 'chat',
            ]);
        }

        // Wire fully-connected internal edges at $meanWeight.
        for ($i = 0; $i < count($nodes); $i++) {
            for ($j = $i + 1; $j < count($nodes); $j++) {
                MemoryEdge::create([
                    'user_id'      => $this->userId,
                    'from_node_id' => $nodes[$i]->id,
                    'to_node_id'   => $nodes[$j]->id,
                    'relationship' => 'same_topic_as',
                    'weight'       => $meanWeight,
                ]);
            }
        }

        return $nodes;
    }

    private function service(array $clusters, string $llmSummary = 'Consolidated concept.'): ConsolidationService
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->andReturn($llmSummary);
        $this->app->instance(LlmService::class, $llm);

        $detector = Mockery::mock(ClusterDetectionService::class);
        $detector->shouldReceive('detect')->with($this->userId)->andReturn($clusters);
        $this->app->instance(ClusterDetectionService::class, $detector);

        return app(ConsolidationService::class);
    }

    private function clusterPayload(array $nodes, float $meanWeight): array
    {
        return [
            'node_ids'    => array_column($nodes, null, 'id') ? array_map(fn ($n) => $n->id, $nodes) : [],
            'node_count'  => count($nodes),
            'mean_weight' => $meanWeight,
        ];
    }

    // ── Threshold tests ───────────────────────────────────────────────────────

    public function test_cluster_below_minimum_size_is_not_consolidated(): void
    {
        $nodes = $this->makeCluster(4, 0.5); // size 4 < MIN_CLUSTER_SIZE 5

        $result = $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $this->assertSame(0, $result['clusters_consolidated']);
        $this->assertSame(0, $result['concept_nodes_created']);
        $this->assertDatabaseCount('memory_nodes', 4);
    }

    public function test_cluster_below_weight_threshold_is_not_consolidated(): void
    {
        $nodes = $this->makeCluster(5, 0.2); // mean weight 0.2 < MIN_WEIGHT 0.3

        $result = $this->service([$this->clusterPayload($nodes, 0.2)])
            ->consolidate($this->userId);

        $this->assertSame(0, $result['clusters_consolidated']);
        $this->assertDatabaseCount('memory_nodes', 5);
    }

    // ── Consolidation correctness ─────────────────────────────────────────────

    public function test_qualifying_cluster_creates_one_concept_node(): void
    {
        $nodes = $this->makeCluster(5, 0.5);

        $result = $this->service([$this->clusterPayload($nodes, 0.5)], 'User focuses on graph work.')
            ->consolidate($this->userId);

        $this->assertSame(1, $result['clusters_consolidated']);
        $this->assertSame(1, $result['concept_nodes_created']);
        $this->assertSame(5, $result['nodes_consolidated']);

        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => $this->userId,
            'type'    => 'concept',
            'content' => 'User focuses on graph work.',
            'source'  => 'consolidated',
        ]);
    }

    public function test_all_episodic_nodes_receive_consolidated_at_timestamp(): void
    {
        $nodes = $this->makeCluster(5, 0.5);

        $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        foreach ($nodes as $node) {
            $this->assertNotNull(
                $node->fresh()->consolidated_at,
                "Node {$node->id} should have consolidated_at set after consolidation."
            );
        }
    }

    public function test_concept_node_has_supersedes_edge_to_every_absorbed_node(): void
    {
        $nodes = $this->makeCluster(5, 0.5);

        $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $concept = MemoryNode::where('user_id', $this->userId)
            ->where('type', 'concept')
            ->first();

        $this->assertNotNull($concept);

        foreach ($nodes as $node) {
            $this->assertDatabaseHas('memory_edges', [
                'user_id'      => $this->userId,
                'from_node_id' => $concept->id,
                'to_node_id'   => $node->id,
                'relationship' => 'supersedes',
            ]);
        }
    }

    public function test_external_edge_is_rewired_to_concept_node(): void
    {
        $nodes = $this->makeCluster(5, 0.5);

        // One external node connected to the cluster.
        $external = MemoryNode::create([
            'user_id'     => $this->userId,
            'type'        => 'person',
            'sensitivity' => 'public',
            'label'       => 'External person',
            'content'     => 'Person anchor.',
            'tags'        => ['person'],
            'confidence'  => 1.0,
            'source'      => 'extracted',
        ]);

        MemoryEdge::create([
            'user_id'      => $this->userId,
            'from_node_id' => $nodes[0]->id,
            'to_node_id'   => $external->id,
            'relationship' => 'about_person',
            'weight'       => 0.8,
        ]);

        $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $concept = MemoryNode::where('user_id', $this->userId)
            ->where('type', 'concept')
            ->first();

        $this->assertDatabaseHas('memory_edges', [
            'user_id'      => $this->userId,
            'from_node_id' => $concept->id,
            'to_node_id'   => $external->id,
            'relationship' => 'about_person',
        ]);
    }

    public function test_already_consolidated_nodes_are_excluded_from_re_consolidation(): void
    {
        $nodes = $this->makeCluster(5, 0.5);

        // Pre-mark all as consolidated.
        foreach ($nodes as $node) {
            $node->update(['consolidated_at' => now()]);
        }

        $result = $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $this->assertSame(0, $result['clusters_consolidated'],
            'Clusters whose nodes are all already consolidated must be skipped.');
    }

    public function test_partially_consolidated_cluster_below_threshold_is_skipped(): void
    {
        $nodes = $this->makeCluster(6, 0.5);

        // Mark two nodes as already consolidated — leaves 4 unconsolidated, below MIN 5.
        $nodes[0]->update(['consolidated_at' => now()]);
        $nodes[1]->update(['consolidated_at' => now()]);

        $result = $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $this->assertSame(0, $result['clusters_consolidated'],
            'Only 4 unconsolidated nodes remain — below the minimum of 5.');
    }

    public function test_sensitive_nodes_do_not_enter_model_consolidation(): void
    {
        // Mix of public and sensitive nodes in the cluster.
        $nodes = [];
        for ($i = 0; $i < 5; $i++) {
            $sensitivity = $i === 2 ? 'sensitive' : 'public';
            $nodes[] = MemoryNode::create([
                'user_id'     => $this->userId,
                'type'        => 'memory',
                'sensitivity' => $sensitivity,
                'label'       => "Node {$i}",
                'content'     => "Content {$i}.",
                'tags'        => [],
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

        $this->service([$this->clusterPayload($nodes, 0.5)])
            ->consolidate($this->userId);

        $concept = MemoryNode::where('user_id', $this->userId)
            ->where('type', 'concept')
            ->first();

        $this->assertNull($concept);
        $this->assertNull($nodes[2]->fresh()->consolidated_at);
    }
}
