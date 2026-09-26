<?php

namespace App\Services\Context;

use App\Models\User;

/** Optional batching within one freshness checkpoint, never across checkpoints. */
interface BatchContextSource
{
    /** @return array<string, true> Current fragment resource identifiers. */
    public function current(User $owner, array $fragments): array;
}
