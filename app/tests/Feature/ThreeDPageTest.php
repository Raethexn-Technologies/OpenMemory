<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThreeDPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_threed_page_loads(): void
    {
        $this->withSession(['chat_user_id' => 'user-1'])
            ->get('/3d')
            ->assertOk();
    }

    public function test_threed_page_passes_agents_prop(): void
    {
        Agent::create([
            'owner_user_id' => 'user-1',
            'graph_user_id' => 'agent_' . Str::uuid(),
            'name' => 'Test Agent',
            'trust_score' => 0.5,
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->get('/3d');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Memory/ThreeD')
            ->has('agents', 1)
        );
    }

    public function test_threed_page_passes_empty_agents_when_none_exist(): void
    {
        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->get('/3d');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Memory/ThreeD')
            ->has('agents', 0)
        );
    }

    public function test_threed_page_agents_prop_scoped_to_session_user(): void
    {
        Agent::create([
            'owner_user_id' => 'user-1',
            'graph_user_id' => 'agent_' . Str::uuid(),
            'name' => 'Mine',
            'trust_score' => 0.5,
        ]);
        Agent::create([
            'owner_user_id' => 'user-2',
            'graph_user_id' => 'agent_' . Str::uuid(),
            'name' => 'Theirs',
            'trust_score' => 0.5,
        ]);

        $response = $this->withSession(['chat_user_id' => 'user-1'])
            ->get('/3d');

        $response->assertInertia(fn ($page) => $page
            ->has('agents', 1)
        );
    }
}
