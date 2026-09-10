<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRawRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The history surface, with particular attention to the boundaries: owner
 * scoping on every route, and the separation between redacted normalized text
 * and the preserved unredacted source.
 */
class ConversationHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $userId = 'history-owner';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('conversations.local_user_id', $this->userId);
    }

    private function seedConversation(?string $userId = null, string $provider = 'chatgpt', string $title = 'Ledger design'): Conversation
    {
        $userId ??= $this->userId;
        $at = Carbon::parse('2025-03-04T10:00:00Z');

        $conversation = Conversation::create([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_conversation_id' => 'pc-' . Str::random(8),
            'title' => $title,
            'provider_created_at' => $at,
            'first_message_at' => $at,
            'last_message_at' => $at->copy()->addMinutes(2),
            'message_count' => 2,
            'visibility' => Conversation::VISIBILITY_PRIVATE,
            'content_hash' => hash('sha256', $title),
            'parser_version' => 'test-1',
        ]);

        foreach ([['user', 'How should the ledger service handle reconciliation?'], ['assistant', 'Reconcile nightly against the source of truth.']] as $index => [$role, $text]) {
            ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'provider' => $provider,
                'provider_message_id' => 'pm-' . Str::random(10),
                'sequence' => $index,
                'on_active_path' => true,
                'role' => $role,
                'content_text' => $text,
                'provider_created_at' => $at->copy()->addMinutes($index),
                'char_count' => mb_strlen($text),
                'content_hash' => hash('sha256', $text),
            ]);
        }

        return $conversation;
    }

    public function test_history_page_renders_with_the_corpus_overview(): void
    {
        $this->seedConversation();

        $this->get('/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('History/Index')
                ->where('overview.conversation_count', 1)
                ->where('overview.message_count', 2)
                ->has('conversations.data', 1));
    }

    public function test_history_page_renders_with_an_empty_corpus(): void
    {
        $this->get('/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('History/Index')
                ->where('overview.conversation_count', 0)
                ->has('conversations.data', 0));
    }

    public function test_conversation_detail_shows_normalized_messages(): void
    {
        $conversation = $this->seedConversation();

        $this->get("/history/conversations/{$conversation->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('History/Show')
                ->where('conversation.title', 'Ledger design')
                ->has('messages', 2));
    }

    public function test_another_owners_conversation_is_not_reachable(): void
    {
        $other = $this->seedConversation('someone-else');

        $this->get("/history/conversations/{$other->id}")->assertNotFound();
        $this->getJson("/api/history/conversations/{$other->id}/raw")->assertNotFound();
        $this->deleteJson("/api/history/conversations/{$other->id}")->assertNotFound();
    }

    public function test_listing_excludes_other_owners(): void
    {
        $this->seedConversation();
        $this->seedConversation('someone-else', 'claude', 'Not yours');

        $this->getJson('/api/history/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.total', 1)
            ->assertJsonPath('conversations.data.0.title', 'Ledger design');
    }

    public function test_provider_filter_applies_to_the_listing(): void
    {
        $this->seedConversation();
        $this->seedConversation(null, 'claude', 'Claude thread');

        $this->getJson('/api/history/conversations?provider=claude')
            ->assertOk()
            ->assertJsonPath('conversations.total', 1)
            ->assertJsonPath('conversations.data.0.provider', 'claude');
    }

    public function test_search_returns_resolvable_evidence(): void
    {
        $this->seedConversation();

        $response = $this->postJson('/api/history/search', ['query' => 'reconciliation'])->assertOk();

        $evidence = $response->json('evidence');
        $this->assertNotEmpty($evidence);
        $this->assertNotNull(ConversationMessage::query()->whereKey($evidence[0]['message_id'])->first());
    }

    public function test_search_validates_its_input(): void
    {
        $this->postJson('/api/history/search', ['query' => ''])->assertStatus(422);
    }

    public function test_ask_returns_evidence_without_a_model_when_generation_is_off(): void
    {
        $this->seedConversation();
        config()->set('conversations.ask.generate_answer', false);

        $this->postJson('/api/history/ask', ['question' => 'What did I decide about reconciliation?'])
            ->assertOk()
            ->assertJsonPath('answer_state', 'generation_disabled')
            ->assertJsonPath('model_called', false)
            ->assertJsonCount(1, 'evidence');
    }

    public function test_timeline_returns_month_buckets_for_a_subject(): void
    {
        $this->seedConversation();

        $this->postJson('/api/history/timeline', ['subject' => 'reconciliation'])
            ->assertOk()
            ->assertJsonPath('months.0.month', '2025-03')
            ->assertJsonPath('total_conversations', 1);
    }

    public function test_raw_record_is_served_only_to_the_owner(): void
    {
        $conversation = $this->seedConversation();

        ConversationRawRecord::create([
            'import_id' => null,
            'user_id' => $this->userId,
            'provider' => 'chatgpt',
            'provider_conversation_id' => $conversation->provider_conversation_id,
            'conversation_id' => $conversation->id,
            'record_type' => 'conversation',
            'payload' => '{"secret":"kept out of every other path"}',
            'payload_bytes' => 44,
            'payload_sha256' => hash('sha256', 'x'),
        ]);

        $this->getJson("/api/history/conversations/{$conversation->id}/raw")
            ->assertOk()
            ->assertJsonPath('provider', 'chatgpt')
            ->assertSee('kept out of every other path');
    }

    public function test_missing_raw_record_returns_a_clear_not_found(): void
    {
        $conversation = $this->seedConversation();

        $this->getJson("/api/history/conversations/{$conversation->id}/raw")
            ->assertStatus(404)
            ->assertJsonPath('error', 'No raw record was stored for this conversation.');
    }

    public function test_raw_payloads_never_appear_in_the_detail_or_search_responses(): void
    {
        $conversation = $this->seedConversation();

        ConversationRawRecord::create([
            'import_id' => null,
            'user_id' => $this->userId,
            'provider' => 'chatgpt',
            'provider_conversation_id' => $conversation->provider_conversation_id,
            'conversation_id' => $conversation->id,
            'record_type' => 'conversation',
            'payload' => '{"api_key":"sk-proj-abcdefghijklmnopqrstuvwxyz012345"}',
            'payload_bytes' => 52,
            'payload_sha256' => hash('sha256', 'y'),
        ]);

        $this->get("/history/conversations/{$conversation->id}")
            ->assertOk()
            ->assertDontSee('sk-proj-abcdefghijklmnopqrstuvwxyz012345');

        $this->postJson('/api/history/search', ['query' => 'reconciliation'])
            ->assertOk()
            ->assertDontSee('sk-proj-abcdefghijklmnopqrstuvwxyz012345');
    }

    public function test_deleting_a_conversation_removes_messages_and_source(): void
    {
        $conversation = $this->seedConversation();

        ConversationRawRecord::create([
            'import_id' => null,
            'user_id' => $this->userId,
            'provider' => 'chatgpt',
            'provider_conversation_id' => $conversation->provider_conversation_id,
            'conversation_id' => $conversation->id,
            'record_type' => 'conversation',
            'payload' => '{}',
            'payload_bytes' => 2,
            'payload_sha256' => hash('sha256', 'z'),
        ]);

        $this->deleteJson("/api/history/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, ConversationRawRecord::count());
    }

    public function test_imported_history_does_not_reach_the_mcp_search_endpoint(): void
    {
        // The MCP surface reads public graph nodes. Imported conversations live
        // in their own tables and are private, so an external agent connected
        // through MCP cannot pull a person's archived history out of them.
        config()->set('services.mcp.api_key', 'test-key');
        $this->seedConversation();

        $this->postJson('/mcp/search', [
            'user_id' => $this->userId,
            'query' => 'reconciliation',
        ], ['X-OMA-API-Key' => 'test-key'])
            ->assertOk()
            ->assertJsonPath('records', []);
    }
}
