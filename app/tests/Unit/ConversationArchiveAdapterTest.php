<?php

namespace Tests\Unit;

use App\Services\Conversations\Adapters\ChatGptArchiveAdapter;
use App\Services\Conversations\Adapters\ClaudeArchiveAdapter;
use App\Services\Conversations\Adapters\GeminiTakeoutArchiveAdapter;
use App\Services\Conversations\Archive\ArchiveLimits;
use App\Services\Conversations\Archive\ArchiveSource;
use App\Services\Conversations\Archive\ArchiveSourceFactory;
use App\Services\Conversations\ConversationArchiveRegistry;
use App\Services\Conversations\NormalizedConversation;
use App\Services\Conversations\NormalizedMessage;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

/**
 * Adapter behaviour against synthetic archives.
 *
 * These tests are the executable version of the format documentation. When a
 * provider changes its export, this file is what fails first and what a
 * maintainer updates to describe the new shape.
 */
class ConversationArchiveAdapterTest extends TestCase
{
    private string $dir;

    /** @var array<int, ArchiveSource> */
    private array $open = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = ConversationFixtures::scratchDir('adapters');
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $source) {
            $source->close();
        }

        $this->open = [];
        ConversationFixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function limits(): ArchiveLimits
    {
        return new ArchiveLimits(
            maxTotalUncompressedBytes: 50 * 1024 * 1024,
            maxEntryUncompressedBytes: 25 * 1024 * 1024,
            maxEntries: 1000,
            maxCompressionRatio: 400.0,
            maxJsonElementBytes: 1024 * 1024,
            maxMessageChars: 10000,
        );
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(string $name, array $entries): ArchiveSource
    {
        $path = ConversationFixtures::writeZip($this->dir . '/' . $name, $entries);
        $source = (new ArchiveSourceFactory())->open($path, $this->limits());
        $this->open[] = $source;

        return $source;
    }

    private function chatGptArchive(): ArchiveSource
    {
        return $this->zip('chatgpt.zip', [
            'conversations.json' => json_encode(ConversationFixtures::chatGptConversations(), JSON_UNESCAPED_UNICODE),
            'user.json' => '{"id":"user-synthetic"}',
            'chat.html' => '<html></html>',
        ]);
    }

    private function claudeArchive(): ArchiveSource
    {
        return $this->zip('claude.zip', [
            'conversations.json' => json_encode(ConversationFixtures::claudeConversations(), JSON_UNESCAPED_UNICODE),
            'users.json' => '[{"uuid":"user-synthetic"}]',
        ]);
    }

    private function geminiArchive(): ArchiveSource
    {
        return $this->zip('gemini.zip', [
            'Takeout/My Activity/Gemini Apps/MyActivity.json' => json_encode(ConversationFixtures::geminiActivityRecords(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array<int, NormalizedConversation>
     */
    private function parse($adapter, ArchiveSource $source): array
    {
        $detection = $adapter->detect($source);
        $this->assertTrue($detection->supported, 'Adapter did not report the archive as supported.');

        return iterator_to_array($adapter->conversations($source, $detection), false);
    }

    // ChatGPT.

    public function test_chatgpt_adapter_detects_its_own_archive(): void
    {
        $detection = (new ChatGptArchiveAdapter())->detect($this->chatGptArchive());

        $this->assertTrue($detection->supported);
        $this->assertSame('conversations.json', $detection->entry);
    }

    public function test_chatgpt_adapter_does_not_claim_a_claude_archive(): void
    {
        // Both providers ship a file called conversations.json, so name-based
        // detection would misroute an entire history to the wrong parser.
        $detection = (new ChatGptArchiveAdapter())->detect($this->claudeArchive());

        $this->assertFalse($detection->recognized);
    }

    public function test_chatgpt_conversation_metadata_is_normalized(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());

        $this->assertCount(3, $conversations);

        $first = $conversations[0];
        $this->assertSame('chatgpt', $first->provider);
        $this->assertSame('conv-chatgpt-0001', $first->providerConversationId);
        $this->assertSame('Choosing a database for the ledger service', $first->title);
        $this->assertSame('2024-06-01T12:00:00+00:00', $first->createdAt?->toIso8601String());
        $this->assertSame(['gpt-synthetic-1'], $first->models());
    }

    public function test_chatgpt_active_branch_is_ordered_first_and_marked(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $messages = $conversations[0]->messages;

        $active = array_values(array_filter($messages, static fn (NormalizedMessage $m) => $m->onActivePath));
        $abandoned = array_values(array_filter($messages, static fn (NormalizedMessage $m) => ! $m->onActivePath));

        $this->assertSame(
            ['msg-1', 'msg-2', 'msg-3b', 'msg-4b'],
            array_map(static fn (NormalizedMessage $m) => $m->providerMessageId, $active),
        );

        // The edited-away branch survives instead of being discarded.
        $this->assertSame(
            ['msg-3a', 'msg-4a'],
            array_map(static fn (NormalizedMessage $m) => $m->providerMessageId, $abandoned),
        );
    }

    public function test_chatgpt_parent_links_are_preserved(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $byId = [];

        foreach ($conversations[0]->messages as $message) {
            $byId[$message->providerMessageId] = $message;
        }

        $this->assertSame('node-2', $byId['msg-3b']->parentProviderMessageId);
        $this->assertSame('node-2', $byId['msg-3a']->parentProviderMessageId);
    }

    public function test_chatgpt_missing_timestamp_stays_null(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $messages = $conversations[1]->messages;

        $withoutTime = array_values(array_filter(
            $messages,
            static fn (NormalizedMessage $m) => $m->providerMessageId === 'msg-b2',
        ));

        $this->assertCount(1, $withoutTime);
        $this->assertNull($withoutTime[0]->createdAt);
    }

    public function test_chatgpt_image_parts_become_attachments_not_body_text(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $byId = [];

        foreach ($conversations[1]->messages as $message) {
            $byId[$message->providerMessageId] = $message;
        }

        $message = $byId['msg-b2'];
        $this->assertCount(1, $message->attachments);
        $this->assertSame('file_SYNTHETIC0001', $message->attachments[0]->id);
        $this->assertSame(800, $message->attachments[0]->width);
        $this->assertStringContainsString('Café notes', $message->text);
        $this->assertStringNotContainsString('sediment://', $message->text);
    }

    public function test_chatgpt_tool_messages_keep_the_tool_role(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $roles = array_map(
            static fn (NormalizedMessage $m) => $m->role,
            $conversations[1]->messages,
        );

        $this->assertContains(NormalizedMessage::ROLE_TOOL, $roles);
    }

    public function test_chatgpt_empty_conversation_is_kept_with_a_warning(): void
    {
        $conversations = $this->parse(new ChatGptArchiveAdapter(), $this->chatGptArchive());
        $empty = $conversations[2];

        $this->assertSame('conv-chatgpt-0003', $empty->providerConversationId);
        $this->assertSame([], $empty->messages);
        $this->assertNotEmpty($empty->warnings);
    }

    public function test_chatgpt_conversation_without_an_id_gets_a_deterministic_one(): void
    {
        $record = [[
            'title' => 'No identifier',
            'create_time' => 1717243200.0,
            'current_node' => 'n1',
            'mapping' => [
                'n1' => ['id' => 'n1', 'parent' => null, 'children' => [], 'message' => [
                    'id' => 'm1',
                    'author' => ['role' => 'user'],
                    'create_time' => 1717243200.0,
                    'content' => ['content_type' => 'text', 'parts' => ['hello']],
                    'metadata' => [],
                ]],
            ],
        ]];

        $json = json_encode($record);
        $first = $this->parse(new ChatGptArchiveAdapter(), $this->zip('noid-a.zip', ['conversations.json' => $json]));
        $second = $this->parse(new ChatGptArchiveAdapter(), $this->zip('noid-b.zip', ['conversations.json' => $json]));

        $this->assertStringStartsWith('om-', $first[0]->providerConversationId);
        $this->assertSame($first[0]->providerConversationId, $second[0]->providerConversationId);
    }

    public function test_chatgpt_malformed_conversation_is_skipped_and_reported(): void
    {
        $adapter = new ChatGptArchiveAdapter();
        $source = $this->zip('mixed.zip', [
            'conversations.json' => '[' . json_encode(ConversationFixtures::chatGptConversations()[0]) . ',{"mapping":,},{"title":"no mapping"}]',
        ]);

        $conversations = $this->parse($adapter, $source);

        $this->assertCount(1, $conversations);
        $this->assertNotEmpty($adapter->warnings());
    }

    public function test_chatgpt_parent_cycle_does_not_hang(): void
    {
        // A malformed export can contain a parent loop. The walk must terminate.
        $record = [[
            'conversation_id' => 'cycle',
            'title' => 'Cycle',
            'current_node' => 'a',
            'mapping' => [
                'a' => ['id' => 'a', 'parent' => 'b', 'children' => ['b'], 'message' => [
                    'id' => 'ma', 'author' => ['role' => 'user'], 'create_time' => 1717243200.0,
                    'content' => ['content_type' => 'text', 'parts' => ['one']], 'metadata' => [],
                ]],
                'b' => ['id' => 'b', 'parent' => 'a', 'children' => ['a'], 'message' => [
                    'id' => 'mb', 'author' => ['role' => 'assistant'], 'create_time' => 1717243260.0,
                    'content' => ['content_type' => 'text', 'parts' => ['two']], 'metadata' => [],
                ]],
            ],
        ]];

        $conversations = $this->parse(
            new ChatGptArchiveAdapter(),
            $this->zip('cycle.zip', ['conversations.json' => json_encode($record)]),
        );

        $this->assertCount(1, $conversations);
        $this->assertCount(2, $conversations[0]->messages);
    }

    // Claude.

    public function test_claude_adapter_detects_its_own_archive(): void
    {
        $detection = (new ClaudeArchiveAdapter())->detect($this->claudeArchive());

        $this->assertTrue($detection->supported);
    }

    public function test_claude_adapter_does_not_claim_a_chatgpt_archive(): void
    {
        $this->assertFalse((new ClaudeArchiveAdapter())->detect($this->chatGptArchive())->recognized);
    }

    public function test_claude_text_blocks_become_the_body(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());
        $first = $conversations[0];

        $this->assertSame('conv-claude-0001', $first->providerConversationId);
        $this->assertSame('Refactoring the retrieval scorer', $first->title);
        $this->assertSame('2025-04-04T09:12:00+00:00', $first->createdAt?->toIso8601String());
        $this->assertStringContainsString('double counts hyphenated tokens', $first->messages[0]->text);
    }

    public function test_claude_thinking_and_tool_blocks_are_recorded_but_not_inlined(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());
        $assistant = $conversations[0]->messages[1];

        $this->assertStringContainsString('Index the compound', $assistant->text);
        $this->assertStringNotContainsString('Considering token boundaries', $assistant->text);

        $types = array_column($assistant->contentBlocks, 'type');
        $this->assertContains('thinking', $types);
        $this->assertContains('tool_use', $types);
    }

    public function test_claude_legacy_text_field_is_used_when_blocks_are_absent(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());
        $legacy = $conversations[0]->messages[2];

        $this->assertStringContainsString('Résumé of the change', $legacy->text);
        $this->assertSame('legacy_text', $legacy->contentBlocks[0]['source'] ?? null);
    }

    public function test_claude_roles_are_normalized_from_sender(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());

        $this->assertSame(NormalizedMessage::ROLE_USER, $conversations[0]->messages[0]->role);
        $this->assertSame(NormalizedMessage::ROLE_ASSISTANT, $conversations[0]->messages[1]->role);
    }

    public function test_claude_files_become_attachment_metadata(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());
        $message = $conversations[1]->messages[0];

        $this->assertCount(1, $message->attachments);
        $this->assertSame('map.png', $message->attachments[0]->name);
        $this->assertSame('image/png', $message->attachments[0]->mediaType);
    }

    public function test_claude_project_label_is_captured(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());

        $this->assertSame('Personal', $conversations[1]->workspaceLabel);
    }

    public function test_claude_empty_conversation_is_kept_with_a_warning(): void
    {
        $conversations = $this->parse(new ClaudeArchiveAdapter(), $this->claudeArchive());

        $this->assertSame([], $conversations[2]->messages);
        $this->assertNotEmpty($conversations[2]->warnings);
    }

    public function test_claude_unknown_sender_becomes_unknown_not_user(): void
    {
        $record = [[
            'uuid' => 'conv-x',
            'name' => 'Odd sender',
            'created_at' => '2025-01-01T00:00:00.000000Z',
            'chat_messages' => [
                ['uuid' => 'm1', 'sender' => 'oracle', 'created_at' => '2025-01-01T00:00:00.000000Z', 'text' => 'something'],
            ],
        ]];

        $conversations = $this->parse(
            new ClaudeArchiveAdapter(),
            $this->zip('odd.zip', ['conversations.json' => json_encode($record)]),
        );

        $this->assertSame(NormalizedMessage::ROLE_UNKNOWN, $conversations[0]->messages[0]->role);
        $this->assertNotEmpty($conversations[0]->warnings);
    }

    // Gemini.

    public function test_gemini_adapter_detects_a_takeout_activity_export(): void
    {
        $detection = (new GeminiTakeoutArchiveAdapter())->detect($this->geminiArchive());

        $this->assertTrue($detection->supported);
        $this->assertSame('activity_log', $detection->variant);
    }

    public function test_gemini_html_only_export_is_refused_with_the_fix(): void
    {
        $source = $this->zip('gemini-html.zip', [
            'Takeout/My Activity/Gemini Apps/MyActivity.html' => '<html><body>activity</body></html>',
        ]);

        $detection = (new GeminiTakeoutArchiveAdapter())->detect($source);

        $this->assertTrue($detection->recognized);
        $this->assertFalse($detection->supported);
        $this->assertStringContainsString('change the activity record format to JSON', (string) $detection->reason);
    }

    public function test_gemini_prompt_and_response_are_extracted(): void
    {
        $conversations = $this->parse(new GeminiTakeoutArchiveAdapter(), $this->geminiArchive());
        $first = $conversations[0];

        $this->assertCount(2, $first->messages);
        $this->assertSame(NormalizedMessage::ROLE_USER, $first->messages[0]->role);
        $this->assertStringContainsString('two lease offers', $first->messages[0]->text);
        $this->assertSame(NormalizedMessage::ROLE_ASSISTANT, $first->messages[1]->role);
        $this->assertStringContainsString('shorter but costs more', $first->messages[1]->text);
        $this->assertStringContainsString('- Twelve months', $first->messages[1]->text);
    }

    public function test_gemini_response_html_is_converted_and_scripts_are_dropped(): void
    {
        $conversations = $this->parse(new GeminiTakeoutArchiveAdapter(), $this->geminiArchive());
        $last = end($conversations);
        $response = $last->messages[1]->text;

        $this->assertStringContainsString('going to pass this time', $response);
        $this->assertStringNotContainsString('<script>', $response);
        $this->assertStringNotContainsString('alert(1)', $response);
    }

    public function test_gemini_records_are_marked_as_activity_grain(): void
    {
        $conversations = $this->parse(new GeminiTakeoutArchiveAdapter(), $this->geminiArchive());

        $this->assertSame('activity_record', $conversations[0]->providerMetadata['grain']);
    }

    public function test_gemini_prompt_without_a_response_is_kept_and_flagged(): void
    {
        $conversations = $this->parse(new GeminiTakeoutArchiveAdapter(), $this->geminiArchive());
        $second = $conversations[1];

        $this->assertCount(1, $second->messages);
        $this->assertNotEmpty($second->warnings);
    }

    public function test_gemini_ignores_records_from_other_google_products(): void
    {
        $adapter = new GeminiTakeoutArchiveAdapter();
        $conversations = $this->parse($adapter, $this->geminiArchive());

        // Four records in, one of them a Search entry.
        $this->assertCount(3, $conversations);
        $this->assertNotEmpty(array_filter(
            $adapter->warnings(),
            static fn (string $warning) => str_contains($warning, 'other than Gemini Apps'),
        ));
    }

    // Registry.

    public function test_registry_routes_each_archive_to_the_right_adapter(): void
    {
        $registry = $this->registry();

        [$chatgpt] = $registry->detect($this->chatGptArchive());
        [$claude] = $registry->detect($this->claudeArchive());
        [$gemini] = $registry->detect($this->geminiArchive());

        $this->assertSame('chatgpt', $chatgpt?->provider());
        $this->assertSame('claude', $claude?->provider());
        $this->assertSame('gemini', $gemini?->provider());
    }

    public function test_registry_surfaces_a_recognized_but_unsupported_reason(): void
    {
        $source = $this->zip('gemini-html-2.zip', [
            'Takeout/My Activity/Gemini Apps/MyActivity.html' => '<html></html>',
        ]);

        [$adapter, $detection] = $this->registry()->detect($source);

        $this->assertNull($adapter);
        $this->assertStringContainsString('MyActivity.json', (string) $detection->reason);
    }

    public function test_registry_reports_an_unrecognized_archive(): void
    {
        $source = $this->zip('random.zip', ['notes.txt' => 'nothing to see']);

        [$adapter, $detection] = $this->registry()->detect($source);

        $this->assertNull($adapter);
        $this->assertStringContainsString('No registered adapter recognized', (string) $detection->reason);
    }

    public function test_forcing_a_provider_that_cannot_read_the_archive_fails_clearly(): void
    {
        [$adapter, $detection] = $this->registry()->detect($this->claudeArchive(), 'chatgpt');

        $this->assertNull($adapter);
        $this->assertStringContainsString('did not recognize this archive', (string) $detection->reason);
    }

    private function registry(): ConversationArchiveRegistry
    {
        return new ConversationArchiveRegistry([
            new ChatGptArchiveAdapter(),
            new ClaudeArchiveAdapter(),
            new GeminiTakeoutArchiveAdapter(),
        ]);
    }
}
