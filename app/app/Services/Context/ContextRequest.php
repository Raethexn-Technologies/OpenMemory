<?php

namespace App\Services\Context;

final readonly class ContextRequest
{
    public function __construct(
        public string $query,
        public array $sources,
        public ?string $from,
        public ?string $to,
        public int $limit,
        public int $perSourceLimit,
    ) {}

    public static function fromArray(array $input): self
    {
        ContextInput::validate($input, [
            'version' => 'required|in:context-request-v1',
            'query' => 'required|string|min:1|max:500',
            'sources' => 'required|array|list|min:1|max:2',
            'sources.*' => 'in:native_memory,history|distinct',
            'from' => 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z',
            'limit' => 'sometimes|integer|min:1|max:20',
            'per_source_limit' => 'sometimes|integer|min:1|max:10',
        ]);
        if (isset($input['from'], $input['to']) && $input['from'] > $input['to']) {
            ContextInput::validate(['invalid_range' => true], []);
        }

        return new self(
            $input['query'], array_values(array_intersect(['native_memory', 'history'], $input['sources'])),
            $input['from'] ?? null, $input['to'] ?? null, (int) ($input['limit'] ?? 10),
            (int) ($input['per_source_limit'] ?? 5),
        );
    }
}
