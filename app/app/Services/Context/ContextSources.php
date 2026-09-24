<?php

namespace App\Services\Context;

class ContextSources
{
    public function get(string $source): ContextSource
    {
        return match ($source) {
            'native_memory' => app(NativeMemorySource::class),
            'history' => app(HistorySource::class),
            default => throw new \InvalidArgumentException('Unsupported context source.'),
        };
    }
}
