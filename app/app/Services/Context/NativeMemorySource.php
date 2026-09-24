<?php

namespace App\Services\Context;

use App\Models\NativeMemory;
use App\Models\User;
use App\Services\QueryRelevanceScorer;
use Illuminate\Support\Carbon;

class NativeMemorySource implements ContextSource
{
    public function __construct(private readonly QueryRelevanceScorer $scorer) {}

    public function search(User $owner, ContextRequest $request): SourceResult
    {
        $terms = $this->scorer->terms($request->query);
        $cap = max(1, min(100, (int) config('context.candidate_limit', 100)));
        $coverage = [
            'scope' => 'active_native_memory', 'timestamp_basis' => 'created_at',
            'candidate_limit' => $cap, 'candidate_limit_reached' => false,
            'lifetime_coverage' => 'not_claimed',
        ];
        if ($terms === []) {
            return new SourceResult([], true, $coverage + ['reason' => 'no_search_terms']);
        }
        $query = NativeMemory::where('owner_id', $owner->id)->where('state', 'active');
        if ($request->from !== null) {
            $query->where('created_at', '>=', Carbon::parse($request->from)->utc());
        }
        if ($request->to !== null) {
            $query->where('created_at', '<=', Carbon::parse($request->to)->utc());
        }
        $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
                $q->orWhereRaw("content LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
            }
        });
        $rows = $query->orderByDesc('created_at')->orderBy('memory_id')->limit($cap + 1)->get();
        $coverage['candidate_limit_reached'] = $rows->count() > $cap;
        $ranked = [];
        foreach ($rows->take($cap) as $row) {
            $score = $this->scorer->score($terms, $row->content);
            if ($score > 0) {
                $ranked[] = ['row' => $row, 'score' => $score];
            }
        }
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']
            ?: $b['row']->created_at->getTimestamp() <=> $a['row']->created_at->getTimestamp()
            ?: strcmp($a['row']->memory_id, $b['row']->memory_id));
        $coverage['result_limit_reached'] = count($ranked) > $request->perSourceLimit;
        $fragments = [];
        foreach (array_slice($ranked, 0, $request->perSourceLimit) as $i => $item) {
            $row = $item['row'];
            $fragments[] = new ContextFragment('native_memory', $row->memory_id,
                ContextExcerpt::select($row->content, $terms), [
                    'memory_id' => $row->memory_id, 'attribution' => $row->attribution,
                    'created_at' => $row->created_at->toIso8601String(),
                    'updated_at' => $row->updated_at->toIso8601String(),
                    'revision' => $row->revision,
                ], $i + 1, mb_strlen($row->content) > 600, (string) $row->revision);
        }

        return new SourceResult($fragments,
            $coverage['candidate_limit_reached'] || count($ranked) > $request->perSourceLimit, $coverage);
    }

    public function isCurrent(User $owner, ContextFragment $fragment): bool
    {
        return NativeMemory::where('owner_id', $owner->id)->where('memory_id', $fragment->resourceId)
            ->where('state', 'active')->where('revision', $fragment->recordVersion)->exists();
    }
}
