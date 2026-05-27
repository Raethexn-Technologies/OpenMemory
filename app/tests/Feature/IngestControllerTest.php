<?php

namespace Tests\Feature;

use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\Ingest\IngestSummarizer;
use App\Services\MemoryGraphService;
use App\Services\RedactionResult;
use App\Services\RedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * End-to-end behaviour of the ingest HTTP flow. The pipeline enforces several
 * trust boundaries that are easy to regress accidentally, so each is asserted
 * here as a separate test:
 *
 *   - Redaction runs before any LLM call (no raw token text reaches summarizer).
 *   - Public items land in the local graph; mock mode also writes to ICP.
 *   - Non-public items are dropped silently — never written to ICP.
 *   - The dedup cursor advances only to items that succeeded.
 *   - Repo overrides via request body are rejected in live mode.
 */
class IngestControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_public_item_lands_in_graph_and_in_mock_icp(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-pub', 'commit' => ['message' => 'Ship feature X', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $this->bindRedactionPassthrough();
        $this->bindSummarizer(['type' => 'public', 'content' => 'User shipped feature X']);
        $this->bindGraphExtractor();

        $graph = Mockery::mock(MemoryGraphService::class);
        $graph->shouldReceive('storeNode')
            ->once()
            ->andReturn(new \App\Models\MemoryNode(['id' => 'node-1']));
        $this->app->instance(MemoryGraphService::class, $graph);

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $icp->shouldReceive('storeMemory')->once()->andReturn('icp-1');
        $this->app->instance(IcpMemoryService::class, $icp);

        $response = $this->withSession(['chat_user_id' => 'test-user', 'chat_session_id' => 'sess-1'])
            ->postJson('/api/ingest/github', ['repos' => ['owner/repo']]);

        $response->assertOk();
        $response->assertJsonPath('summary.stored', 1);
        $response->assertJsonPath('summary.non_public_dropped', 0);

        // Cursor advanced to the successful SHA.
        $this->assertSame('sha-pub', Cache::get('ingest:github:lastsha:test-user:owner/repo'));
    }

    public function test_public_item_skips_icp_write_in_live_mode(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-pub', 'commit' => ['message' => 'Live mode commit', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $this->bindRedactionPassthrough();
        $this->bindSummarizer(['type' => 'public', 'content' => 'Public memory']);
        $this->bindGraphExtractor();

        $graph = Mockery::mock(MemoryGraphService::class);
        $graph->shouldReceive('storeNode')->once()->andReturn(new \App\Models\MemoryNode(['id' => 'node-1']));
        $this->app->instance(MemoryGraphService::class, $graph);

        // Live mode: the adapter rejects /store, so the pipeline must skip it.
        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(false);
        $icp->shouldNotReceive('storeMemory');
        $this->app->instance(IcpMemoryService::class, $icp);

        // Live mode also forbids request-supplied repo overrides — use config.
        config(['services.ingest.repos' => 'owner/repo']);

        $response = $this->withSession(['chat_user_id' => 'test-user'])->postJson('/api/ingest/github');

        $response->assertOk();
        $response->assertJsonPath('summary.stored', 1);
    }

    public function test_non_public_item_is_dropped_and_never_reaches_icp(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-priv', 'commit' => ['message' => 'private note', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $this->bindRedactionPassthrough();
        $this->bindSummarizer(['type' => 'private', 'content' => 'Some private fact']);

        // Graph extractor and graph storage must not be called for non-public.
        $graphExtractor = Mockery::mock(GraphExtractionService::class);
        $graphExtractor->shouldNotReceive('extract');
        $this->app->instance(GraphExtractionService::class, $graphExtractor);

        $graph = Mockery::mock(MemoryGraphService::class);
        $graph->shouldNotReceive('storeNode');
        $this->app->instance(MemoryGraphService::class, $graph);

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $icp->shouldNotReceive('storeMemory');
        $this->app->instance(IcpMemoryService::class, $icp);

        $response = $this->withSession(['chat_user_id' => 'test-user'])
            ->postJson('/api/ingest/github', ['repos' => ['owner/repo']]);

        $response->assertOk();
        $response->assertJsonPath('summary.stored', 0);
        $response->assertJsonPath('summary.non_public_dropped', 1);

        // A "dropped" item is still terminal — its SHA advances the cursor so
        // we don't re-classify it every sweep.
        $this->assertSame('sha-priv', Cache::get('ingest:github:lastsha:test-user:owner/repo'));
    }

    public function test_redaction_runs_before_summarizer_sees_text(): void
    {
        // AKIAIOSFODNN7EXAMPLE is a canonical example AWS key. The redactor
        // matches it via the floor 'credential' category — proving the
        // wiring without depending on test-only mock behaviour.
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-1', 'commit' => [
                    'message' => 'Add AWS access key AKIAIOSFODNN7EXAMPLE to deploy script',
                    'author' => ['name' => 'A', 'date' => null],
                ]],
            ], 200),
        ]);

        $icp = Mockery::mock(IcpMemoryService::class)->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(IngestSummarizer::class);
        $summarizer->shouldReceive('extract')
            ->once()
            ->withArgs(function ($source, $text) {
                // The raw AWS key must not reach the LLM.
                return ! str_contains($text, 'AKIAIOSFODNN7EXAMPLE')
                    && str_contains($text, '[CREDENTIAL');
            })
            // Mocking a sensitive return here would force the dropped-path test,
            // which we already cover. Returning null keeps this test focused
            // strictly on what reaches the summarizer.
            ->andReturn(null);
        $this->app->instance(IngestSummarizer::class, $summarizer);

        $response = $this->withSession(['chat_user_id' => 'test-user'])
            ->postJson('/api/ingest/github', ['repos' => ['owner/repo']]);

        $response->assertOk();
    }

    public function test_errors_do_not_advance_cursor(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-err', 'commit' => ['message' => 'will error', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $this->bindRedactionPassthrough();

        $summarizer = Mockery::mock(IngestSummarizer::class);
        $summarizer->shouldReceive('extract')->andThrow(new \RuntimeException('LLM down'));
        $this->app->instance(IngestSummarizer::class, $summarizer);

        $icp = Mockery::mock(IcpMemoryService::class)->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $this->app->instance(IcpMemoryService::class, $icp);

        $response = $this->withSession(['chat_user_id' => 'test-user'])
            ->postJson('/api/ingest/github', ['repos' => ['owner/repo']]);

        $response->assertOk();
        $response->assertJsonPath('summary.errors', 1);
        $response->assertJsonPath('summary.stored', 0);

        // Cursor must stay null — next sweep should retry this commit.
        $this->assertNull(Cache::get('ingest:github:lastsha:test-user:owner/repo'));
    }

    public function test_repo_override_is_forbidden_in_live_mode(): void
    {
        $icp = Mockery::mock(IcpMemoryService::class)->shouldIgnoreMissing();
        $icp->shouldReceive('isMockMode')->andReturn(false);
        $this->app->instance(IcpMemoryService::class, $icp);

        config(['services.ingest.repos' => 'configured/repo']);

        $response = $this->withSession(['chat_user_id' => 'test-user'])
            ->postJson('/api/ingest/github', ['repos' => ['attacker/private-repo']]);

        $response->assertStatus(403);
        $this->assertStringContainsString('not allowed in live mode', $response->json('error'));
    }

    public function test_missing_session_returns_422(): void
    {
        $response = $this->postJson('/api/ingest/github', ['repos' => ['owner/repo']]);
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'No user identity. Open /chat first.');
    }

    public function test_no_repos_returns_422(): void
    {
        config(['services.ingest.repos' => '']);

        $response = $this->withSession(['chat_user_id' => 'test-user'])
            ->postJson('/api/ingest/github');

        $response->assertStatus(422);
        $this->assertStringContainsString('No repos configured', $response->json('error'));
    }

    // ----- helpers ----------------------------------------------------------

    /** Bind a passthrough redactor so tests not focused on redaction stay readable. */
    private function bindRedactionPassthrough(): void
    {
        $redactor = Mockery::mock(RedactionService::class);
        $redactor->shouldReceive('redact')
            ->andReturnUsing(fn (string $text) => new RedactionResult($text));
        $redactor->shouldReceive('enforceSensitivity')
            ->andReturnUsing(fn (string $proposed) => $proposed);
        $this->app->instance(RedactionService::class, $redactor);
    }

    private function bindSummarizer(array $extract): void
    {
        $summarizer = Mockery::mock(IngestSummarizer::class);
        $summarizer->shouldReceive('extract')->andReturn($extract);
        $this->app->instance(IngestSummarizer::class, $summarizer);
    }

    private function bindGraphExtractor(): void
    {
        $extractor = Mockery::mock(GraphExtractionService::class);
        $extractor->shouldReceive('extract')->andReturn([
            'type' => 'memory',
            'label' => 'Test label',
            'tags' => ['ingest'],
            'people' => [],
            'projects' => [],
            'sensitivity' => 'public',
        ]);
        $this->app->instance(GraphExtractionService::class, $extractor);
    }
}
