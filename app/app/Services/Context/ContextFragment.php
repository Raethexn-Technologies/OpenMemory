<?php

namespace App\Services\Context;

final readonly class ContextFragment
{
    public function __construct(
        public string $source,
        public string $resourceId,
        public string $content,
        public array $provenance,
        public int $rank,
        public bool $truncated,
        public string $recordVersion,
        public string $method = 'lexical',
    ) {}

    public function payload(string $content, bool $redacted): array
    {
        return [
            'source' => $this->source, 'resource_id' => $this->resourceId,
            'content' => $content, 'provenance' => $this->provenance,
            'retrieval' => ['method' => $this->method, 'source_rank' => $this->rank],
            'classification' => 'private', 'trust' => 'untrusted_data',
            'excerpt_truncated' => $this->truncated || mb_strlen($content) > 600,
            'redacted' => $redacted,
        ];
    }
}
