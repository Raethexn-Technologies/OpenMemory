<?php

namespace App\Services\Context;

use App\Models\SourceConnection;
use Carbon\CarbonImmutable;

class ContextPlan
{
    public function __construct(private readonly ContextPolicy $policy, private readonly ContextSources $sources) {}

    public function forSource(ContextCaller $caller, ContextRequest $request, string $source, array $results): ContextRequest|SourceResult
    {
        if (! (ContextSources::DEFINITIONS[$source]['federated'] ?? false)) {
            return $request;
        }
        $connection = SourceConnection::where('owner_id', $caller->owner->id)->where('provider', $source)->first();
        if (! $connection || $connection->disconnected_at !== null) {
            return $this->failure('source_disconnected');
        }
        $resources = $this->policy->resources($caller, $source);
        if ($resources === []) {
            return $this->failure('resource_not_authorized');
        }
        $from = $request->from;
        $to = $request->to;
        $anchor = null;
        if (($request->temporal['to_source'] ?? null) === $source) {
            $origin = $request->temporal['from_source'];
            if (! in_array($origin, $connection->query_disclosures, true)
                || ! $this->policy->allows($caller, $origin.'.query_disclose.'.$source)
                || ! $this->policy->retrieve($caller, $origin) || ! $this->policy->disclose($caller, $origin)) {
                return $this->failure('query_disclosure_denied');
            }
            $field = ContextSources::DEFINITIONS[$origin]['event_time'];
            foreach ($results[$origin]->fragments ?? [] as $fragment) {
                if (($fragment->provenance[$field] ?? null) !== null
                    && $this->sources->get($origin)->isCurrent($caller->owner, $fragment)) {
                    $anchor = $fragment;
                    break;
                }
            }
            if ($anchor === null) {
                return $this->failure('temporal_anchor_unavailable');
            }
            $date = CarbonImmutable::parse($anchor->provenance[$field])->utc();
            $from = $date->subDays($request->temporal['days'])->format('Y-m-d\TH:i:s\Z');
            $to = $date->addDays($request->temporal['days'])->format('Y-m-d\TH:i:s\Z');
        }

        return app(ContextSources::DEFINITIONS[$source]['query'])->prepare(new ContextRequest(
            $request->query, [$source], $from, $to, $request->limit, $request->perSourceLimit,
            access: new SourceAccess($caller, $source, $resources, $anchor),
        ));
    }

    private function failure(string $status): SourceResult
    {
        return new SourceResult([], true, [], $status, false);
    }
}
