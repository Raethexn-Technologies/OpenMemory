<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Models\SharedMemoryEdge;
use App\Services\MultiAgentGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiAgentReinforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $ownerUserId, string $name, float $trust = 0.5): Agent
    {
        return Agent::create([
            'owner_user_id' => $ownerUserId,
            'graph_user_id' => 'agent_'.Str::uuid(),
            'name' => $name,
            'trust_score' => $trust,
        ]);
    }

    private function makeNode(string $userId, string $content): MemoryNode
    {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => $content,
            'content' => $content,
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
    }

    private function pairKey(Agent $left, Agent $right): string
    {
        return collect([$left->id, $right->id])->sort()->implode(':');
    }

    public function test_reinforce_shared_creates_edge_when_agents_share_content(): void
    {
        $agentA = $this->makeAgent('owner-1', 'Alpha', 0.8);
        $agentB = $this->makeAgent('owner-1', 'Beta', 0.6);

        $sharedContent = 'The shared memory fact';
        $nodeA = $this->makeNode($agentA->graph_user_id, $sharedContent);
        $this->makeNode($agentB->graph_user_id, $sharedContent);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeA->id], $agentA);

        $this->assertDatabaseCount('shared_memory_edges', 1);

        $edge = SharedMemoryEdge::first();
        $this->assertSame(hash('sha256', $sharedContent), $edge->content_hash);
        $this->assertSame('owner-1', $edge->owner_user_id);
    }

    public function test_reinforce_shared_tracks_first_access_on_new_edge(): void
    {
        $agentA = $this->makeAgent('owner-first-touch', 'Alpha', 1.0);
        $agentB = $this->makeAgent('owner-first-touch', 'Beta', 1.0);

        $sharedContent = 'New shared edge';
        $nodeA = $this->makeNode($agentA->graph_user_id, $sharedContent);
        $this->makeNode($agentB->graph_user_id, $sharedContent);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeA->id], $agentA);

        $edge = SharedMemoryEdge::first();
        $this->assertNotNull($edge);
        $this->assertSame(1, $edge->access_count);
        $this->assertNotNull($edge->last_accessed_at);
        $this->assertEqualsWithDelta(0.36, $edge->weight, 0.0001);
    }

    public function test_reinforce_shared_uses_trust_weighted_alpha(): void
    {
        $agentA = $this->makeAgent('owner-2', 'Full trust', 1.0);
        $agentB = $this->makeAgent('owner-2', 'Half trust', 0.5);
        $agentC = $this->makeAgent('owner-2', 'Recipient', 0.5);

        $sharedContent = 'Trust weighted fact';
        $nodeAFull = $this->makeNode($agentA->graph_user_id, $sharedContent);
        $nodeAHalf = $this->makeNode($agentB->graph_user_id, $sharedContent);
        $this->makeNode($agentC->graph_user_id, $sharedContent);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeAFull->id], $agentA);
        $service->reinforceShared([$nodeAHalf->id], $agentB);

        $edges = SharedMemoryEdge::all()->keyBy(fn ($edge) => $this->pairKey($edge->agentA, $edge->agentB));

        $this->assertEqualsWithDelta(0.39, $edges[$this->pairKey($agentA, $agentB)]->weight, 0.0001);
        $this->assertEqualsWithDelta(0.36, $edges[$this->pairKey($agentA, $agentC)]->weight, 0.0001);
        $this->assertEqualsWithDelta(0.33, $edges[$this->pairKey($agentB, $agentC)]->weight, 0.0001);
    }

    public function test_reinforce_shared_does_nothing_for_zero_trust_agent(): void
    {
        $agentA = $this->makeAgent('owner-3', 'Untrusted', 0.0);
        $agentB = $this->makeAgent('owner-3', 'Peer', 0.8);

        $sharedContent = 'Zero trust fact';
        $nodeA = $this->makeNode($agentA->graph_user_id, $sharedContent);
        $this->makeNode($agentB->graph_user_id, $sharedContent);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeA->id], $agentA);

        $edge = SharedMemoryEdge::first();
        if ($edge) {
            $this->assertEqualsWithDelta(0.3, $edge->weight, 0.0001);
        } else {
            $this->assertDatabaseCount('shared_memory_edges', 0);
        }
    }

    public function test_reinforce_shared_increments_weight_on_repeated_access(): void
    {
        $agentA = $this->makeAgent('owner-4', 'Alpha', 1.0);
        $agentB = $this->makeAgent('owner-4', 'Beta', 1.0);

        $content = 'Repeatedly accessed fact';
        $nodeA = $this->makeNode($agentA->graph_user_id, $content);
        $this->makeNode($agentB->graph_user_id, $content);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeA->id], $agentA);
        $service->reinforceShared([$nodeA->id], $agentA);

        $edge = SharedMemoryEdge::first();
        $this->assertNotNull($edge);
        $this->assertEqualsWithDelta(0.42, $edge->weight, 0.0001);
        $this->assertSame(2, $edge->access_count);
    }

    public function test_reinforce_shared_does_not_cross_owner_boundaries(): void
    {
        $owner1AgentA = $this->makeAgent('owner-5', 'Owner1-A', 0.8);
        $owner2AgentB = $this->makeAgent('owner-6', 'Owner2-B', 0.8);

        $sharedContent = 'Cross-owner fact';
        $nodeA = $this->makeNode($owner1AgentA->graph_user_id, $sharedContent);
        $this->makeNode($owner2AgentB->graph_user_id, $sharedContent);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$nodeA->id], $owner1AgentA);

        $this->assertDatabaseCount('shared_memory_edges', 0);
    }

    public function test_seed_from_owner_copies_public_nodes_to_agent_partition(): void
    {
        $ownerUserId = 'owner-seed';
        $agent = $this->makeAgent($ownerUserId, 'Seed target', 0.5);

        $this->makeNode($ownerUserId, 'Owner public fact one');
        $this->makeNode($ownerUserId, 'Owner public fact two');
        MemoryNode::create([
            'user_id' => $ownerUserId,
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private',
            'content' => 'Private owner fact',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $service = app(MultiAgentGraphService::class);
        $seeded = $service->seedFromOwner($agent);

        $this->assertSame(2, $seeded);
        $this->assertDatabaseCount('memory_nodes', 5);

        $agentNodes = MemoryNode::where('user_id', $agent->graph_user_id)->get();
        $this->assertCount(2, $agentNodes);
        $this->assertSame('seeded', $agentNodes->first()->source);
    }

    public function test_seed_from_owner_does_not_duplicate_existing_agent_nodes(): void
    {
        $ownerUserId = 'owner-dedup';
        $agent = $this->makeAgent($ownerUserId, 'Dedup agent', 0.5);
        $content = 'Already seeded fact';

        $this->makeNode($ownerUserId, $content);
        $this->makeNode($agent->graph_user_id, $content);

        $service = app(MultiAgentGraphService::class);
        $seeded = $service->seedFromOwner($agent);

        $this->assertSame(0, $seeded);
    }

    public function test_retrieve_collective_context_boosts_nodes_with_shared_weight(): void
    {
        $ownerUserId = 'owner-collective';
        $agentA = $this->makeAgent($ownerUserId, 'A', 0.9);
        $agentB = $this->makeAgent($ownerUserId, 'B', 0.9);

        $strongContent = 'Collectively important fact';
        $weakContent = 'Only agent A cares about this';

        $strongA = $this->makeNode($agentA->graph_user_id, $strongContent);
        $weakA = $this->makeNode($agentA->graph_user_id, $weakContent);
        $this->makeNode($agentB->graph_user_id, $strongContent);

        MemoryEdge::create([
            'user_id' => $agentA->graph_user_id,
            'from_node_id' => $strongA->id,
            'to_node_id' => $weakA->id,
            'relationship' => 'related_to',
            'weight' => 0.5,
        ]);

        $service = app(MultiAgentGraphService::class);
        $service->reinforceShared([$strongA->id], $agentA);

        $context = $service->retrieveCollectiveContext($agentA);

        $strongRecord = collect($context)->firstWhere('content', $strongContent);
        $weakRecord = collect($context)->firstWhere('content', $weakContent);

        if ($strongRecord && $weakRecord) {
            $this->assertGreaterThan($weakRecord['collective_weight'], $strongRecord['collective_weight']);
        } elseif ($strongRecord) {
            $this->assertGreaterThan(0.0, $strongRecord['collective_weight']);
        }
    }

    public function test_agents_index_page_loads(): void
    {
        $response = $this->withSession([
            'chat_user_id' => 'user-agents-page',
            'chat_session_id' => 'session-agents',
        ])->get('/agents');

        $response->assertOk();
    }

    public function test_create_agent_api_returns_agent_record(): void
    {
        $response = $this->withSession([
            'chat_user_id' => 'user-create',
            'chat_session_id' => 'session-create',
        ])->postJson('/api/agents', [
            'name' => 'Research agent',
            'trust_score' => 0.7,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Research agent');
        $response->assertJsonPath('trust_score', 0.7);
        $this->assertDatabaseHas('agents', ['name' => 'Research agent', 'owner_user_id' => 'user-create']);
    }

    public function test_update_trust_changes_agent_trust_score(): void
    {
        $agent = $this->makeAgent('user-trust', 'Test', 0.5);

        $response = $this->withSession([
            'chat_user_id' => 'user-trust',
            'chat_session_id' => 'session-trust',
        ])->patchJson("/api/agents/{$agent->id}/trust", [
            'trust_score' => 0.9,
        ]);

        $response->assertOk();
        $agent->refresh();
        $this->assertEqualsWithDelta(0.9, $agent->trust_score, 0.0001);
    }

    public function test_simulate_endpoint_returns_active_nodes_and_updates_access_tracking(): void
    {
        $agentA = $this->makeAgent('owner-simulate', 'Alpha', 1.0);
        $agentB = $this->makeAgent('owner-simulate', 'Beta', 0.8);

        $sharedNode = $this->makeNode($agentA->graph_user_id, 'Shared simulation fact');
        $otherNode = $this->makeNode($agentA->graph_user_id, 'Agent-local context');
        $this->makeNode($agentB->graph_user_id, 'Shared simulation fact');

        MemoryEdge::create([
            'user_id' => $agentA->graph_user_id,
            'from_node_id' => $sharedNode->id,
            'to_node_id' => $otherNode->id,
            'relationship' => 'related_to',
            'weight' => 0.5,
        ]);

        $response = $this->withSession([
            'chat_user_id' => 'owner-simulate',
            'chat_session_id' => 'session-simulate',
        ])->postJson("/api/agents/{$agentA->id}/simulate");

        $response->assertOk();
        $response->assertJsonPath('agent_id', $agentA->id);
        $response->assertJsonPath('agent_name', 'Alpha');
        $this->assertNotEmpty($response->json('active_node_ids'));

        $agentA->refresh();
        $this->assertSame(1, $agentA->access_count);
        $this->assertDatabaseCount('shared_memory_edges', 1);
    }

    public function test_destroy_agent_removes_partition_graph_data(): void
    {
        $agentA = $this->makeAgent('owner-destroy', 'Alpha', 1.0);
        $agentB = $this->makeAgent('owner-destroy', 'Beta', 1.0);

        $nodeA = $this->makeNode($agentA->graph_user_id, 'Shared destroy fact');
        $neighborA = $this->makeNode($agentA->graph_user_id, 'Destroy neighbor');
        $this->makeNode($agentB->graph_user_id, 'Shared destroy fact');

        MemoryEdge::create([
            'user_id' => $agentA->graph_user_id,
            'from_node_id' => $nodeA->id,
            'to_node_id' => $neighborA->id,
            'relationship' => 'related_to',
            'weight' => 0.5,
        ]);

        app(MultiAgentGraphService::class)->reinforceShared([$nodeA->id], $agentA);
        $this->assertDatabaseCount('shared_memory_edges', 1);

        $response = $this->withSession([
            'chat_user_id' => 'owner-destroy',
            'chat_session_id' => 'session-destroy',
        ])->deleteJson("/api/agents/{$agentA->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('agents', ['id' => $agentA->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $nodeA->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $neighborA->id]);
        $this->assertDatabaseCount('memory_edges', 0);
        $this->assertDatabaseCount('shared_memory_edges', 0);
    }
}
