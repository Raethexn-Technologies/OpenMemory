<?php

namespace App\Services\Context;

class ContextSources
{
    public const DEFINITIONS = [
        'native_memory' => ['adapter' => NativeMemorySource::class, 'read' => 'memory.read', 'disclose' => 'memory.disclose'],
        'history' => ['adapter' => HistorySource::class, 'read' => 'history.search', 'disclose' => 'history.disclose', 'event_time' => 'message_at'],
        'github' => ['adapter' => \App\Services\GitHub\GitHubSource::class, 'read' => 'github.commits.read',
            'disclose' => 'github.disclose', 'federated' => true,
            'query' => \App\Services\GitHub\GitHubQuery::class,
            'resource_limit' => \App\Services\GitHub\GitHubQuery::RESOURCE_LIMIT,
            'temporal_origins' => ['history'], 'temporal_days' => \App\Services\GitHub\GitHubQuery::TEMPORAL_DAYS,
            'capabilities' => ['repository.list', 'repository.commits.list']],
    ];

    public function get(string $source): ContextSource
    {
        return app(self::DEFINITIONS[$source]['adapter']);
    }

    public function current(string $source, \App\Models\User $owner, array $fragments): array
    {
        $fragments = array_values(array_filter($fragments, fn ($fragment) => $fragment instanceof ContextFragment && $fragment->source === $source));
        if ($fragments === []) {
            return [];
        }
        $adapter = $this->get($source);
        if ($adapter instanceof BatchContextSource) {
            return $adapter->current($owner, $fragments);
        }
        $current = [];
        foreach ($fragments as $fragment) {
            if ($adapter->isCurrent($owner, $fragment)) {
                $current[$fragment->resourceId] = true;
            }
        }

        return $current;
    }
}
