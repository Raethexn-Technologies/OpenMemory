<?php

namespace App\Services\Context;

use App\Models\ContextApplication;

class ContextPolicy
{
    public const CAPABILITIES = ['context.resolve', 'memory.read', 'memory.disclose', 'history.search', 'history.disclose'];

    private const SOURCE_GRANTS = [
        'native_memory' => ['memory.read', 'memory.disclose'],
        'history' => ['history.search', 'history.disclose'],
    ];

    public function allows(ContextCaller $caller, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }
        if ($caller->applicationId === null) {
            return $caller->owner->exists;
        }
        $application = ContextApplication::where('owner_id', $caller->owner->id)
            ->whereKey($caller->applicationId)->first();

        return $application !== null && $application->revoked_at === null
            && $application->expires_at->isFuture()
            && $application->grant_revision === $caller->grantRevision
            && in_array($capability, $application->capabilities, true);
    }

    public function retrieve(ContextCaller $caller, string $source): bool
    {
        return $this->sourceEnabled($source) && $this->allows($caller, 'context.resolve')
            && $this->allows($caller, self::SOURCE_GRANTS[$source][0]);
    }

    public function disclose(ContextCaller $caller, string $source): bool
    {
        return $this->sourceEnabled($source) && $this->allows($caller, 'context.resolve')
            && $this->allows($caller, self::SOURCE_GRANTS[$source][1]);
    }

    private function sourceEnabled(string $source): bool
    {
        return array_key_exists($source, self::SOURCE_GRANTS)
            && in_array($source, config('context.enabled_sources', []), true);
    }
}
