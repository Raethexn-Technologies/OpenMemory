<?php

namespace App\Http\Controllers;

use App\Services\IcpMemoryService;
use App\Services\Ingest\GitHubIngestService;
use App\Services\Ingest\IngestPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * HTTP endpoints for the manual "Ingest Now" flow.
 *
 * Repo selection trust model:
 *   - The server-configured INGEST_GITHUB_REPOS is the authoritative allowlist.
 *   - In live (non-mock) mode the request body cannot override the allowlist —
 *     a session that could ask the server to fetch any repo the server's
 *     GitHub token can read would amount to repo-read-as-a-service.
 *   - In mock mode the request body may supply repos for demo convenience.
 *     Mock-mode runs do not write to ICP and the local cache is the only
 *     side-effect surface, so the blast radius is bounded to the demo session.
 */
class IngestController extends Controller
{
    public function __construct(
        private readonly GitHubIngestService $github,
        private readonly IngestPipeline $pipeline,
        private readonly IcpMemoryService $icp,
    ) {}

    /**
     * Trigger an ingest sweep across the configured repos for the current user.
     */
    public function github(Request $request)
    {
        $userId = session()->get('chat_user_id');
        if (! $userId) {
            return response()->json(['error' => 'No user identity. Open /chat first.'], 422);
        }

        try {
            $repos = $this->resolveRepos($request);
        } catch (RepoOverrideForbiddenException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        if (empty($repos)) {
            return response()->json([
                'error' => 'No repos configured. Set INGEST_GITHUB_REPOS in .env.',
            ], 422);
        }

        $limit = (int) $request->input('limit', config('services.ingest.per_repo_limit', 20));
        $sessionId = (string) session()->get('chat_session_id', (string) Str::uuid());

        $summary = ['processed' => 0, 'stored' => 0, 'skipped' => 0, 'non_public_dropped' => 0, 'errors' => 0];
        $repoErrors = [];

        foreach ($repos as $repo) {
            try {
                $items = $this->github->fetchCommits($userId, $repo, $limit);
            } catch (Throwable $e) {
                $repoErrors[$repo] = $e->getMessage();
                $summary['errors']++;
                continue;
            }

            if (empty($items)) {
                continue;
            }

            $result = $this->pipeline->run($userId, $sessionId, $items);

            // Advance the dedup cursor only to the newest item whose downstream
            // processing actually succeeded. Items that errored remain
            // eligible for the next sweep; pipeline correctness depends on
            // this — see GitHubIngestService class docblock.
            if (! empty($result['last_success_external_id'])) {
                $this->github->markProcessed($userId, $repo, $result['last_success_external_id']);
            }

            foreach (['processed', 'stored', 'skipped', 'non_public_dropped', 'errors'] as $k) {
                $summary[$k] += $result[$k];
            }
        }

        cache()->put("ingest:last_run:{$userId}", now()->toIso8601String(), now()->addDays(30));

        return response()->json([
            'summary'     => $summary,
            'repo_errors' => (object) $repoErrors,
            'repos'       => $repos,
            'last_run_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<int, string>
     *
     * @throws RepoOverrideForbiddenException
     */
    private function resolveRepos(Request $request): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.ingest.repos', '')),
        )));

        $raw = $request->input('repos');
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        $requested = is_array($raw)
            ? array_values(array_filter(array_map('trim', $raw)))
            : [];

        if (empty($requested)) {
            return $configured;
        }

        // Live mode: request override is rejected outright. Letting any
        // session enumerate or fetch repos via the server's GitHub token is
        // a confused-deputy problem we are not in a position to solve safely
        // without per-user GitHub auth.
        if (! $this->icp->isMockMode()) {
            throw new RepoOverrideForbiddenException(
                'Repo override is not allowed in live mode. Set INGEST_GITHUB_REPOS in .env.'
            );
        }

        return $requested;
    }
}

/**
 * Internal sentinel — the only caller is IngestController. Lifted to a
 * top-level class so PHP's exception hierarchy works without ceremony.
 */
class RepoOverrideForbiddenException extends \RuntimeException {}
