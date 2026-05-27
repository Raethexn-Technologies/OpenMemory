<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetches recent commits from a GitHub repository.
 *
 * Scope is deliberately narrow for v1: read commits from one or more
 * `owner/repo` slugs, hand each commit to the caller as a structured item.
 * No issues, PRs, comments, or release feeds — those can extend the same
 * pattern later without changing the pipeline contract.
 *
 * Auth: a PAT in GITHUB_INGEST_TOKEN lifts the unauthenticated 60 req/hour
 * limit to 5000 and lets the service read private repos the token has access
 * to. Without a token public repos still work, just rate-limited.
 *
 * Dedup: the last successfully-processed SHA per (user, repo) is cached so
 * subsequent runs only return commits newer than that. The cache is
 * intentionally separate from the memory layer — a duplicate commit must
 * not become a duplicate memory, but losing the dedup state only means
 * re-ingesting, not corruption.
 *
 * Cursor advancement is the caller's job. fetchCommits reads the cursor and
 * returns new items but does NOT mark them seen. After processing, the
 * caller invokes markProcessed(userId, repoSlug, sha) with the newest SHA
 * whose downstream processing succeeded. If processing fails partway, the
 * cursor stays at the older value so the next sweep retries those commits.
 * Without this discipline a transient ICP/LLM failure would silently drop
 * commits that were fetched but never stored.
 */
class GitHubIngestService
{
    private const API_BASE = 'https://api.github.com';

    /**
     * Fetch commits newer than the last-seen SHA for this user+repo.
     *
     * Each item carries the fields the pipeline needs to write a memory:
     *   - source_label  human-readable origin ("github:owner/repo")
     *   - external_id   the commit SHA (becomes the dedup key)
     *   - text          message + author + short stats — what the LLM sees
     *   - metadata      structured fields stored alongside the memory
     *
     * @return array<int, array{source_label: string, external_id: string, text: string, metadata: array}>
     */
    public function fetchCommits(string $userId, string $repoSlug, int $limit = 20): array
    {
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repoSlug)) {
            throw new RuntimeException("Invalid GitHub repo slug: {$repoSlug}");
        }

        $lastSha = $this->lastSeenSha($userId, $repoSlug);

        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get(self::API_BASE . "/repos/{$repoSlug}/commits", [
                'per_page' => max(1, min(100, $limit)),
            ]);

        if ($response->status() === 404) {
            throw new RuntimeException("GitHub repo not found or not accessible: {$repoSlug}");
        }

        if ($response->failed()) {
            Log::warning('GitHubIngestService: fetch failed', [
                'repo'   => $repoSlug,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
            ]);
            throw new RuntimeException("GitHub fetch failed ({$response->status()}): " . $response->body());
        }

        $items = [];

        foreach ($response->json() ?? [] as $commit) {
            $sha = $commit['sha'] ?? null;
            if (! $sha) {
                continue;
            }

            // Stop as soon as we hit the last-processed commit. The API returns
            // commits in reverse-chronological order so everything after this
            // point has already been processed in a prior successful run.
            if ($lastSha !== null && $sha === $lastSha) {
                break;
            }

            $items[] = $this->commitToItem($repoSlug, $commit);
        }

        return $items;
    }

    /**
     * Advance the dedup cursor for this (user, repo) to a commit SHA whose
     * processing has just completed successfully. Subsequent fetchCommits
     * calls will stop at this point.
     *
     * Callers should pass the newest SHA whose downstream pipeline reached a
     * terminal non-error state. If no item from this batch succeeded the
     * cursor must NOT be advanced — leave the previous value so the next
     * sweep retries the work.
     */
    public function markProcessed(string $userId, string $repoSlug, string $sha): void
    {
        // 90 days is well past any realistic ingest cadence — if dedup state
        // expires it just means we re-ingest, which the summariser will catch
        // as duplicates of existing memories anyway.
        Cache::put($this->cacheKey($userId, $repoSlug), $sha, now()->addDays(90));
    }

    /**
     * @param  array<string, mixed>  $commit
     * @return array{source_label: string, external_id: string, text: string, metadata: array}
     */
    private function commitToItem(string $repoSlug, array $commit): array
    {
        $sha       = $commit['sha'] ?? '';
        $shortSha  = substr($sha, 0, 7);
        $message   = $commit['commit']['message'] ?? '';
        $authorRaw = $commit['commit']['author'] ?? [];
        $author    = $authorRaw['name'] ?? ($commit['author']['login'] ?? 'unknown');
        $date      = $authorRaw['date'] ?? null;

        $text = trim("Commit {$shortSha} in {$repoSlug} by {$author}:\n{$message}");

        return [
            'source_label' => "github:{$repoSlug}",
            'external_id'  => $sha,
            'text'         => $text,
            'metadata'     => [
                'source'      => 'github_commit',
                'repo'        => $repoSlug,
                'sha'         => $sha,
                'author'      => $author,
                'committed_at'=> $date,
                'url'         => $commit['html_url'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'OpenMemory-Ingest/1.0',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $token = config('services.github.token');
        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    private function lastSeenSha(string $userId, string $repoSlug): ?string
    {
        return Cache::get($this->cacheKey($userId, $repoSlug));
    }

    private function cacheKey(string $userId, string $repoSlug): string
    {
        return "ingest:github:lastsha:{$userId}:{$repoSlug}";
    }
}
