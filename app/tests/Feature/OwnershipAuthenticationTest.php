<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\CorpusOwnerBinding;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

class OwnershipAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = ConversationFixtures::scratchDir('ownership');
        Http::preventStrayRequests();
        config()->set('conversations.ask.generate_answer', false);
    }

    protected function tearDown(): void
    {
        ConversationFixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function archive(): string
    {
        return ConversationFixtures::writeZip($this->dir.'/synthetic.zip', [
            'conversations.json' => json_encode(ConversationFixtures::chatGptConversations()),
        ]);
    }

    private function corpus(string $key): Conversation
    {
        app(ConversationImportService::class)->importPath($key, $this->archive());

        return Conversation::where('user_id', $key)->firstOrFail();
    }

    public function test_every_private_history_route_denies_an_unauthenticated_request(): void
    {
        config()->set('conversations.local_user_id', 'configured-owner');
        $conversation = $this->corpus('configured-owner');
        $this->withSession(['chat_user_id' => 'configured-owner', 'identity_source' => 'browser']);
        foreach ([
            ['GET', '/history'],
            ['GET', '/history/conversations/'.$conversation->id],
            ['GET', '/api/history/conversations'],
            ['POST', '/api/history/search'],
            ['POST', '/api/history/ask'],
            ['POST', '/api/history/timeline'],
            ['GET', '/api/history/conversations/'.$conversation->id.'/raw'],
            ['DELETE', '/api/history/conversations/'.$conversation->id],
        ] as [$method, $uri]) {
            $this->json($method, $uri, ['query' => 'ledger', 'question' => 'ledger', 'subject' => 'ledger'])
                ->assertUnauthorized();
        }
        $this->get('/history')->assertRedirect('/login');
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_owner_can_read_search_and_delete_their_bound_corpus(): void
    {
        $user = User::factory()->create();
        $conversation = $this->corpus($user->owner_uuid);
        $this->actingAs($user);
        $this->get('/history')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/history/conversations/'.$conversation->id)->assertOk();
        $this->getJson('/api/history/conversations')->assertOk()->assertJsonPath('conversations.total', 3);
        $evidence = $this->postJson('/api/history/search', ['query' => 'ledger'])->assertOk()->json('evidence');
        $this->assertNotEmpty($evidence);
        $this->postJson('/api/history/ask', ['question' => 'ledger'])->assertOk()->assertJsonPath('model_called', false);
        $this->postJson('/api/history/timeline', ['subject' => 'ledger'])->assertOk();
        $this->getJson('/api/history/conversations/'.$conversation->id.'/raw')->assertOk();
        $this->deleteJson('/api/history/conversations/'.$conversation->id)->assertOk();
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    }

    public function test_second_authenticated_owner_cannot_read_search_or_delete_the_first_corpus(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $conversation = $this->corpus($a->owner_uuid);
        config()->set('conversations.local_user_id', $a->owner_uuid);
        $this->actingAs($b)->withSession([
            'chat_user_id' => $a->owner_uuid,
            'authenticated_owner_context' => $a->id.':'.$a->owner_uuid,
        ]);
        $this->get('/history/conversations/'.$conversation->id)->assertNotFound();
        $this->getJson('/api/history/conversations/'.$conversation->id.'/raw')->assertNotFound();
        $this->deleteJson('/api/history/conversations/'.$conversation->id)->assertNotFound();
        $this->getJson('/api/history/conversations?user_id='.$a->owner_uuid)
            ->assertOk()->assertJsonPath('conversations.total', 0);
        $this->postJson('/api/history/search', ['query' => 'ledger', 'user_id' => $a->owner_uuid])
            ->assertOk()->assertJsonCount(0, 'evidence');
        $this->postJson('/api/history/ask', ['question' => 'ledger'])
            ->assertOk()->assertJsonCount(0, 'evidence');
        $this->postJson('/api/history/timeline', ['subject' => 'ledger'])
            ->assertOk()->assertJsonPath('total_conversations', 0);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_configured_owner_never_replaces_an_authenticated_owners_binding(): void
    {
        $user = User::factory()->create();
        $this->corpus('unbound-legacy');
        config()->set('conversations.local_user_id', 'unbound-legacy');
        $this->actingAs($user)->getJson('/api/history/conversations')
            ->assertOk()->assertJsonPath('conversations.total', 0);
        $this->assertSame($user->owner_uuid, session('chat_user_id'));
    }

    public function test_missing_binding_fails_closed(): void
    {
        $user = User::factory()->create();
        CorpusOwnerBinding::where('user_id', $user->id)->delete();
        $this->actingAs($user)->getJson('/api/history/conversations')->assertForbidden();
    }

    public function test_principal_and_stale_session_cannot_establish_ownership(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $this->actingAs($user)->withSession([
            'chat_user_id' => $victim->owner_uuid,
            'chat_session_id' => 'victim-transcript',
            'identity_source' => 'browser',
        ])->postJson('/chat/send', ['message' => 'Hello', 'principal' => $victim->owner_uuid])
            ->assertStatus(422);
        $this->assertSame($user->owner_uuid, session('chat_user_id'));
        $this->assertSame('openmemory', session('identity_source'));
        $this->assertNull(session('chat_session_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_browser_principal_without_login_is_denied(): void
    {
        $this->withSession(['chat_user_id' => 'victim', 'chat_session_id' => 'old'])
            ->postJson('/chat/send', ['message' => 'Hello', 'principal' => 'victim'])
            ->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_private_browser_surfaces_cannot_be_reached_with_only_a_legacy_session(): void
    {
        $this->withSession(['chat_user_id' => 'victim']);
        foreach (['/chat', '/memory', '/memory/refresh', '/graph', '/api/graph', '/api/documents', '/agents', '/3d'] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
        foreach (['/chat/store-memory', '/chat/sync-graph-memory', '/api/graph/prune', '/api/documents/ingest', '/api/agents'] as $uri) {
            $this->postJson($uri)->assertUnauthorized();
        }
    }

    public function test_local_cli_import_and_explicit_binding_preserve_existing_records(): void
    {
        config()->set('conversations.local_user_id', 'legacy-cli');
        $this->artisan('memory:import-archive', ['path' => $this->archive(), '--json' => true])->assertSuccessful();
        $ids = Conversation::where('user_id', 'legacy-cli')->pluck('id')->all();
        $user = User::factory()->create();
        $this->artisan('openmemory:corpus:bind', ['owner' => 'legacy-cli', '--email' => $user->email])->assertSuccessful();
        $this->actingAs($user)->getJson('/api/history/conversations')->assertOk()->assertJsonPath('conversations.total', 3);
        $this->artisan('memory:import-archive', ['path' => $this->archive(), '--user' => 'legacy-cli', '--json' => true])->assertSuccessful();
        $this->assertSame($ids, Conversation::where('user_id', 'legacy-cli')->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_binding_is_idempotent_but_cannot_be_claimed_by_another_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $args = ['owner' => 'legacy', '--email' => $a->email];
        $this->artisan('openmemory:corpus:bind', $args)->assertSuccessful();
        $this->artisan('openmemory:corpus:bind', $args)->assertSuccessful();
        $this->artisan('openmemory:corpus:bind', ['owner' => 'legacy', '--email' => $b->email])->assertFailed();
        $this->artisan('openmemory:corpus:bind', ['owner' => $a->owner_uuid, '--email' => $b->email])->assertFailed();
        $this->artisan('openmemory:corpus:bind', ['owner' => 'other', '--email' => $a->email])->assertFailed();
        $this->assertSame('legacy', $a->corpusOwnerKey());
        $this->assertSame($b->owner_uuid, $b->corpusOwnerKey());
    }

    public function test_binding_cannot_abandon_a_namespace_containing_data(): void
    {
        $user = User::factory()->create();
        $this->corpus($user->owner_uuid);
        $this->artisan('openmemory:corpus:bind', ['owner' => 'legacy', '--email' => $user->email])->assertFailed();
        $this->assertSame($user->owner_uuid, $user->corpusOwnerKey());
    }

    public function test_binding_requires_an_existing_account_and_valid_owner_key(): void
    {
        $this->artisan('openmemory:corpus:bind', ['owner' => 'legacy', '--email' => 'missing@example.test'])->assertFailed();
        $this->artisan('openmemory:corpus:bind', ['owner' => ' legacy ', '--email' => 'missing@example.test'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_creation_prompts_for_a_password_and_does_not_bind_configured_corpus(): void
    {
        config()->set('conversations.local_user_id', 'unclaimed');
        $this->artisan('openmemory:user:create', ['email' => 'owner@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'synthetic-long-password')
            ->expectsQuestion('Confirm password', 'synthetic-long-password')
            ->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('synthetic-long-password', $user->password));
        $this->assertSame($user->owner_uuid, $user->corpusOwnerKey());
        $this->assertDatabaseMissing('corpus_owner_bindings', ['owner_key' => 'unclaimed']);
    }

    public function test_user_creation_rejects_mismatched_passwords(): void
    {
        $this->artisan('openmemory:user:create', ['email' => 'owner@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'synthetic-long-password')
            ->expectsQuestion('Confirm password', 'different-long-password')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_password_login_regenerates_session_and_discards_legacy_context(): void
    {
        $user = User::factory()->create(['password' => 'synthetic-long-password']);
        $this->withSession(['chat_user_id' => 'victim', 'chat_session_id' => 'old']);
        $before = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-long-password'])
            ->assertRedirect('/history');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('chat_session_id'));
        $this->getJson('/api/history/conversations')->assertOk();
        $this->assertSame($user->owner_uuid, session('chat_user_id'));
    }

    public function test_invalid_login_is_generic_and_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_invalidates_authentication_and_owner_context(): void
    {
        $this->withOwnerSession(['chat_user_id' => 'owner', 'chat_session_id' => 'transcript']);
        $before = session()->getId();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('authenticated_owner_context'));
        $this->assertNull(session('chat_session_id'));
        $this->getJson('/api/history/conversations')->assertUnauthorized();
    }

    public function test_switching_authenticated_owners_drops_previous_chat_context(): void
    {
        $this->withOwnerSession(['chat_user_id' => 'owner-a', 'chat_session_id' => 'old-transcript']);
        Message::create(['session_id' => 'old-transcript', 'role' => 'user', 'content' => 'Synthetic private transcript.']);
        $b = User::factory()->create();
        $this->actingAs($b)->get('/chat')->assertOk()->assertDontSee('Synthetic private transcript.');
        $this->assertSame($b->owner_uuid, session('chat_user_id'));
        $this->assertNotSame('old-transcript', session('chat_session_id'));
        $this->assertDatabaseHas('messages', ['session_id' => 'old-transcript']);
    }

    public function test_csrf_protects_login_logout_and_history_mutations(): void
    {
        $this->app['env'] = 'local';
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertStatus(419);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->post('/logout')->assertStatus(419);
        $this->postJson('/api/history/search', ['query' => 'ledger'])->assertStatus(419);
        $this->deleteJson('/api/history/conversations/missing')->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->postJson('/api/history/search', ['query' => 'ledger', '_token' => 'synthetic-csrf-token'])
            ->assertOk();
    }

    public function test_global_inspection_is_public_only_in_mock_and_adapter_modes(): void
    {
        $user = User::factory()->create();
        $records = [
            ['id' => 'public', 'user_id' => 'other', 'memory_type' => 'public', 'content' => 'Public example.'],
            ['id' => 'private', 'user_id' => $user->owner_uuid, 'memory_type' => 'private', 'content' => 'Private example.'],
            ['id' => 'sensitive', 'user_id' => 'other', 'memory_type' => 'sensitive', 'content' => 'Sensitive example.'],
            ['id' => 'unknown', 'user_id' => 'other', 'content' => 'Unclassified example.'],
        ];
        $this->actingAs($user);
        config()->set('services.icp.endpoint', 'http://adapter.test');
        cache()->put('mock_icp_recent', $records);
        config()->set('services.icp.mock', true);
        $mock = $this->getJson('/memory/refresh')->assertOk()->json('memories');
        config()->set('services.icp.mock', false);
        config()->set('services.icp.endpoint', 'http://adapter.test');
        Http::fake(['adapter.test/memories/recent*' => Http::response(['memories' => $records])]);
        $live = $this->getJson('/memory/refresh')->assertOk()->json('memories');
        $this->assertSame([$records[0]], $mock);
        $this->assertSame($mock, $live);
    }

    public function test_mcp_key_is_not_browser_authentication(): void
    {
        config()->set('services.mcp.api_key', 'synthetic-client-key');
        $this->withHeaders(['X-OMA-API-Key' => 'synthetic-client-key'])
            ->getJson('/api/history/conversations')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_migration_backfills_users_without_claiming_existing_corpora(): void
    {
        $migration = require database_path('migrations/2026_09_22_000001_add_authenticated_corpus_ownership.php');
        $migration->down();
        $id = DB::table('users')->insertGetId([
            'name' => 'Existing account', 'email' => 'existing@example.test', 'password' => Hash::make('synthetic-password'),
        ]);
        config()->set('conversations.local_user_id', 'unbound');
        $migration->up();
        $user = User::findOrFail($id);
        $this->assertNotEmpty($user->owner_uuid);
        $this->assertSame($user->owner_uuid, $user->corpusOwnerKey());
        $this->assertDatabaseMissing('corpus_owner_bindings', ['owner_key' => 'unbound']);
    }

    public function test_successful_chat_cannot_adopt_a_supplied_principal(): void
    {
        $owner = User::factory()->create();
        $llm = \Mockery::mock(\App\Services\LLM\LlmService::class);
        $llm->shouldReceive('provider')->andReturn('test-provider');
        $llm->shouldReceive('buildSystemPrompt')->once()->with([])->andReturn('Test policy.');
        $llm->shouldReceive('chat')->once()->andReturn('Synthetic answer.');
        $this->app->instance(\App\Services\LLM\LlmService::class, $llm);
        $memorability = \Mockery::mock(\App\Services\MemorabilityService::class);
        $memorability->shouldReceive('evaluate')->once()->with('Hello', 'Synthetic answer.', $owner->owner_uuid)
            ->andReturn(['decision' => 'skip', 'node_id' => null]);
        $this->app->instance(\App\Services\MemorabilityService::class, $memorability);
        $this->actingAs($owner)->get('/chat')->assertOk();
        $this->postJson('/chat/send', ['message' => 'Hello', 'principal' => 'forged-principal'])
            ->assertOk()->assertJsonPath('user_id', $owner->owner_uuid)->assertJsonPath('identity_source', 'openmemory');
        $this->assertSame($owner->owner_uuid, session('chat_user_id'));
        Http::assertNothingSent();
    }

    public function test_private_mock_write_uses_authenticated_owner_not_request_owner(): void
    {
        $owner = User::factory()->create();
        $extractor = \Mockery::mock(\App\Services\GraphExtractionService::class);
        $extractor->shouldReceive('extract')->once()->andReturn(null);
        $this->app->instance(\App\Services\GraphExtractionService::class, $extractor);
        $this->actingAs($owner)->get('/chat')->assertOk();
        $this->postJson('/chat/store-memory', [
            'content' => 'Synthetic private note.', 'memory_type' => 'private',
            'user_id' => 'victim', 'principal' => 'victim',
        ])->assertOk();
        $this->assertEmpty(cache()->get('mock_icp_victim', []));
        $records = cache()->get('mock_icp_'.$owner->owner_uuid, []);
        $this->assertCount(1, $records);
        $this->assertSame($owner->owner_uuid, $records[0]['user_id']);
        $this->getJson('/memory/refresh')->assertOk()->assertJsonCount(0, 'memories');
    }

    public function test_binding_cannot_abandon_private_mock_memories(): void
    {
        $owner = User::factory()->create();
        cache()->put('mock_icp_'.$owner->owner_uuid, [['memory_type' => 'private']]);
        $this->artisan('openmemory:corpus:bind', ['owner' => 'legacy', '--email' => $owner->email])->assertFailed();
        $this->assertSame($owner->owner_uuid, $owner->corpusOwnerKey());
    }

    public function test_database_rejects_duplicate_owner_bindings(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CorpusOwnerBinding::where('user_id', $b->id)->update(['owner_key' => $a->owner_uuid]);
    }

    public function test_login_page_supports_inertia_expiry_and_contains_csrf_input(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeaders(['X-Inertia' => 'true'])->get('/login')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
    }

    public function test_deleted_account_cannot_reuse_its_authenticated_session(): void
    {
        $user = User::factory()->create(['password' => 'synthetic-long-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-long-password'])->assertRedirect();
        CorpusOwnerBinding::where('user_id', $user->id)->delete();
        $user->delete();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/history/conversations')->assertUnauthorized();
    }
}
