<?php

namespace Tests\Feature;

use App\Models\GraphSnapshot;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GraphSnapshotTest extends TestCase
{
    use RefreshDatabase;

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

    private function snapshot(string $userId, string $time): GraphSnapshot
    {
        return GraphSnapshot::create([
            'user_id' => $userId,
            'snapshot_at' => Carbon::parse($time),
            'payload' => ['clusters' => []],
        ]);
    }

    public function test_take_snapshot_command_creates_snapshot_record(): void
    {
        $this->node('user-1', 'Node A');

        $this->artisan('graph:snapshot')->assertExitCode(0);

        $this->assertDatabaseCount('graph_snapshots', 1);
        $this->assertDatabaseHas('graph_snapshots', ['user_id' => 'user-1']);
    }

    public function test_snapshot_payload_contains_cluster_structure(): void
    {
        $this->node('user-1', 'Node A');

        $this->artisan('graph:snapshot');

        $snapshot = GraphSnapshot::where('user_id', 'user-1')->first();
        $this->assertNotNull($snapshot);
        $this->assertArrayHasKey('clusters', $snapshot->payload);
        $this->assertIsArray($snapshot->payload['clusters']);
    }

    public function test_snapshot_prunes_to_96_per_user(): void
    {
        $this->node('user-1', 'Node A');

        // Insert 97 snapshots for user-1 (15-minute intervals, valid timestamps)
        $base = Carbon::parse('2026-01-01 00:00:00');
        for ($i = 0; $i < 97; $i++) {
            GraphSnapshot::create([
                'user_id' => 'user-1',
                'snapshot_at' => $base->copy()->addMinutes($i * 15),
                'payload' => ['clusters' => []],
            ]);
        }

        $this->assertDatabaseCount('graph_snapshots', 97);

        // Running the command creates one more and then prunes to 96
        $this->artisan('graph:snapshot');

        $this->assertSame(96, GraphSnapshot::where('user_id', 'user-1')->count());
    }

    public function test_pruning_does_not_affect_other_users_snapshots(): void
    {
        $this->node('user-1', 'Node A');
        $this->node('user-2', 'Node B');

        // Fill user-1 to 97 before the run
        $base = Carbon::parse('2026-01-01 00:00:00');
        for ($i = 0; $i < 97; $i++) {
            GraphSnapshot::create([
                'user_id' => 'user-1',
                'snapshot_at' => $base->copy()->addMinutes($i * 15),
                'payload' => ['clusters' => []],
            ]);
        }
        // user-2 has 3
        for ($i = 0; $i < 3; $i++) {
            GraphSnapshot::create([
                'user_id' => 'user-2',
                'snapshot_at' => $base->copy()->addMinutes($i * 15),
                'payload' => ['clusters' => []],
            ]);
        }

        $this->artisan('graph:snapshot');

        // user-1 pruned to 96; user-2 now has 3 + 1 = 4
        $this->assertSame(96, GraphSnapshot::where('user_id', 'user-1')->count());
        $this->assertSame(4, GraphSnapshot::where('user_id', 'user-2')->count());
    }

    public function test_snapshots_index_endpoint_returns_list(): void
    {
        $this->snapshot('user-1', '2026-01-01 10:00:00');
        $this->snapshot('user-1', '2026-01-01 10:15:00');

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/graph/snapshots');

        $response->assertOk();
        $response->assertJsonCount(2, 'snapshots');
        $response->assertJsonStructure([
            'snapshots' => [['id', 'snapshot_at']],
        ]);
    }

    public function test_snapshots_index_returns_only_current_users_snapshots(): void
    {
        $this->snapshot('user-1', '2026-01-01 10:00:00');
        $this->snapshot('user-2', '2026-01-01 10:00:00');

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson('/api/graph/snapshots');

        $response->assertOk();
        $response->assertJsonCount(1, 'snapshots');
    }

    public function test_snapshot_show_endpoint_returns_full_payload(): void
    {
        $snap = GraphSnapshot::create([
            'user_id' => 'user-1',
            'snapshot_at' => Carbon::now(),
            'payload' => ['clusters' => [['id' => 'abc', 'node_ids' => [], 'node_count' => 0, 'mean_weight' => 0.0]]],
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->getJson("/api/graph/snapshots/{$snap->id}");

        $response->assertOk();
        $response->assertJsonStructure(['clusters']);
        $response->assertJsonPath('clusters.0.id', 'abc');
    }

    public function test_snapshot_show_returns_404_for_other_users_snapshot(): void
    {
        $snap = GraphSnapshot::create([
            'user_id' => 'user-1',
            'snapshot_at' => Carbon::now(),
            'payload' => ['clusters' => []],
        ]);

        $this->withSession(['chat_user_id' => 'user-2'])
            ->getJson("/api/graph/snapshots/{$snap->id}")
            ->assertNotFound();
    }
}
