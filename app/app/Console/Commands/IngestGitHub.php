<?php

namespace App\Console\Commands;

use App\Services\Ingest\GitHubIngestService;
use App\Services\Ingest\IngestPipeline;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sweep one or more GitHub repos and write durable memories for new commits.
 *
 * Sources: passed via --repo (repeatable) or the INGEST_GITHUB_REPOS config
 * (comma-separated owner/repo slugs). The user identity is required because
 * dedup state and ICP writes are per-user; pass it explicitly so this
 * command can run in a cron context where there is no HTTP session.
 *
 *   php artisan ingest:github --user=alice --repo=owner/repo1 --repo=owner/repo2
 *   php artisan ingest:github --user=alice          # uses configured repos
 */
class IngestGitHub extends Command
{
    protected $signature = 'ingest:github
                            {--user= : The user_id to attribute memories to (required)}
                            {--repo=* : Repo slug owner/name. Repeatable. Defaults to INGEST_GITHUB_REPOS.}
                            {--limit= : Max commits to inspect per repo (default: INGEST_PER_REPO_LIMIT)}';

    protected $description = 'Sweep GitHub repos for new commits and store them as memories.';

    public function handle(GitHubIngestService $github, IngestPipeline $pipeline): int
    {
        $userId = $this->option('user');
        if (! is_string($userId) || $userId === '') {
            $this->error('--user is required.');
            return self::FAILURE;
        }

        $repos = (array) $this->option('repo');
        if (empty($repos)) {
            $configured = (string) config('services.ingest.repos', '');
            $repos = array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        if (empty($repos)) {
            $this->error('No repos specified. Pass --repo or set INGEST_GITHUB_REPOS.');
            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?? config('services.ingest.per_repo_limit', 20));
        $sessionId = 'ingest_github_' . date('Ymd_His');

        $totals = ['processed' => 0, 'stored' => 0, 'skipped' => 0, 'non_public_dropped' => 0, 'errors' => 0];

        foreach ($repos as $repo) {
            $this->line("Fetching {$repo}…");
            try {
                $items = $github->fetchCommits($userId, $repo, $limit);
            } catch (Throwable $e) {
                $this->warn("  fetch failed: {$e->getMessage()}");
                $totals['errors']++;
                continue;
            }

            if (empty($items)) {
                $this->line("  no new commits");
                continue;
            }

            $result = $pipeline->run($userId, $sessionId, $items);

            // Advance the dedup cursor only when something terminal succeeded.
            if (! empty($result['last_success_external_id'])) {
                $github->markProcessed($userId, $repo, $result['last_success_external_id']);
            }

            $this->line(sprintf(
                '  %d processed → %d stored, %d skipped, %d non-public dropped, %d errors',
                $result['processed'],
                $result['stored'],
                $result['skipped'],
                $result['non_public_dropped'],
                $result['errors'],
            ));

            foreach (['processed', 'stored', 'skipped', 'non_public_dropped', 'errors'] as $k) {
                $totals[$k] += $result[$k];
            }
        }

        $this->info(sprintf(
            'Done. %d processed → %d stored, %d skipped, %d non-public dropped, %d errors.',
            $totals['processed'],
            $totals['stored'],
            $totals['skipped'],
            $totals['non_public_dropped'],
            $totals['errors'],
        ));

        return self::SUCCESS;
    }
}
