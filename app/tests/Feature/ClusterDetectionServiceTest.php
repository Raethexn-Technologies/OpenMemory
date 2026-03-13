<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\ClusterDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClusterDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClusterDetectionService::class);
    }

    private function node(string $userId, string $label): MemoryNode
    {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => $label,
            'content' => $label,
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
    }

    private function edge(string $userId, string $from, string $to, float $weight = 0.5): MemoryEdge
    {
        return MemoryEdge::create([
            'user_id' => $userId,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'relationship' => 'same_topic_as',
            'weight' => $weight,
        ]);
    }

    public function test_empty_graph_returns_empty_clusters(): void
    {
        $result = $this->service->detect('user-empty');

        $this->assertSame([], $result);
    }

    public function test_single_node_forms_its_own_cluster(): void
    {
        $n = $this->node('user-1', 'Solo');

        $clusters = $this->service->detect('user-1');

        $this->assertCount(1, $clusters);
        $this->assertContains($n->id, $clusters[0]['node_ids']);
        $this->assertSame(1, $clusters[0]['node_count']);
        $this->assertSame(0.0, $clusters[0]['mean_weight']);
    }

    public function test_two_connected_nodes_form_one_cluster(): void
    {
        $a = $this->node('user-1', 'Alpha');
        $b = $this->node('user-1', 'Beta');
        $this->edge('user-1', $a->id, $b->id, 0.8);

        $clusters = $this->service->detect('user-1');

        $this->assertCount(1, $clusters);
        $this->assertCount(2, $clusters[0]['node_ids']);
    }

    public function test_disconnected_components_stay_separate(): void
    {
        $a = $this->node('user-1', 'A');
        $b = $this->node('user-1', 'B');
        $c = $this->node('user-1', 'C');
        $d = $this->node('user-1', 'D');

        // A-B connected, C-D connected, nothing between the two pairs
        $this->edge('user-1', $a->id, $b->id, 0.9);
        $this->edge('user-1', $c->id, $d->id, 0.9);

        $clusters = $this->service->detect('user-1');

        $this->assertCount(2, $clusters);
        $sizes = array_map(fn ($c) => $c['node_count'], $clusters);
        sort($sizes);
        $this->assertSame([2, 2], $sizes);
    }

    public function test_cluster_mean_weight_reflects_internal_edge_weights(): void
    {
        $a = $this->node('user-1', 'A');
        $b = $this->node('user-1', 'B');
        $c = $this->node('user-1', 'C');

        $this->edge('user-1', $a->id, $b->id, 0.6);
        $this->edge('user-1', $b->id, $c->id, 0.4);

        $clusters = $this->service->detect('user-1');

        // All three should form one cluster; mean internal weight = (0.6 + 0.4) / 2 = 0.5
        $this->assertCount(1, $clusters);
        $this->assertEqualsWithDelta(0.5, $clusters[0]['mean_weight'], 0.001);
    }

    public function test_detect_is_deterministic_for_the_same_graph_state(): void
    {
        $a = $this->node('user-det', 'A');
        $b = $this->node('user-det', 'B');
        $c = $this->node('user-det', 'C');
        $d = $this->node('user-det', 'D');

        $this->edge('user-det', $a->id, $b->id, 0.9);
        $this->edge('user-det', $b->id, $c->id, 0.8);
        $this->edge('user-det', $c->id, $d->id, 0.9);

        $first = $this->service->detect('user-det');
        $second = $this->service->detect('user-det');

        $normalize = fn (array $clusters) => collect($clusters)
            ->map(fn ($cluster) => [
                'id' => $cluster['id'],
                'node_ids' => collect($cluster['node_ids'])->sort()->values()->all(),
                'node_count' => $cluster['node_count'],
                'mean_weight' => $cluster['mean_weight'],
            ])
            ->sortBy('id')
            ->values()
            ->all();

        $this->assertSame($normalize($first), $normalize($second));
    }

    public function test_nodes_belonging_to_other_users_are_excluded(): void
    {
        $this->node('user-1', 'Mine');
        $this->node('user-2', 'Theirs');

        $clusters1 = $this->service->detect('user-1');
        $clusters2 = $this->service->detect('user-2');

        $this->assertCount(1, $clusters1);
        $this->assertCount(1, $clusters2);
        $this->assertNotEquals($clusters1[0]['node_ids'], $clusters2[0]['node_ids']);
    }

    public function test_clusters_api_endpoint_returns_correct_shape(): void
    {
        $this->node('user-1', 'Node A');

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/graph/clusters');

        $response->assertOk();
        $response->assertJsonStructure([
            'clusters' => [
                '*' => ['id', 'node_ids', 'node_count', 'mean_weight'],
            ],
        ]);
    }

    public function test_clusters_api_returns_empty_for_user_with_no_nodes(): void
    {
        $response = $this->withSession(['chat_user_id' => 'user-nobody'])
            ->getJson('/api/graph/clusters');

        $response->assertOk();
        $response->assertJsonPath('clusters', []);
    }
}
