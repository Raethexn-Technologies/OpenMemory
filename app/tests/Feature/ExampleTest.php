<?php

namespace Tests\Feature;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    // ─── Routing ──────────────────────────────────────────────────────

    public function test_root_redirects_to_chat(): void
    {
        $this->get('/')->assertRedirect('/chat');
    }

    public function test_chat_page_loads(): void
    {
        $this->get('/chat')
            ->assertStatus(200)
            ->assertSee('Chat\\/Index', false);
    }

    public function test_memory_page_loads(): void
    {
        $this->get('/memory')
            ->assertStatus(200)
            ->assertSee('Memory\\/Index', false);
    }

    // ─── Core demo behavior: identity survives reset ───────────────────

    public function test_reset_preserves_user_identity(): void
    {
        // Establish a session with a user identity
        $session = $this->withSession([
            'chat_session_id' => 'sess-111',
            'chat_user_id'    => 'user_abc123',
        ]);

        // Seed a message so reset has something to delete
        Message::create(['session_id' => 'sess-111', 'role' => 'user', 'content' => 'Hello']);

        // Reset — should only clear session_id, not user_id
        $response = $session->post('/chat/reset');
        $response->assertRedirect('/chat');

        // user_id must still be in the session; session_id must be gone
        $this->assertNull($this->app['session']->get('chat_session_id'));
        $this->assertEquals('user_abc123', $this->app['session']->get('chat_user_id'));
    }

    public function test_reset_deletes_transcript_messages(): void
    {
        Message::create(['session_id' => 'sess-222', 'role' => 'user', 'content' => 'Test']);

        $this->withSession(['chat_session_id' => 'sess-222', 'chat_user_id' => 'user_xyz'])
            ->post('/chat/reset');

        $this->assertDatabaseMissing('messages', ['session_id' => 'sess-222']);
    }

    // ─── Status API ────────────────────────────────────────────────────

    public function test_status_endpoint_returns_mode(): void
    {
        $response = $this->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonStructure(['mode', 'healthy'])
            ->assertJsonFragment(['mode' => 'mock']);
    }
}
