<?php

namespace App\Services\GitHub;

final class GitHubFailure extends \RuntimeException
{
    public function __construct(public readonly string $outcome, public readonly ?int $retryAt = null)
    {
        parent::__construct('GitHub request could not complete.');
    }
}
