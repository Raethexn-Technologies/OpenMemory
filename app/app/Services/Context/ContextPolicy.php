<?php

namespace App\Services\Context;

use App\Models\ContextApplication;
use App\Models\SourceResource;

class ContextPolicy
{
    public const CAPABILITIES = ['context.resolve', 'memory.read', 'memory.disclose', 'history.search', 'history.disclose',
        'github.commits.read', 'github.disclose', 'history.query_disclose.github'];

    public function allows(ContextCaller $caller, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        return $this->permits($caller, [$capability]);
    }

    public function application(ContextCaller $caller): ?ContextApplication
    {
        if ($caller->applicationId === null) {
            return null;
        }
        $application = ContextApplication::where('owner_id', $caller->owner->id)
            ->whereKey($caller->applicationId)->first();

        return $application !== null && $application->revoked_at === null
            && $application->expires_at->isFuture()
            && $application->grant_revision === $caller->grantRevision
            ? $application : null;
    }

    private function permits(ContextCaller $caller, array $capabilities): bool
    {
        return $this->hasCapabilities($caller, $this->application($caller), $capabilities);
    }

    private function hasCapabilities(ContextCaller $caller, ?ContextApplication $application, array $capabilities): bool
    {
        return $caller->applicationId === null ? $caller->owner->exists
            : $application !== null && array_diff($capabilities, $application->capabilities) === [];
    }

    public function retrieve(ContextCaller $caller, string $source): bool
    {
        return $this->sourceEnabled($source) && $this->permits($caller, ['context.resolve', ContextSources::DEFINITIONS[$source]['read']]);
    }

    public function disclose(ContextCaller $caller, string $source): bool
    {
        return $this->sourceEnabled($source) && $this->permits($caller, ['context.resolve', ContextSources::DEFINITIONS[$source]['disclose']]);
    }

    private function sourceEnabled(string $source): bool
    {
        return array_key_exists($source, ContextSources::DEFINITIONS)
            && in_array($source, config('context.enabled_sources', []), true);
    }

    public function resources(ContextCaller $caller, string $source): array
    {
        $query = SourceResource::with('connection')->where('selected', true)
            ->whereHas('connection', fn ($q) => $q->where('owner_id', $caller->owner->id)
                ->where('provider', $source)->whereNull('disconnected_at'));
        if ($caller->applicationId !== null) {
            $app = ContextApplication::where('owner_id', $caller->owner->id)->find($caller->applicationId);
            $query->whereIn('id', $app?->source_resources ?? []);
        }

        return $query->orderBy('id')->limit(ContextSources::DEFINITIONS[$source]['resource_limit'] + 1)->get()->mapWithKeys(fn ($resource) => [
            $resource->id => ['revision' => $resource->revision, 'connection_revision' => $resource->connection->revision],
        ])->all();
    }

    public function resourceCurrent(SourceAccess $access, string $id): bool
    {
        $application = $this->application($access->caller);
        if (! isset($access->resources[$id]) || ! $this->sourceEnabled($access->source)
            || ! $this->hasCapabilities($access->caller, $application, ['context.resolve',
                ContextSources::DEFINITIONS[$access->source]['read'], ContextSources::DEFINITIONS[$access->source]['disclose']])) {
            return false;
        }
        $resource = SourceResource::with('connection')->find($id);
        $connection = $resource?->connection;
        if (! $resource?->selected || ! $connection || $connection->owner_id !== $access->caller->owner->id
            || $connection->provider !== $access->source || $connection->disconnected_at !== null
            || $resource->revision !== $access->resources[$id]['revision']
            || $connection->revision !== $access->resources[$id]['connection_revision']) {
            return false;
        }
        if ($access->caller->applicationId !== null) {
            if (! in_array($id, $application->source_resources ?? [], true)) {
                return false;
            }
        }
        if ($anchor = $access->temporalAnchor) {
            return in_array($anchor->source, $connection->query_disclosures, true)
                && $this->sourceEnabled($anchor->source)
                && $this->hasCapabilities($access->caller, $application, [
                    $anchor->source.'.query_disclose.'.$access->source,
                    ContextSources::DEFINITIONS[$anchor->source]['read'], ContextSources::DEFINITIONS[$anchor->source]['disclose']])
                && app(ContextSources::class)->get($anchor->source)->isCurrent($access->caller->owner, $anchor);
        }

        return true;
    }
}
