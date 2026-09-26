<?php

namespace App\Services\Context;

/** Request-local counters contain no SQL text, bindings, URLs, or payloads. */
final class ContextMetrics
{
    public bool $active = false;

    public ?string $requestId = null;

    public int $queries = 0;

    public int $providerRequests = 0;

    public float $elapsedMs = 0;

    public array $queriedSources = [];

    private int $started = 0;

    public function begin(string $id): void
    {
        $this->active = true;
        $this->requestId = $id;
        $this->queries = $this->providerRequests = 0;
        $this->queriedSources = [];
        $this->started = hrtime(true);
    }

    public function finish(): void
    {
        $this->elapsedMs = round((hrtime(true) - $this->started) / 1000000, 2);
        $this->active = false;
    }

    public function query(?\Illuminate\Database\Events\QueryExecuted $event = null): void
    {
        if ($this->active) {
            $this->queries++;
            if ($event && preg_match('/\bfrom ["`]?([a-z_]+)/i', $event->sql, $match)) {
                $source = ['native_memories' => 'native_memory', 'conversation_messages' => 'history'][$match[1]] ?? null;
                if ($source) {
                    $this->queriedSources[$source] = true;
                }
            }
        }
    }

    public function providerRequest(string $source): void
    {
        if ($this->active) {
            $this->providerRequests++;
            $this->queriedSources[$source] = true;
        }
    }

    public function headers(): array
    {
        return ['X-Context-Sql-Queries' => (string) $this->queries,
            'X-Context-Provider-Requests' => (string) $this->providerRequests,
            'X-Context-Sources-Queried' => implode(',', array_keys($this->queriedSources)),
            'X-Context-Resolver-Ms' => (string) $this->elapsedMs];
    }
}
