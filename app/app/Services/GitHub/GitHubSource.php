<?php

namespace App\Services\GitHub;

use App\Models\SourceResource;
use App\Models\User;
use App\Services\Context\ContextFragment;
use App\Services\Context\ContextPolicy;
use App\Services\Context\ContextRequest;
use App\Services\Context\ContextSource;
use App\Services\Context\SourceResult;
use Carbon\CarbonImmutable;

class GitHubSource implements \App\Services\Context\BatchContextSource, ContextSource
{
    public function __construct(
        private readonly GitHubClient $client,
        private readonly ContextPolicy $policy,
    ) {}

    public function search(User $owner, ContextRequest $request): SourceResult
    {
        if (! $request->access || $request->access->caller->owner->id !== $owner->id
            || $request->access->source !== 'github' || $request->from === null || $request->to === null) {
            return new SourceResult([], true, [], 'source_not_authorized', false);
        }
        $fragments = [];
        $outcomes = [];
        $limited = count($request->access->resources) > GitHubQuery::RESOURCE_LIMIT;
        $deadline = microtime(true) + 25;
        $searched = false;
        foreach (array_slice(array_keys($request->access->resources), 0, GitHubQuery::RESOURCE_LIMIT) as $resourceId) {
            $repoFragments = [];
            try {
                $resource = $this->resource($request, $resourceId, $deadline);
                $searched = true;
                $beforeRequest = fn () => app(\App\Services\Context\ContextAudit::class)->providerAttempt($request->access);
                $metadata = GitHubClient::repository($this->client->connected($resource->connection, '/repos/'.$resource->reference, [], $beforeRequest)['data']);
                if ($metadata['external_id'] !== $resource->external_id || $metadata['reference'] !== $resource->reference) {
                    throw new GitHubFailure('repository_identity_changed');
                }
                $seen = [];
                $next = false;
                for ($page = 1; $page <= 2; $page++) {
                    $resource = $this->resource($request, $resourceId, $deadline);
                    $response = $this->client->connected($resource->connection, '/repos/'.$resource->reference.'/commits', [
                        'since' => $request->from, 'until' => $request->to, 'per_page' => 10, 'page' => $page,
                    ], $beforeRequest);
                    if (! array_is_list($response['data']) || count($response['data']) > 10) {
                        throw new GitHubFailure('provider_response_invalid');
                    }
                    $retrievedAt = now()->utc()->toIso8601String();
                    foreach ($response['data'] as $commit) {
                        $fragment = $this->fragment($resource, $commit, $retrievedAt, $request);
                        if ($fragment && ! isset($seen[$fragment->resourceId])) {
                            $seen[$fragment->resourceId] = true;
                            $repoFragments[] = $fragment;
                        }
                    }
                    $next = $response['next'];
                    if (! $next) {
                        break;
                    }
                }
                $limited = $limited || $next;
                $outcomes[$resourceId] = ['status' => $next ? 'search_incomplete' : ($repoFragments === [] ? 'no_matches' : 'complete'),
                    'pagination_truncated' => $next];
            } catch (GitHubFailure $failure) {
                // Failed rechecks discard evidence already read from this repository.
                $repoFragments = [];
                $outcomes[$resourceId] = ['status' => $failure->outcome, 'retry_at' => $failure->retryAt];
            }
            $fragments = array_merge($fragments, $repoFragments);
        }
        usort($fragments, fn ($a, $b) => strcmp($b->provenance['commit_time'], $a->provenance['commit_time'])
            ?: strcmp($a->resourceId, $b->resourceId));
        $fragments = array_map(fn ($fragment, $index) => new ContextFragment(
            $fragment->source, $fragment->resourceId, $fragment->content, $fragment->provenance,
            $index + 1, $fragment->truncated, $fragment->recordVersion, $fragment->method,
        ), $fragments, array_keys($fragments));
        $limited = $limited || count($fragments) > $request->perSourceLimit;
        $statuses = array_column($outcomes, 'status');
        $failed = array_filter($statuses, fn ($status) => ! in_array($status, ['complete', 'no_matches'], true));
        $status = $failed !== []
            ? (count(array_unique($statuses)) === 1 ? reset($statuses) : 'partial_failure')
            : ($limited ? 'search_incomplete' : null);

        return new SourceResult(array_slice($fragments, 0, $request->perSourceLimit), $limited || $failed !== [], [
            'scope' => 'selected_repository_default_branches', 'timestamp_basis' => 'committer_date',
            'retrieval' => 'live', 'capability' => 'repository.commits.list',
            'repository_limit' => 3, 'commits_per_repository_limit' => 20, 'pages_per_repository_limit' => 2,
            'result_limit_reached' => $limited, 'resources' => $outcomes,
            'temporal_basis' => $request->access->temporalAnchor ? 'first_ranked_dated_history_match' : 'explicit_window',
            'lifetime_coverage' => 'not_claimed',
        ], $status, $searched);
    }

    private function resource(ContextRequest $request, string $id, float $deadline): SourceResource
    {
        if (microtime(true) > $deadline) {
            throw new GitHubFailure('provider_timeout');
        }
        if (! $this->policy->resourceCurrent($request->access, $id)) {
            throw new GitHubFailure('authorization_changed');
        }

        return SourceResource::with('connection')->findOrFail($id);
    }

    private function fragment(SourceResource $resource, mixed $commit, string $retrievedAt, ContextRequest $request): ?ContextFragment
    {
        if (! is_array($commit) || ! is_string($commit['sha'] ?? null)
            || ! preg_match('/^[a-f0-9]{40,64}$/D', $commit['sha'])
            || ! is_string($commit['commit']['message'] ?? null)
            || ! is_string($commit['commit']['committer']['date'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $commit['commit']['committer']['date'])) {
            throw new GitHubFailure('provider_response_invalid');
        }
        try {
            $time = CarbonImmutable::parse($commit['commit']['committer']['date'])->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new GitHubFailure('provider_response_invalid');
        }
        if ($time < $request->from || $time > $request->to) {
            return null;
        }
        $message = $commit['commit']['message'];
        // Author email and arbitrary provider URLs are intentionally omitted.
        $author = $commit['author']['login'] ?? null;
        if (! is_string($author) || ! preg_match('/^[A-Za-z0-9_\[\]-]{1,100}$/D', $author)) {
            $author = null;
        }
        $authorId = $commit['author']['id'] ?? null;
        $authorId = is_int($authorId) && $authorId > 0 ? (string) $authorId : null;
        $provenance = [
            'provider' => 'github', 'connection_id' => $resource->connection_id,
            'external_account_id' => $resource->connection->external_account_id,
            'external_account_login' => $resource->connection->external_account_login,
            'source_resource_id' => $resource->id, 'repository_id' => $resource->external_id,
            'repository' => $resource->reference, 'commit_sha' => $commit['sha'],
            'author_login' => $author, 'author_id' => $authorId,
            'commit_time' => $time, 'retrieved_at' => $retrievedAt,
            'url' => 'https://github.com/'.$resource->reference.'/commit/'.$commit['sha'],
            'capability' => 'repository.commits.list', 'projection' => 'commit_message_excerpt',
        ];

        // The resolver redacts before clipping, including secrets crossing the excerpt boundary.
        return new ContextFragment('github', $resource->external_id.':'.$commit['sha'], $message,
            $provenance, 1, mb_strlen($message) > 600, $resource->revision.':'.$resource->connection->revision,
            'repository.commits.list');
    }

    public function isCurrent(User $owner, ContextFragment $fragment): bool
    {
        return isset($this->current($owner, [$fragment])[$fragment->resourceId]);
    }

    public function current(User $owner, array $fragments): array
    {
        $rows = SourceResource::with('connection')->whereIn('id', array_map(fn ($fragment) => $fragment->provenance['source_resource_id'] ?? '', $fragments))->get()->keyBy('id');
        $current = [];
        foreach ($fragments as $fragment) {
            $resource = $rows->get($fragment->provenance['source_resource_id'] ?? '');
            if ($resource !== null && $resource->selected && $resource->connection->owner_id === $owner->id
            && $resource->connection->provider === 'github' && $resource->connection->disconnected_at === null
            && $resource->connection->getRawOriginal('credential') !== null
            && $resource->connection->credential_expires_at->isFuture()
            && $resource->revision.':'.$resource->connection->revision === $fragment->recordVersion) {
                $current[$fragment->resourceId] = true;
            }
        }

        return $current;
    }
}
