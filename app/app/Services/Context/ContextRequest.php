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
        public ?array $temporal = null,
        public ?SourceAccess $access = null,
    ) {}

    public static function fromArray(array $input): self
    {
        ContextInput::validate($input, [
            'version' => 'required|in:context-request-v1',
            'query' => 'required|string|min:1|max:500',
            'sources' => 'required|array|list|min:1|max:3',
            'sources.*' => 'in:'.implode(',', array_keys(ContextSources::DEFINITIONS)).'|distinct',
            'from' => 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z',
            'to' => 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z',
            'limit' => 'sometimes|integer|min:1|max:20',
            'per_source_limit' => 'sometimes|integer|min:1|max:10',
            'temporal' => 'sometimes|array:from_source,to_source,days|required_array_keys:from_source,to_source,days',
            'temporal.from_source' => 'required_with:temporal|in:'.implode(',', array_keys(ContextSources::DEFINITIONS)),
            'temporal.to_source' => 'required_with:temporal|in:'.implode(',', array_keys(ContextSources::DEFINITIONS)),
            'temporal.days' => 'required_with:temporal|integer|min:1',
        ]);
        if (isset($input['from'], $input['to']) && $input['from'] > $input['to']) {
            ContextInput::validate(['invalid_range' => true], []);
        }

        if (isset($input['temporal']) && (isset($input['from']) || isset($input['to'])
            || ! in_array($input['temporal']['from_source'], ContextSources::DEFINITIONS[$input['temporal']['to_source']]['temporal_origins'] ?? [], true)
            || $input['temporal']['days'] > (ContextSources::DEFINITIONS[$input['temporal']['to_source']]['temporal_days'] ?? 0)
            || ! in_array($input['temporal']['from_source'], $input['sources'], true)
            || ! in_array($input['temporal']['to_source'], $input['sources'], true))) {
            ContextInput::validate(['invalid_temporal_request' => true], []);
        }

        return new self(
            $input['query'], array_values(array_intersect(array_keys(ContextSources::DEFINITIONS), $input['sources'])),
            $input['from'] ?? null, $input['to'] ?? null, (int) ($input['limit'] ?? 10),
            (int) ($input['per_source_limit'] ?? 5),
            $input['temporal'] ?? null,
        );
    }
}
