<?php

namespace App\Services\Context;

use App\Models\User;

interface ContextSource
{
    public function search(User $owner, ContextRequest $request): SourceResult;

    public function isCurrent(User $owner, ContextFragment $fragment): bool;
}
