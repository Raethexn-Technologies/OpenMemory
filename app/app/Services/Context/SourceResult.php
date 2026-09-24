<?php

namespace App\Services\Context;

final readonly class SourceResult
{
    /** @param list<ContextFragment> $fragments */
    public function __construct(
        public array $fragments,
        public bool $incomplete,
        public array $coverage,
    ) {}
}
