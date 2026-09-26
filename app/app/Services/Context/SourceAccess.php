<?php

namespace App\Services\Context;

/** Server-created authority; never populated from request JSON. */
final readonly class SourceAccess
{
    public function __construct(
        public ContextCaller $caller,
        public string $source,
        public array $resources,
        public ?ContextFragment $temporalAnchor = null,
    ) {}
}
