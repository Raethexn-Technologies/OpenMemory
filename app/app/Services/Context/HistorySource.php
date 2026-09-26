<?php

namespace App\Services\Context;

use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Conversations\ConversationEvidenceRetrievalService;

class HistorySource implements BatchContextSource, ContextSource
{
    public function __construct(private readonly ConversationEvidenceRetrievalService $retrieval) {}

    public function search(User $owner, ContextRequest $request): SourceResult
    {
        $cap = max(1, min(100, (int) config('context.candidate_limit', 100)));
        $result = $this->retrieval->retrieve($owner->corpusOwnerKey(), $request->query, $request->perSourceLimit, [
            'from' => $request->from, 'to' => $request->to,
            'candidate_limit' => $cap, 'excerpt_chars' => 600,
        ]);
        $fragments = [];
        foreach ($result['evidence'] as $i => $evidence) {
            $fragments[] = new ContextFragment('history', $evidence['message_id'], $evidence['excerpt'], [
                'conversation_id' => $evidence['conversation_id'],
                'message_id' => $evidence['message_id'],
                'provider' => $evidence['provider'],
                'role' => $evidence['role'],
                'sequence' => $evidence['sequence'],
                'message_at' => $evidence['occurred_at'],
                'stored_at' => $evidence['stored_at'],
                'projection' => 'redacted_message_excerpt',
                'location_basis' => 'message_id_and_sequence',
            ], $i + 1, $evidence['excerpt_truncated'], $evidence['record_version']);
        }
        $incomplete = $result['candidate_limit_reached']
            || $result['matched_count'] > $request->perSourceLimit || $result['terms'] === [];

        return new SourceResult($fragments, $incomplete, [
            'scope' => 'imported_active_path_messages', 'timestamp_basis' => 'provider_created_at',
            'undated' => $request->from !== null || $request->to !== null ? 'excluded' : 'eligible',
            'candidate_limit' => $cap, 'candidate_limit_reached' => $result['candidate_limit_reached'],
            'lifetime_coverage' => 'unknown',
            'result_limit_reached' => $result['matched_count'] > $request->perSourceLimit,
            'reason' => $result['terms'] === [] ? 'no_search_terms' : null,
        ]);
    }

    public function isCurrent(User $owner, ContextFragment $fragment): bool
    {
        return isset($this->current($owner, [$fragment])[$fragment->resourceId]);
    }

    public function current(User $owner, array $fragments): array
    {
        $key = $owner->corpusOwnerKey();
        $rows = ConversationMessage::where('user_id', $key)->where('on_active_path', true)
            ->whereIn('id', array_map(fn ($fragment) => $fragment->resourceId, $fragments))
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $key))
            ->get(['id', 'content_hash', 'conversation_id', 'sequence', 'role', 'provider', 'provider_created_at'])->keyBy('id');
        $current = [];
        foreach ($fragments as $fragment) {
            $row = $rows->get($fragment->resourceId);
            $at = isset($fragment->provenance['message_at']) ? \Carbon\Carbon::parse($fragment->provenance['message_at'])->utc()->timestamp : null;
            if ($row && $row->content_hash === $fragment->recordVersion
                && $row->conversation_id === ($fragment->provenance['conversation_id'] ?? null)
                && $row->sequence === ($fragment->provenance['sequence'] ?? null)
                && $row->role === ($fragment->provenance['role'] ?? null)
                && $row->provider === ($fragment->provenance['provider'] ?? null)
                && $row->provider_created_at?->timestamp === $at) {
                $current[$fragment->resourceId] = true;
            }
        }

        return $current;
    }
}
