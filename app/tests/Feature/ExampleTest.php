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

    // ─── Principal-based identity ──────────────────────────────────────

    public function test_send_adopts_browser_principal_on_first_message(): void
    {
        // Simulate a fresh session with a server-generated fallback id
        $this->withSession([
            'chat_session_id' => 'sess-333',
            'chat_user_id'    => 'session_fallback',
            'identity_source' => 'session',
        ])->postJson('/chat/send', [
            'message'   => 'Hello',
            'principal' => 'abc12-defgh-ijklm-nopqr-cai',
        ]);

        // After the first message with a principal, the session user_id should be the principal
        $this->assertEquals('abc12-defgh-ijklm-nopqr-cai', $this->app['session']->get('chat_user_id'));
        $this->assertEquals('browser', $this->app['session']->get('identity_source'));
    }

    public function test_send_does_not_replace_established_browser_principal(): void
    {
        // Once identity_source is 'browser', subsequent messages cannot change the user_id
        $this->withSession([
            'chat_session_id' => 'sess-444',
            'chat_user_id'    => 'original-principal-cai',
            'identity_source' => 'browser',
        ])->postJson('/chat/send', [
            'message'   => 'Hello again',
            'principal' => 'different-principal-cai',
        ]);

        // Should remain the original — first-message lock-in is preserved
        $this->assertEquals('original-principal-cai', $this->app['session']->get('chat_user_id'));
    }

    // ─── Status API ────────────────────────────────────────────────────

    public function test_status_endpoint_returns_mode(): void
    {
        $response = $this->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonStructure(['mode', 'healthy'])
            ->assertJsonFragment(['mode' => 'mock']);
    }

    // ─── Core memory-type architecture ────────────────────────────────
    //
    // These tests validate the three most important behavioral contracts:
    //   1. LLM recall is restricted to public memories only
    //   2. Private/sensitive memories require user approval before being stored
    //   3. The /chat/store-memory endpoint enforces memory_type boundaries
    //
    // They run in mock mode (default), which now mirrors live-mode behavior:
    // the same consent gates fire, the same filters apply.

    public function test_get_public_memories_excludes_private_and_sensitive(): void
    {
        $userId = 'test-principal-abc';

        // Seed the mock cache with all three types
        cache()->put("mock_icp_{$userId}", [
            [
                'id' => '1', 'user_id' => $userId, 'session_id' => 's1',
                'content' => 'Public fact about user', 'timestamp' => now()->toIso8601String(),
                'metadata' => null, 'memory_type' => 'public',
            ],
            [
                'id' => '2', 'user_id' => $userId, 'session_id' => 's1',
                'content' => 'Private fact about user', 'timestamp' => now()->toIso8601String(),
                'metadata' => null, 'memory_type' => 'private',
            ],
            [
                'id' => '3', 'user_id' => $userId, 'session_id' => 's1',
                'content' => 'Sensitive fact about user', 'timestamp' => now()->toIso8601String(),
                'metadata' => null, 'memory_type' => 'sensitive',
            ],
        ], now()->addHour());

        $icp    = app(\App\Services\IcpMemoryService::class);
        $result = $icp->getPublicMemories($userId);

        // Only the public record must be returned to the LLM
        $this->assertCount(1, $result);
        $this->assertEquals('Public fact about user', $result[0]['content']);
        $this->assertEquals('public', $result[0]['memory_type']);
    }

    public function test_store_memory_endpoint_persists_with_correct_type_after_approval(): void
    {
        // Simulate the browser calling /chat/store-memory after user approves
        // a sensitive memory in mock mode (mirrors live-mode browser→canister write)
        $response = $this->withSession([
            'chat_session_id' => 'sess-approve-test',
            'chat_user_id'    => 'test-principal-xyz',
        ])->postJson('/chat/store-memory', [
            'content'     => 'User earns $120k annually',
            'memory_type' => 'sensitive',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);

        // Verify the record was stored with the correct type — not downgraded to public
        $cached = cache()->get('mock_icp_test-principal-xyz', []);
        $this->assertCount(1, $cached);
        $this->assertEquals('sensitive', $cached[0]['memory_type']);
        $this->assertEquals('User earns $120k annually', $cached[0]['content']);
    }

    public function test_store_memory_endpoint_rejects_public_type(): void
    {
        // Public memories are written by ChatController::send() directly, not this endpoint.
        // Accepting public here would bypass the approval-gate contract.
        $this->withSession([
            'chat_session_id' => 'sess-pub-test',
            'chat_user_id'    => 'test-principal-pub',
        ])->postJson('/chat/store-memory', [
            'content'     => 'I enjoy hiking',
            'memory_type' => 'public',
        ])->assertStatus(422); // validation rule: 'in:private,sensitive'
    }
}
