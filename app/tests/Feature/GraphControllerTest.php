<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_page_loads(): void
    {
        $this->withSession(['chat_user_id' => 'user-1'])
            ->get('/graph')
            ->assertOk();
    }

    public function test_graph_api_uses_session_user_and_request_filters(): void
    {
        $memory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Visible memory',
            'content' => 'Visible memory',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $person = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'person',
            'sensitivity' => 'public',
            'label' => 'Visible person',
            'content' => 'Visible person',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $privateMemory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Hidden memory',
            'content' => 'Hidden memory',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $otherUser = MemoryNode::create([
            'user_id' => 'user-2',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other user',
            'content' => 'Other user',
            'tags' => ['gamma'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $memory->id,
            'to_node_id' => $person->id,
            'relationship' => 'about_person',
            'weight' => 0.9,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $memory->id,
            'to_node_id' => $privateMemory->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-2',
            'from_node_id' => $otherUser->id,
            'to_node_id' => $otherUser->id,
            'relationship' => 'related_to',
            'weight' => 0.1,
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/graph?types[]=memory&sensitivity[]=public');

        $response->assertOk();
        $response->assertJsonCount(1, 'nodes');
        $response->assertJsonCount(0, 'edges');
        $response->assertJsonPath('nodes.0.id', $memory->id);
    }

    public function test_graph_neighborhood_hides_private_neighbors_by_default(): void
    {
        $root = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Public root',
            'content' => 'Public root',
            'tags' => ['root'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $private = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private node',
            'content' => 'Private node',
            'tags' => ['private'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $root->id,
            'to_node_id' => $private->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson("/api/graph/neighborhood/{$root->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'nodes');
        $response->assertJsonCount(0, 'edges');
    }

    public function test_graph_neighborhood_returns_not_found_for_other_users_node(): void
    {
        $node = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'User one node',
            'content' => 'User one node',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $this->withSession(['chat_user_id' => 'user-2'])
            ->getJson("/api/graph/neighborhood/{$node->id}")
            ->assertNotFound();
    }

    public function test_graph_simulate_reinforces_active_edges_and_returns_updated_weights(): void
    {
        $first = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'First',
            'content' => 'First memory',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $second = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Second',
            'content' => 'Second memory',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $otherUser = MemoryNode::create([
            'user_id' => 'user-2',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other',
            'content' => 'Other memory',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $edge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $first->id,
            'to_node_id' => $second->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-2',
            'from_node_id' => $otherUser->id,
            'to_node_id' => $otherUser->id,
            'relationship' => 'related_to',
            'weight' => 0.4,
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->postJson('/api/graph/simulate');

        $response->assertOk();
        $response->assertJsonCount(2, 'active_node_ids');
        $response->assertJsonCount(1, 'updated_edges');
        $response->assertJsonPath('updated_edges.0.id', $edge->id);
        $response->assertJsonPath('updated_edges.0.weight', 0.6);

        $edge->refresh();
        $this->assertEqualsWithDelta(0.6, $edge->weight, 0.0001);
    }
}
