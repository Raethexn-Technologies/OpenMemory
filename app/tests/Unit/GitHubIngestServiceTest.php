<?php

namespace Tests\Unit;

use App\Services\Ingest\GitHubIngestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Network-free tests for GitHubIngestService. Http::fake() intercepts every
 * outbound call so the suite can be run without a token or internet.
 */
class GitHubIngestServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_invalid_repo_slug_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid GitHub repo slug');

        (new GitHubIngestService())->fetchCommits('user-1', 'not-a-slug');
    }

    public function test_fetches_commits_and_maps_to_pipeline_items(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                [
                    'sha' => 'abc1234567890',
                    'html_url' => 'https://github.com/owner/repo/commit/abc1234567890',
                    'commit' => [
                        'message' => 'Add task-routed LLM dispatcher',
                        'author' => ['name' => 'Anthony', 'date' => '2026-05-25T12:00:00Z'],
                    ],
                    'author' => ['login' => 'anthony'],
                ],
                [
                    'sha' => 'def4567890123',
                    'html_url' => 'https://github.com/owner/repo/commit/def4567890123',
                    'commit' => [
                        'message' => 'Fix preprocessor URL stripping',
                        'author' => ['name' => 'Anthony', 'date' => '2026-05-24T09:00:00Z'],
                    ],
                ],
            ], 200),
        ]);

        $items = (new GitHubIngestService())->fetchCommits('user-1', 'owner/repo', 10);

        $this->assertCount(2, $items);
        $this->assertSame('github:owner/repo', $items[0]['source_label']);
        $this->assertSame('abc1234567890', $items[0]['external_id']);
        $this->assertStringContainsString('abc1234', $items[0]['text']);
        $this->assertStringContainsString('Add task-routed LLM dispatcher', $items[0]['text']);
        $this->assertSame('github_commit', $items[0]['metadata']['source']);
        $this->assertSame('owner/repo', $items[0]['metadata']['repo']);
    }

    public function test_fetch_does_not_advance_cursor_on_its_own(): void
    {
        // Cursor discipline: fetchCommits must not mark anything seen. A
        // transient downstream failure would otherwise silently drop commits
        // that were fetched but never persisted.
        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'sha-newest', 'commit' => ['message' => 'Newest', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $service = new GitHubIngestService();
        $service->fetchCommits('user-1', 'owner/repo');

        $this->assertNull(Cache::get('ingest:github:lastsha:user-1:owner/repo'));
    }

    public function test_mark_processed_advances_cursor(): void
    {
        $service = new GitHubIngestService();
        $service->markProcessed('user-1', 'owner/repo', 'sha-success');

        $this->assertSame('sha-success', Cache::get('ingest:github:lastsha:user-1:owner/repo'));
    }

    public function test_dedup_stops_at_last_processed_sha(): void
    {
        Cache::put('ingest:github:lastsha:user-1:owner/repo', 'def4567890123', now()->addDay());

        Http::fake([
            'api.github.com/*' => Http::response([
                ['sha' => 'new1', 'commit' => ['message' => 'New 1', 'author' => ['name' => 'A', 'date' => null]]],
                ['sha' => 'new2', 'commit' => ['message' => 'New 2', 'author' => ['name' => 'A', 'date' => null]]],
                ['sha' => 'def4567890123', 'commit' => ['message' => 'Old', 'author' => ['name' => 'A', 'date' => null]]],
                ['sha' => 'older', 'commit' => ['message' => 'Older still', 'author' => ['name' => 'A', 'date' => null]]],
            ], 200),
        ]);

        $items = (new GitHubIngestService())->fetchCommits('user-1', 'owner/repo');

        $this->assertCount(2, $items);
        $this->assertSame(['new1', 'new2'], array_column($items, 'external_id'));

        // Cursor is unchanged — caller is responsible for advancing it.
        $this->assertSame('def4567890123', Cache::get('ingest:github:lastsha:user-1:owner/repo'));
    }

    public function test_404_response_throws_with_meaningful_message(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 404),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or not accessible');

        (new GitHubIngestService())->fetchCommits('user-1', 'owner/private-repo');
    }
}
