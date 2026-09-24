<?php

namespace App\Services\Context;

final readonly class ContextBundle
{
    public function __construct(public array $payload) {}

    public function toArray(): array
    {
        return $this->payload;
    }
}
