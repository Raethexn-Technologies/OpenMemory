<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GraphControllerAmbientTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambient_caps_each_other_user_at_eight_nodes(): void
    {
        // A prolific other user creates 20 public memory nodes spaced one
        // minute apart. Eloquent overwrites $fillable created_at on create(),
        // so we use Carbon::setTestNow() to advance the clock between writes.
        // The ambient feed must cap any single other user at 8 most-recent.
        $base = Carbon::parse('2026-01-01 00:00:00');
        for ($i = 0; $i < 20; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes($i));
            MemoryNode::create([
                'user_id' => 'prolific-user',
                'type' => 'memory',
                'sensitivity' => 'public',
                'label' => "Prolific {$i}",
                'content' => "Prolific node {$i}",
                'tags' => ['shared'],
                'confidence' => 1.0,
                'source' => 'chat',
            ]);
        }
        Carbon::setTestNow();

        $response = $this->withSession(['chat_user_id' => 'session-user'])
            ->getJson('/api/graph/ambient');

        $response->assertOk();
        $nodes = $response->json('nodes');

        $fromProlific = array_filter($nodes, fn ($n) => str_starts_with($n['label'] ?? '', 'Prolific'));
        $this->assertCount(8, $fromProlific, 'Per-user cap of 8 was not enforced for non-session user.');

        // The 8 that survived should be the most-recent indices (12-19).
        $labels = array_map(fn ($n) => $n['label'], $fromProlific);
        sort($labels);
        $this->assertEquals(
            ['Prolific 12', 'Prolific 13', 'Prolific 14', 'Prolific 15', 'Prolific 16', 'Prolific 17', 'Prolific 18', 'Prolific 19'],
            $labels,
            'The 8 surviving nodes are not the most recent.'
        );
    }

    public function test_ambient_does_not_cap_session_user_until_mine_cap(): void
    {
        // The session user creates 15 public nodes. They should all appear,
        // since MINE_CAP is 50. Every one of them should carry mine: true.
        for ($i = 0; $i < 15; $i++) {
            MemoryNode::create([
                'user_id' => 'session-user',
                'type' => 'memory',
                'sensitivity' => 'public',
                'label' => "Mine {$i}",
                'content' => "My node {$i}",
                'tags' => ['mine'],
                'confidence' => 1.0,
                'source' => 'chat',
            ]);
        }

        $response = $this->withSession(['chat_user_id' => 'session-user'])
            ->getJson('/api/graph/ambient');

        $response->assertOk();
        $nodes = $response->json('nodes');
        $mine = array_filter($nodes, fn ($n) => $n['mine']);

        $this->assertCount(15, $mine, 'Session user nodes were unexpectedly capped below MINE_CAP.');
    }

    public function test_ambient_excludes_private_sensitive_and_consolidated_nodes(): void
    {
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Visible',
            'content' => 'Visible',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private hidden',
            'content' => 'Private hidden',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'sensitive',
            'label' => 'Sensitive hidden',
            'content' => 'Sensitive hidden',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Already consolidated',
            'content' => 'Already consolidated',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
            'consolidated_at' => Carbon::now(),
        ]);

        $response = $this->withSession(['chat_user_id' => 'session-user'])
            ->getJson('/api/graph/ambient');

        $response->assertOk();
        $labels = array_column($response->json('nodes'), 'label');

        $this->assertEquals(['Visible'], $labels, 'Ambient feed leaked a private/sensitive/consolidated node.');
    }

    public function test_ambient_only_returns_edges_between_visible_nodes(): void
    {
        $kept = MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Kept',
            'content' => 'Kept',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $visible = MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Visible',
            'content' => 'Visible',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $private = MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Hidden',
            'content' => 'Hidden',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $included = MemoryEdge::create([
            'user_id' => 'other-user',
            'from_node_id' => $kept->id,
            'to_node_id' => $visible->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.4,
        ]);
        // Edge into a private node — its endpoints aren't both in the visible
        // set, so it must be filtered out.
        MemoryEdge::create([
            'user_id' => 'other-user',
            'from_node_id' => $kept->id,
            'to_node_id' => $private->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.4,
        ]);

        $response = $this->withSession(['chat_user_id' => 'session-user'])
            ->getJson('/api/graph/ambient');

        $response->assertOk();
        $response->assertJsonCount(1, 'edges');
        $response->assertJsonPath('edges.0.source', $included->from_node_id);
        $response->assertJsonPath('edges.0.target', $included->to_node_id);
    }

    public function test_ambient_strips_user_id_from_payload(): void
    {
        MemoryNode::create([
            'user_id' => 'other-user',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'A node',
            'content' => 'A node',
            'tags' => ['t'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $response = $this->withSession(['chat_user_id' => 'session-user'])
            ->getJson('/api/graph/ambient');

        $response->assertOk();
        $body = $response->json();

        foreach ($body['nodes'] as $node) {
            $this->assertArrayNotHasKey('user_id', $node, 'Ambient feed leaked user_id.');
            $this->assertArrayNotHasKey('session_id', $node, 'Ambient feed leaked session_id.');
            $this->assertArrayNotHasKey('content', $node, 'Ambient feed leaked raw content body.');
            $this->assertArrayHasKey('mine', $node);
            $this->assertIsBool($node['mine']);
        }
    }
}
