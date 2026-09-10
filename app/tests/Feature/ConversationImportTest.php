<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationImport;
use App\Models\ConversationMessage;
use App\Models\ConversationRawRecord;
use App\Services\Conversations\Archive\ArchiveException;
use App\Services\Conversations\ConversationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

/**
 * End-to-end import behaviour: normalization, idempotency, provenance, and the
 * privacy boundaries that separate the preserved source from everything derived.
 */
class ConversationImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $userId = 'test-owner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = ConversationFixtures::scratchDir('import');
    }

    protected function tearDown(): void
    {
        ConversationFixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function importer(): ConversationImportService
    {
        return app(ConversationImportService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $conversations
     */
    private function chatGptZip(string $name = 'chatgpt.zip', ?array $conversations = null): string
    {
        return ConversationFixtures::writeZip($this->dir . '/' . $name, [
            'conversations.json' => json_encode($conversations ?? ConversationFixtures::chatGptConversations(), JSON_UNESCAPED_UNICODE),
            'user.json' => '{"id":"user-synthetic"}',
        ]);
    }

    private function claudeZip(string $name = 'claude.zip'): string
    {
        return ConversationFixtures::writeZip($this->dir . '/' . $name, [
            'conversations.json' => json_encode(ConversationFixtures::claudeConversations(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function geminiZip(string $name = 'gemini.zip'): string
    {
        return ConversationFixtures::writeZip($this->dir . '/' . $name, [
            'Takeout/My Activity/Gemini Apps/MyActivity.json' => json_encode(ConversationFixtures::geminiActivityRecords(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function test_chatgpt_archive_imports_conversations_and_messages(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->chatGptZip());

        $this->assertSame('chatgpt', $result['provider']);
        $this->assertSame(3, $result['report']->conversationsSeen);
        $this->assertSame(3, $result['report']->conversationsNew);
        $this->assertSame(0, $result['report']->conversationsUnchanged);

        $this->assertSame(3, Conversation::where('user_id', $this->userId)->count());
        // Eight, not nine: the hidden system placeholder in the second fixture
        // conversation carries no body, so it is not stored as a message.
        $this->assertSame(8, ConversationMessage::where('user_id', $this->userId)->count());
    }

    public function test_conversation_row_records_derived_times_and_models(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip());

        $conversation = Conversation::where('provider_conversation_id', 'conv-chatgpt-0001')->firstOrFail();

        $this->assertSame(6, $conversation->message_count);
        $this->assertSame('2024-06-01T12:00:00+00:00', $conversation->first_message_at->toIso8601String());
        $this->assertSame('2024-06-01T12:04:20+00:00', $conversation->last_message_at->toIso8601String());
        $this->assertSame(['gpt-synthetic-1'], $conversation->models);
        $this->assertSame('chatgpt-1', $conversation->parser_version);
    }

    public function test_imported_conversations_default_to_private(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip());

        $this->assertSame(
            0,
            Conversation::where('user_id', $this->userId)->where('visibility', '!=', 'private')->count(),
            'Imported history must never default to a visibility that could reach an external agent.',
        );
    }

    public function test_reimporting_the_same_archive_creates_no_duplicates(): void
    {
        $path = $this->chatGptZip();
        $this->importer()->importPath($this->userId, $path);
        $second = $this->importer()->importPath($this->userId, $path);

        $this->assertSame(3, $second['report']->conversationsSeen);
        $this->assertSame(0, $second['report']->conversationsNew);
        $this->assertSame(3, $second['report']->conversationsUnchanged);
        $this->assertSame(0, $second['report']->conversationsUpdated);
        $this->assertSame(8, $second['report']->messagesUnchanged);
        $this->assertSame(0, $second['report']->messagesNew);

        $this->assertSame(3, Conversation::where('user_id', $this->userId)->count());
        $this->assertSame(8, ConversationMessage::where('user_id', $this->userId)->count());
    }

    public function test_reimporting_the_same_archive_stores_one_copy_of_each_raw_record(): void
    {
        $path = $this->chatGptZip();
        $this->importer()->importPath($this->userId, $path);
        $second = $this->importer()->importPath($this->userId, $path);

        $this->assertSame(0, $second['report']->rawRecordsStored);
        $this->assertSame(3, $second['report']->rawRecordsAlreadyPresent);
        $this->assertSame(3, ConversationRawRecord::where('user_id', $this->userId)->count());
    }

    public function test_a_later_archive_merges_new_and_changed_conversations(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip('first.zip'));

        // A later export: one conversation gained a reply, and a new conversation exists.
        $conversations = ConversationFixtures::chatGptConversations();
        $conversations[0]['mapping']['node-5b'] = [
            'id' => 'node-5b',
            'parent' => 'node-4b',
            'children' => [],
            'message' => [
                'id' => 'msg-5b',
                'author' => ['role' => 'user'],
                'create_time' => 1717250000.0,
                'content' => ['content_type' => 'text', 'parts' => ['Agreed, we will start with SQLite.']],
                'metadata' => [],
            ],
        ];
        $conversations[0]['mapping']['node-4b']['children'] = ['node-5b'];
        $conversations[0]['current_node'] = 'node-5b';

        $conversations[] = [
            'conversation_id' => 'conv-chatgpt-0004',
            'title' => 'A brand new thread',
            'create_time' => 1725000000.0,
            'current_node' => 'node-d1',
            'mapping' => [
                'node-d1' => ['id' => 'node-d1', 'parent' => null, 'children' => [], 'message' => [
                    'id' => 'msg-d1',
                    'author' => ['role' => 'user'],
                    'create_time' => 1725000000.0,
                    'content' => ['content_type' => 'text', 'parts' => ['Starting a new project this month.']],
                    'metadata' => [],
                ]],
            ],
        ];

        $second = $this->importer()->importPath($this->userId, $this->chatGptZip('second.zip', $conversations));

        $this->assertSame(4, $second['report']->conversationsSeen);
        $this->assertSame(1, $second['report']->conversationsNew);
        $this->assertSame(1, $second['report']->conversationsUpdated);
        $this->assertSame(2, $second['report']->conversationsUnchanged);
        // One reply added to an existing conversation, plus the single message
        // of the conversation that did not exist before.
        $this->assertSame(2, $second['report']->messagesNew);

        $this->assertSame(4, Conversation::where('user_id', $this->userId)->count());
        $this->assertSame(
            7,
            Conversation::where('provider_conversation_id', 'conv-chatgpt-0001')->firstOrFail()->message_count,
        );
    }

    public function test_messages_absent_from_a_newer_archive_are_kept_and_reported(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip('full.zip'));

        // A later export where the user deleted the abandoned branch.
        $conversations = ConversationFixtures::chatGptConversations();
        unset($conversations[0]['mapping']['node-3a'], $conversations[0]['mapping']['node-4a']);
        $conversations[0]['mapping']['node-2']['children'] = ['node-3b'];

        $second = $this->importer()->importPath($this->userId, $this->chatGptZip('trimmed.zip', $conversations));

        $this->assertSame(
            6,
            Conversation::where('provider_conversation_id', 'conv-chatgpt-0001')->firstOrFail()->message_count,
            'Previously imported messages must survive their removal at the provider.',
        );

        $this->assertNotEmpty(array_filter(
            $second['report']->warnings(),
            static fn (string $warning) => str_contains($warning, 'were kept rather than deleted'),
        ));
    }

    public function test_importing_the_same_archive_under_two_owners_keeps_them_separate(): void
    {
        $path = $this->chatGptZip();
        $this->importer()->importPath('owner-a', $path);
        $this->importer()->importPath('owner-b', $path);

        $this->assertSame(3, Conversation::where('user_id', 'owner-a')->count());
        $this->assertSame(3, Conversation::where('user_id', 'owner-b')->count());
        $this->assertSame(6, ConversationRawRecord::count());
    }

    public function test_raw_source_is_preserved_verbatim(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip());

        $conversation = Conversation::where('provider_conversation_id', 'conv-chatgpt-0001')->firstOrFail();
        $raw = ConversationRawRecord::where('conversation_id', $conversation->id)->firstOrFail();

        $decoded = json_decode($raw->payload, true);

        $this->assertSame('conv-chatgpt-0001', $decoded['conversation_id']);
        // The abandoned branch is present in the source even though a summary
        // of the conversation would drop it.
        $this->assertArrayHasKey('node-4a', $decoded['mapping']);
        $this->assertSame(hash('sha256', $raw->payload), $raw->payload_sha256);
        $this->assertSame(strlen($raw->payload), $raw->payload_bytes);
    }

    public function test_provenance_chain_reaches_the_archive_file(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->chatGptZip());

        $message = ConversationMessage::where('provider_message_id', 'msg-4b')->firstOrFail();
        $conversation = $message->conversation;
        $import = ConversationImport::whereKey($conversation->last_import_id)->firstOrFail();

        $this->assertSame($result['import']->id, $import->id);
        $this->assertSame('chatgpt.zip', $import->source_label);
        $this->assertSame(64, strlen((string) $import->source_sha256));
        $this->assertSame(ConversationImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->stats['conversations_new']);
    }

    public function test_redaction_runs_on_message_text_but_not_on_the_raw_record(): void
    {
        $conversations = [[
            'conversation_id' => 'conv-secret',
            'title' => 'Deploy notes',
            'create_time' => 1717243200.0,
            'current_node' => 'n1',
            'mapping' => [
                'n1' => ['id' => 'n1', 'parent' => null, 'children' => [], 'message' => [
                    'id' => 'm1',
                    'author' => ['role' => 'user'],
                    'create_time' => 1717243200.0,
                    'content' => ['content_type' => 'text', 'parts' => ['The api key is sk-proj-abcdefghijklmnopqrstuvwxyz012345 for staging.']],
                    'metadata' => [],
                ]],
            ],
        ]];

        $result = $this->importer()->importPath($this->userId, $this->chatGptZip('secret.zip', $conversations));

        $message = ConversationMessage::where('provider_message_id', 'm1')->firstOrFail();
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnopqrstuvwxyz012345', $message->content_text);
        $this->assertNotNull($message->redaction);
        $this->assertSame(1, $result['report']->redactedMessages);

        // The preserved source is the authoritative copy and is not rewritten.
        // It never leaves the owner's machine and never enters a prompt.
        $raw = ConversationRawRecord::where('provider_conversation_id', 'conv-secret')->firstOrFail();
        $this->assertStringContainsString('sk-proj-abcdefghijklmnopqrstuvwxyz012345', $raw->payload);
    }

    public function test_redaction_categories_roll_up_onto_the_conversation(): void
    {
        $conversations = [[
            'conversation_id' => 'conv-secret-2',
            'title' => 'Keys',
            'create_time' => 1717243200.0,
            'current_node' => 'n1',
            'mapping' => [
                'n1' => ['id' => 'n1', 'parent' => null, 'children' => [], 'message' => [
                    'id' => 'm1',
                    'author' => ['role' => 'user'],
                    'create_time' => 1717243200.0,
                    'content' => ['content_type' => 'text', 'parts' => ['token is ghp_abcdefghijklmnopqrstuvwxyz0123456789']],
                    'metadata' => [],
                ]],
            ],
        ]];

        $this->importer()->importPath($this->userId, $this->chatGptZip('secret2.zip', $conversations));

        $conversation = Conversation::where('provider_conversation_id', 'conv-secret-2')->firstOrFail();
        $this->assertContains('credential', $conversation->redaction['categories']);
    }

    public function test_oversized_messages_are_truncated_in_the_normalized_copy_only(): void
    {
        $long = str_repeat('a very long sentence about project planning. ', 400);
        $conversations = [[
            'conversation_id' => 'conv-long',
            'title' => 'Long',
            'create_time' => 1717243200.0,
            'current_node' => 'n1',
            'mapping' => [
                'n1' => ['id' => 'n1', 'parent' => null, 'children' => [], 'message' => [
                    'id' => 'm1',
                    'author' => ['role' => 'user'],
                    'create_time' => 1717243200.0,
                    'content' => ['content_type' => 'text', 'parts' => [$long]],
                    'metadata' => [],
                ]],
            ],
        ]];

        config()->set('conversations.limits.max_message_chars', 500);

        $result = $this->importer()->importPath($this->userId, $this->chatGptZip('long.zip', $conversations));

        $message = ConversationMessage::where('provider_message_id', 'm1')->firstOrFail();
        $this->assertLessThan(mb_strlen($long), $message->char_count);
        $this->assertTrue($message->provider_metadata['truncated']);
        $this->assertNotEmpty(array_filter(
            $result['report']->warnings(),
            static fn (string $warning) => str_contains($warning, 'truncated'),
        ));

        $raw = ConversationRawRecord::where('provider_conversation_id', 'conv-long')->firstOrFail();
        $this->assertStringContainsString($long, $raw->payload);
    }

    public function test_duplicate_message_identifiers_within_a_conversation_are_skipped(): void
    {
        $record = [[
            'uuid' => 'conv-dupe',
            'name' => 'Duplicates',
            'created_at' => '2025-01-01T00:00:00.000000Z',
            'chat_messages' => [
                ['uuid' => 'dup-1', 'sender' => 'human', 'created_at' => '2025-01-01T00:00:00.000000Z', 'text' => 'first copy'],
                ['uuid' => 'dup-1', 'sender' => 'human', 'created_at' => '2025-01-01T00:00:05.000000Z', 'text' => 'second copy'],
            ],
        ]];

        $path = ConversationFixtures::writeZip($this->dir . '/dupe.zip', [
            'conversations.json' => json_encode($record),
        ]);

        $result = $this->importer()->importPath($this->userId, $path);

        $this->assertSame(1, ConversationMessage::where('user_id', $this->userId)->count());
        $this->assertSame(1, $result['report']->messagesSkipped);
        $this->assertStringContainsString('first copy', ConversationMessage::first()->content_text);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->chatGptZip(), ['dry_run' => true]);

        $this->assertSame(3, $result['report']->conversationsSeen);
        $this->assertSame(8, $result['report']->messagesSeen);
        $this->assertNull($result['import']);
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, ConversationImport::count());
    }

    public function test_limit_option_stops_early_and_says_so(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->chatGptZip(), ['limit' => 1]);

        $this->assertSame(1, Conversation::where('user_id', $this->userId)->count());
        $this->assertNotEmpty(array_filter(
            $result['report']->warnings(),
            static fn (string $warning) => str_contains($warning, 'limit of 1'),
        ));
    }

    public function test_no_raw_option_skips_the_preserved_source(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip(), ['store_raw' => false]);

        $this->assertSame(0, ConversationRawRecord::count());
        $this->assertSame(3, Conversation::count());
    }

    public function test_claude_archive_imports_with_its_own_provider_tag(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->claudeZip());

        $this->assertSame('claude', $result['provider']);
        $this->assertSame(3, Conversation::where('provider', 'claude')->count());
        $this->assertSame(5, ConversationMessage::where('provider', 'claude')->count());
    }

    public function test_gemini_archive_imports_activity_records(): void
    {
        $result = $this->importer()->importPath($this->userId, $this->geminiZip());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(3, Conversation::where('provider', 'gemini')->count());

        $first = Conversation::where('provider', 'gemini')->orderBy('first_message_at')->firstOrFail();
        $this->assertSame('activity_record', $first->provider_metadata['grain']);
    }

    public function test_three_providers_coexist_in_one_corpus(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip());
        $this->importer()->importPath($this->userId, $this->claudeZip());
        $this->importer()->importPath($this->userId, $this->geminiZip());

        $this->assertSame(9, Conversation::where('user_id', $this->userId)->count());
        $this->assertSame(
            ['chatgpt', 'claude', 'gemini'],
            Conversation::where('user_id', $this->userId)->distinct()->orderBy('provider')->pluck('provider')->all(),
        );
        $this->assertSame(3, ConversationImport::where('user_id', $this->userId)->count());
    }

    public function test_unreadable_archive_is_rejected_with_a_clear_message(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/nothing.zip', ['readme.txt' => 'hello']);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/No registered adapter recognized/');
        $this->importer()->importPath($this->userId, $path);
    }

    public function test_gemini_html_export_is_refused_with_the_specific_fix(): void
    {
        $path = ConversationFixtures::writeZip($this->dir . '/gem-html.zip', [
            'Takeout/My Activity/Gemini Apps/MyActivity.html' => '<html></html>',
        ]);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessageMatches('/format to JSON/');
        $this->importer()->importPath($this->userId, $path);
    }

    public function test_deleting_a_conversation_removes_its_messages_and_source(): void
    {
        $this->importer()->importPath($this->userId, $this->chatGptZip());

        $conversation = Conversation::where('provider_conversation_id', 'conv-chatgpt-0001')->firstOrFail();
        ConversationRawRecord::where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        $this->assertSame(0, ConversationMessage::where('conversation_id', $conversation->id)->count());
        $this->assertSame(0, ConversationRawRecord::where('conversation_id', $conversation->id)->count());
    }

    public function test_import_of_a_bare_json_file_works_without_a_zip(): void
    {
        $path = ConversationFixtures::writeJson(
            $this->dir . '/conversations.json',
            ConversationFixtures::claudeConversations(),
        );

        $result = $this->importer()->importPath($this->userId, $path);

        $this->assertSame('claude', $result['provider']);
        $this->assertSame(3, Conversation::count());
    }

    public function test_import_of_an_extracted_folder_works(): void
    {
        $folder = $this->dir . '/extracted';
        @mkdir($folder . '/Takeout/My Activity/Gemini Apps', 0777, true);
        file_put_contents(
            $folder . '/Takeout/My Activity/Gemini Apps/MyActivity.json',
            json_encode(ConversationFixtures::geminiActivityRecords(), JSON_UNESCAPED_UNICODE),
        );

        $result = $this->importer()->importPath($this->userId, $folder);

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(3, Conversation::count());
        // A folder has no archive-level hash; identity rests on content hashes.
        $this->assertNull($result['import']->source_sha256);
    }

    public function test_unicode_content_survives_the_round_trip(): void
    {
        $this->importer()->importPath($this->userId, $this->claudeZip());

        $message = ConversationMessage::where('provider_message_id', 'cmsg-3')->firstOrFail();

        $this->assertStringContainsString('Résumé', $message->content_text);
    }

    public function test_import_command_reports_and_writes(): void
    {
        $this->artisan('memory:import-archive', [
            'path' => $this->chatGptZip('cli.zip'),
            '--user' => $this->userId,
        ])
            ->expectsOutputToContain('3 conversations detected')
            ->assertExitCode(0);

        $this->assertSame(3, Conversation::where('user_id', $this->userId)->count());
    }

    public function test_import_command_requires_an_owner_identity(): void
    {
        config()->set('conversations.local_user_id', '');

        $this->artisan('memory:import-archive', ['path' => $this->chatGptZip('cli2.zip')])
            ->assertExitCode(1);

        $this->assertSame(0, Conversation::count());
    }

    public function test_import_command_dry_run_writes_nothing(): void
    {
        $this->artisan('memory:import-archive', [
            'path' => $this->chatGptZip('cli3.zip'),
            '--user' => $this->userId,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, Conversation::count());
    }
}
