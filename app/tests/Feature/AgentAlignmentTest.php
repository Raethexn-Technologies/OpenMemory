<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $ownerUserId, string $name, float $trust = 0.5): Agent
    {
        return Agent::create([
            'owner_user_id' => $ownerUserId,
            'graph_user_id' => 'agent_' . Str::uuid(),
            'name' => $name,
            'trust_score' => $trust,
        ]);
    }

    private function node(string $userId, string $content, string $label = 'Node'): MemoryNode
    {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => $label,
            'content' => $content,
            'tags' => ['test'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
    }

    private function edge(string $userId, string $from, string $to, float $weight = 0.8): void
    {
        MemoryEdge::create([
            'user_id' => $userId,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'relationship' => 'same_topic_as',
            'weight' => $weight,
        ]);
    }

    public function test_alignment_endpoint_returns_pairs_array(): void
    {
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $response->assertJsonStructure(['pairs']);
    }

    public function test_alignment_returns_empty_pairs_with_no_agents(): void
    {
        $response = $this->withSession(['chat_user_id' => 'owner-nobody'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $response->assertJsonPath('pairs', []);
    }

    public function test_alignment_returns_empty_pairs_with_single_agent(): void
    {
        $this->agent('owner-1', 'Solo');

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $response->assertJsonPath('pairs', []);
    }

    public function test_alignment_returns_one_pair_for_two_agents(): void
    {
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');

        // Give each agent their own nodes
        $n1 = $this->node($a->graph_user_id, 'Alpha fact one');
        $n2 = $this->node($a->graph_user_id, 'Alpha fact two');
        $this->edge($a->graph_user_id, $n1->id, $n2->id, 0.9);

        $n3 = $this->node($b->graph_user_id, 'Beta fact one');
        $n4 = $this->node($b->graph_user_id, 'Beta fact two');
        $this->edge($b->graph_user_id, $n3->id, $n4->id, 0.9);

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $response->assertJsonCount(1, 'pairs');
        $pair = $response->json('pairs.0');
        $this->assertContains($pair['agent_a_name'], ['Alpha', 'Beta']);
        $this->assertContains($pair['agent_b_name'], ['Alpha', 'Beta']);
    }

    public function test_alignment_jaccard_is_0_for_disjoint_agent_partitions(): void
    {
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');

        // Each agent has completely separate nodes — no UUID overlap possible
        $n1 = $this->node($a->graph_user_id, 'Alpha only');
        $n2 = $this->node($a->graph_user_id, 'Alpha only 2');
        $this->edge($a->graph_user_id, $n1->id, $n2->id, 0.9);

        $n3 = $this->node($b->graph_user_id, 'Beta only');
        $n4 = $this->node($b->graph_user_id, 'Beta only 2');
        $this->edge($b->graph_user_id, $n3->id, $n4->id, 0.9);

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        // Node UUIDs are distinct per partition so intersection is empty; jaccard = 0
        $this->assertEqualsWithDelta(0.0, $response->json('pairs.0.jaccard'), 0.0001);
    }

    public function test_alignment_jaccard_is_0_when_both_agents_have_empty_context(): void
    {
        $this->agent('owner-1', 'Empty Alpha');
        $this->agent('owner-1', 'Empty Beta');

        // No nodes, no edges — retrieveContext returns [] for both agents
        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $this->assertEqualsWithDelta(0.0, $response->json('pairs.0.jaccard'), 0.0001);
    }

    public function test_alignment_jaccard_reflects_shared_content_across_agent_partitions(): void
    {
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');

        $aShared = $this->node($a->graph_user_id, 'Shared fact', 'Shared fact');
        $aOnly = $this->node($a->graph_user_id, 'Alpha only', 'Alpha only');
        $bShared = $this->node($b->graph_user_id, 'Shared fact', 'Shared fact');
        $bOnly = $this->node($b->graph_user_id, 'Beta only', 'Beta only');

        $this->edge($a->graph_user_id, $aShared->id, $aOnly->id, 0.9);
        $this->edge($b->graph_user_id, $bShared->id, $bOnly->id, 0.9);

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $this->assertEqualsWithDelta(0.3333, $response->json('pairs.0.jaccard'), 0.0001);
    }

    public function test_alignment_pairs_are_sorted_descending_by_jaccard(): void
    {
        // Three agents: two with context, one empty — the empty pairings score 0
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');
        $c = $this->agent('owner-1', 'Gamma'); // no nodes — always 0 jaccard with others

        $n1 = $this->node($a->graph_user_id, 'A node');
        $n2 = $this->node($a->graph_user_id, 'A node 2');
        $this->edge($a->graph_user_id, $n1->id, $n2->id, 0.9);

        $n3 = $this->node($b->graph_user_id, 'B node');
        $n4 = $this->node($b->graph_user_id, 'B node 2');
        $this->edge($b->graph_user_id, $n3->id, $n4->id, 0.9);

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $pairs = $response->json('pairs');
        $this->assertCount(3, $pairs); // AB, AC, BC

        // Pairs must be sorted descending
        for ($i = 0; $i < count($pairs) - 1; $i++) {
            $this->assertGreaterThanOrEqual($pairs[$i + 1]['jaccard'], $pairs[$i]['jaccard']);
        }
    }

    public function test_alignment_excludes_agents_from_other_owners(): void
    {
        $this->agent('owner-1', 'Alpha');
        $this->agent('owner-2', 'Intruder');

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        // Only owner-1's agents are returned; with one agent there are no pairs
        $response->assertJsonPath('pairs', []);
    }

    public function test_alignment_response_contains_agent_names(): void
    {
        $a = $this->agent('owner-1', 'Researcher');
        $b = $this->agent('owner-1', 'Analyst');

        $response = $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $response->assertOk();
        $response->assertJsonCount(1, 'pairs');
        $pair = $response->json('pairs.0');
        $this->assertContains($pair['agent_a_name'], ['Researcher', 'Analyst']);
        $this->assertContains($pair['agent_b_name'], ['Researcher', 'Analyst']);
    }

    public function test_alignment_endpoint_is_read_only_and_does_not_reinforce(): void
    {
        $a = $this->agent('owner-1', 'Alpha');
        $b = $this->agent('owner-1', 'Beta');

        $n1 = $this->node($a->graph_user_id, 'Node A');
        $n2 = $this->node($a->graph_user_id, 'Node B');
        $edge = MemoryEdge::create([
            'user_id' => $a->graph_user_id,
            'from_node_id' => $n1->id,
            'to_node_id' => $n2->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $this->withSession(['chat_user_id' => 'owner-1'])
            ->getJson('/api/agents/alignment');

        $edge->refresh();
        $this->assertEqualsWithDelta(0.5, $edge->weight, 0.0001);
    }
}
