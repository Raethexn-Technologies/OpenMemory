<?php

namespace App\Services\GitHub;

use App\Services\Context\ContextRequest;
use App\Services\Context\SourceResult;
use Carbon\CarbonImmutable;

final class GitHubQuery
{
    public const RESOURCE_LIMIT = 3;

    public const TEMPORAL_DAYS = 7;

    public function prepare(ContextRequest $request): ContextRequest|SourceResult
    {
        if ($request->from === null || $request->to === null || $request->from < '1970-01-01T00:00:00Z'
            || $request->to > '2099-12-31T23:59:59Z' || $request->from > $request->to
            || CarbonImmutable::parse($request->from)->diffInSeconds(CarbonImmutable::parse($request->to)) > 31 * 86400) {
            return new SourceResult([], true, [], 'bounded_window_required', false);
        }

        // Commit listing needs only a selected resource and a bounded window.
        return new ContextRequest('', $request->sources, $request->from, $request->to,
            $request->limit, $request->perSourceLimit, access: $request->access);
    }
}
