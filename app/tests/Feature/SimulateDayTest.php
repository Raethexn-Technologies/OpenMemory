<?php

namespace Tests\Feature;

use App\Models\GraphSnapshot;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SimulateDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_day_command_writes_snapshot_payload_and_prunes_to_96(): void
    {
        $userId = 'demo-sim-user';
        $base = Carbon::parse('2026-01-01 00:00:00');

        for ($i = 0; $i < 96; $i++) {
            GraphSnapshot::create([
                'user_id' => $userId,
                'snapshot_at' => $base->copy()->addMinutes($i * 15),
                'payload' => ['clusters' => []],
            ]);
        }

        $this->artisan('simulate:day', [
            '--user' => $userId,
            '--memories' => 20,
        ])->assertExitCode(0);

        $this->assertSame(96, GraphSnapshot::where('user_id', $userId)->count());

        $latest = GraphSnapshot::where('user_id', $userId)
            ->orderByDesc('snapshot_at')
            ->first();

        $this->assertNotNull($latest);
        $this->assertArrayHasKey('clusters', $latest->payload);
        $this->assertIsArray($latest->payload['clusters']);
        $this->assertDatabaseHas('memory_nodes', ['user_id' => $userId]);
    }

    public function test_demo_endpoint_requires_session_user(): void
    {
        $this->postJson('/api/demo/simulate-day')->assertUnauthorized();
    }

    public function test_demo_endpoint_seeds_current_session_user_and_returns_summary(): void
    {
        $response = $this->withOwnerSession([
            'chat_user_id' => 'session-demo-user',
            'chat_session_id' => 'session-demo-id',
        ])->postJson('/api/demo/simulate-day?fresh=1');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('agents', 3);
        $response->assertJsonCount(3, 'agents_list');

        $this->assertGreaterThanOrEqual(40, $response->json('nodes'));
        $this->assertGreaterThan(0, $response->json('edges'));
        $this->assertDatabaseHas('memory_nodes', ['user_id' => 'session-demo-user']);
        $this->assertGreaterThan(0, MemoryNode::where('user_id', 'session-demo-user')->count());
        $this->assertSame(1, GraphSnapshot::where('user_id', 'session-demo-user')->count());
    }
}
