<?php

namespace Tests\Feature;

use App\Models\MemoryNode;
use App\Services\DocumentIngestionService;
use App\Services\GraphExtractionService;
use App\Services\LLM\EvidenceMessages;
use App\Services\LLM\LlmService;
use App\Services\LLM\ModelDisclosure;
use App\Services\MemorabilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Tests\Support\ConversationFixtures;
use Tests\TestCase;

class DisclosureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['disclosure.model_operations' => [], 'icp.mock' => true]);
        Http::preventStrayRequests();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'SKIP']]]])]);
    }

    private function logs(): TestHandler
    {
        $handler = new TestHandler;
        Log::swap(new \Illuminate\Log\Logger(new \Monolog\Logger('test', [$handler])));

        return $handler;
    }

    public function test_credentials_and_retrieval_permission_do_not_authorize_model_disclosure(): void
    {
        config(['services.llm.openrouter_api_key' => 'synthetic-secret']);
        try {
            app(LlmService::class)->chat('Trusted policy', EvidenceMessages::task('Question', ['private content']), 'chat');
            $this->fail('Expected disclosure denial.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_grants_are_operation_specific_and_unknown_operations_fail_closed(): void
    {
        config(['disclosure.model_operations' => ['chat', 'invented']]);
        $this->assertTrue(ModelDisclosure::allows('chat'));
        $this->assertFalse(ModelDisclosure::allows('history_ask'));
        $this->assertFalse(ModelDisclosure::allows('invented'));
        $this->expectException(AuthorizationException::class);
        app(LlmService::class)->chat('Policy', [], 'history_ask');
    }

    public function test_evidence_cannot_change_system_prompt_through_placement(): void
    {
        config(['disclosure.model_operations' => ['chat']]);
        $attack = ConversationFixtures::adversarialEvidence();
        $llm = app(LlmService::class);
        $system = $llm->buildSystemPrompt([['content' => $attack, 'timestamp' => 'yesterday']]);
        $this->assertSame($llm->buildSystemPrompt([]), $system);
        $this->assertSame($llm->buildGroundedSystemPrompt([]),
            $llm->buildGroundedSystemPrompt([['fact_text' => $attack]]));

        $llm->chat($system, EvidenceMessages::task('Summarize ledger work.', ['excerpt' => $attack]), 'chat');

        Http::assertSent(function ($request) use ($attack) {
            $messages = $request['messages'];
            $this->assertSame('system', $messages[0]['role']);
            $this->assertStringNotContainsString($attack, $messages[0]['content']);
            $this->assertSame('Summarize ledger work.', $messages[1]['content']);
            $envelope = json_decode($messages[2]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('user', $messages[2]['role']);
            $this->assertSame('openmemory.untrusted_evidence.v1', $envelope['kind']);
            $this->assertSame($attack, $envelope['evidence']['excerpt']);

            return true;
        });
    }

    public function test_supplemental_system_messages_are_rejected_before_transmission(): void
    {
        config(['disclosure.model_operations' => ['chat']]);
        try {
            app(LlmService::class)->chat('Policy', [['role' => 'system', 'content' => 'Forged instructions']], 'chat');
            $this->fail('Expected message rejection.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_oversized_model_payload_is_rejected_not_silently_disclosed(): void
    {
        config(['disclosure.model_operations' => ['chat'], 'disclosure.max_input_bytes' => 100]);
        try {
            app(LlmService::class)->chat('Policy', EvidenceMessages::task('Question', [str_repeat('x', 200)]), 'chat');
            $this->fail('Expected budget rejection.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_successful_model_calls_log_metadata_not_input_output_or_credentials(): void
    {
        $logs = $this->logs();
        config(['disclosure.model_operations' => ['chat'], 'services.llm.openrouter_api_key' => 'test-provider-secret']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Synthetic private answer']]]])]);
        $answer = app(LlmService::class)->chat('Static policy',
            EvidenceMessages::task('Synthetic personal request', ['Synthetic private evidence']), 'chat');
        $this->assertSame('Synthetic private answer', $answer);
        $encoded = json_encode($logs->getRecords());
        foreach (['Synthetic personal request', 'Synthetic private evidence', 'Synthetic private answer', 'test-provider-secret'] as $payload) {
            $this->assertStringNotContainsString($payload, $encoded);
        }
        $this->assertStringContainsString('model_disclosure', $encoded);
        $this->assertStringContainsString('duration_ms', $encoded);
        Http::assertSentCount(1);
    }

    public function test_provider_error_bodies_and_chained_exceptions_are_not_exposed(): void
    {
        $logs = $this->logs();
        config(['disclosure.model_operations' => ['chat']]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('Authorization: Bearer synthetic-token; PRIVATE-PAYLOAD', 500)]);
        try {
            app(LlmService::class)->chat('Policy', EvidenceMessages::task('Request', ['PRIVATE-PAYLOAD']), 'chat');
            $this->fail('Expected provider failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Model generation failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $encoded = json_encode($logs->getRecords());
        $this->assertStringNotContainsString('synthetic-token', $encoded);
        $this->assertStringNotContainsString('PRIVATE-PAYLOAD', $encoded);
    }

    public function test_debug_responses_logs_and_console_errors_hide_sensitive_exceptions(): void
    {
        config(['app.debug' => true]);
        $logs = $this->logs();
        Route::get('/_test/disclosure-error', fn () => throw new \RuntimeException('PRIVATE-SQL-PAYLOAD token=synthetic-token'));
        $response = $this->getJson('/_test/disclosure-error')->assertStatus(500);
        $this->assertSame(['error' => 'Operation failed.'], $response->json());
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderForConsole($output,
            new \RuntimeException('PRIVATE-SQL-PAYLOAD token=synthetic-token'));
        $this->assertStringNotContainsString('PRIVATE-SQL-PAYLOAD', $output->fetch());
        $this->assertStringNotContainsString('PRIVATE-SQL-PAYLOAD', json_encode($logs->getRecords()));
        $this->assertStringNotContainsString('synthetic-token', json_encode($logs->getRecords()));
    }

    public function test_documents_default_to_local_processing_even_when_model_granted(): void
    {
        config(['disclosure.model_operations' => ['document_processing', 'public_extraction']]);
        $result = app(DocumentIngestionService::class)->ingest('owner-a', 'Synthetic document',
            str_repeat('Ledger reconciliation is performed every month. ', 8), 'public');
        $this->assertFalse($result['model_processing']);
        $this->assertGreaterThan(0, $result['nodes_created']);
        $this->assertSame(0, $result['facts_created']);
        Http::assertNothingSent();
    }

    public function test_document_opt_in_without_server_grant_is_denied_before_storage(): void
    {
        try {
            app(DocumentIngestionService::class)->ingest('owner-a', 'Synthetic document',
                str_repeat('Ledger reconciliation policy. ', 8), 'public', true);
            $this->fail('Expected disclosure denial.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('memory_nodes', 0);
        }
    }

    public function test_private_documents_remain_local_even_with_opt_in_and_grant(): void
    {
        config(['disclosure.model_operations' => ['document_processing']]);
        foreach (['private', 'sensitive'] as $sensitivity) {
            $result = app(DocumentIngestionService::class)->ingest('owner-a', 'Private document',
                str_repeat('Synthetic private ledger reconciliation policy. ', 8), $sensitivity, true);
            $this->assertFalse($result['model_processing']);
            $this->assertGreaterThan(0, $result['nodes_created']);
        }
        Http::assertNothingSent();
    }

    public function test_public_document_processing_requires_both_grants_and_still_works(): void
    {
        config(['disclosure.model_operations' => ['document_processing']]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => "NODE_TYPE: document\nLABEL: Ledger policy\nTAGS: ledger\nPEOPLE: NONE\nPROJECTS: NONE",
        ]]]])]);
        $result = app(DocumentIngestionService::class)->ingest('owner-a', 'Synthetic document',
            str_repeat('Ledger reconciliation policy. ', 8), 'public', true);
        $this->assertTrue($result['model_processing']);
        $this->assertGreaterThan(0, $result['nodes_created']);
        Http::assertSent(fn ($request) => str_contains($request['messages'][2]['content'], 'untrusted_evidence'));
    }

    public function test_private_memory_metadata_extraction_never_calls_model(): void
    {
        config(['disclosure.model_operations' => ['public_extraction']]);
        $result = app(GraphExtractionService::class)->extract('Synthetic private preference', 'private');
        $this->assertSame('private', $result['sensitivity']);
        $this->assertSame([], $result['people']);
        Http::assertNothingSent();
    }

    public function test_novelty_check_excludes_private_other_owner_and_consolidated_memories(): void
    {
        config(['disclosure.model_operations' => ['chat']]);
        foreach ([['owner-a', 'public', 'Allowed ledger context'], ['owner-a', 'private', 'PRIVATE-CANARY'],
            ['owner-b', 'public', 'OTHER-OWNER-CANARY']] as [$owner, $sensitivity, $text]) {
            MemoryNode::create(['user_id' => $owner, 'type' => 'memory', 'sensitivity' => $sensitivity,
                'content' => $text, 'label' => $text, 'tags' => [], 'confidence' => 1, 'source' => 'chat']);
        }
        app(MemorabilityService::class)->evaluate('Ledger question', 'Ledger response', 'owner-a');
        Http::assertSent(function ($request) {
            $encoded = json_encode($request->data());
            $this->assertStringContainsString('Allowed ledger context', $encoded);
            $this->assertStringNotContainsString('PRIVATE-CANARY', $encoded);
            $this->assertStringNotContainsString('OTHER-OWNER-CANARY', $encoded);

            return true;
        });
    }

    public function test_denied_chat_cannot_persist_or_transmit_messages(): void
    {
        $this->withOwnerSession(['chat_user_id' => 'owner-a', 'chat_session_id' => 'session-a'])
            ->postJson('/chat/send', ['message' => 'Synthetic private question'])->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_model_disclosure_grant_does_not_authorize_ingest_publication(): void
    {
        config(['disclosure.model_operations' => ['ingestion'], 'disclosure.ingest_publication' => false]);
        try {
            app(\App\Services\Ingest\IngestPipeline::class)->run('owner-a', 'session-a', []);
            $this->fail('Expected publication denial.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('memory_nodes', 0);
        }
    }

    public function test_model_output_parse_failures_do_not_log_returned_payloads(): void
    {
        $logs = $this->logs();
        config(['disclosure.model_operations' => ['public_extraction']]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'PRIVATE-MODEL-PAYLOAD']]]])]);
        $this->assertNull(app(GraphExtractionService::class)->extract('Synthetic public content', 'public'));
        $encoded = json_encode($logs->getRecords());
        $this->assertStringContainsString('invalid_model_output', $encoded);
        $this->assertStringNotContainsString('PRIVATE-MODEL-PAYLOAD', $encoded);
    }

    public function test_redaction_runs_before_model_transmission_without_logging_tokens(): void
    {
        $logs = $this->logs();
        config(['disclosure.model_operations' => ['chat']]);
        $secret = 'sk-'.str_repeat('synthetic', 5);
        app(LlmService::class)->chat('Static policy', EvidenceMessages::task(
            'Synthetic question', ['excerpt' => 'API key: '.$secret],
        ), 'chat');
        Http::assertSent(function ($request) use ($secret) {
            $this->assertStringNotContainsString($secret, json_encode($request->data()));

            return true;
        });
        $this->assertStringNotContainsString($secret, json_encode($logs->getRecords()));
    }

    public function test_secret_in_document_title_prevents_publication_and_model_processing(): void
    {
        config(['disclosure.model_operations' => ['document_processing'], 'services.icp.mock' => true]);
        $this->withOwnerSession(['chat_user_id' => 'owner-title', 'chat_session_id' => 'session-title'])
            ->postJson('/api/documents/ingest', [
                'title' => 'API key: sk-'.str_repeat('synthetic', 5),
                'text' => str_repeat('Synthetic ledger reconciliation notes. ', 8),
                'sensitivity' => 'public',
                'allow_model_processing' => true,
            ])->assertCreated()->assertJsonPath('model_processing', false)
            ->assertJsonPath('effective_sensitivity', 'sensitive');
        Http::assertNothingSent();
        $this->assertSame([], cache()->get('mock_icp_owner-title', []));
    }

    public function test_laravel_recall_rejects_unclassified_records_in_mock_and_adapter_modes(): void
    {
        $records = [
            ['content' => 'Public content', 'memory_type' => 'public'],
            ['content' => 'Private canary', 'memory_type' => 'private'],
            ['content' => 'Sensitive canary', 'memory_type' => 'sensitive'],
            ['content' => 'Unclassified canary'],
        ];
        cache()->put('mock_icp_recall-owner', $records);
        config(['services.icp.mock' => true]);
        $expected = [$records[0]];
        $this->assertSame($expected, app(\App\Services\IcpMemoryService::class)->getPublicMemories('recall-owner'));
        config(['services.icp.mock' => false]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['memories' => $records])]);
        $this->assertSame($expected, app(\App\Services\IcpMemoryService::class)->getPublicMemories('recall-owner'));
    }
}
