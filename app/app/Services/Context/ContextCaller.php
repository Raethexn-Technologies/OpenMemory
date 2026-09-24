<?php

namespace App\Services\Context;

use App\Models\User;

final readonly class ContextCaller
{
    public function __construct(
        public User $owner,
        public ?string $applicationId = null,
        public ?int $grantRevision = null,
    ) {}
}
