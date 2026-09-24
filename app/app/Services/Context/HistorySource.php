<?php

namespace App\Services\Context;

use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Conversations\ConversationEvidenceRetrievalService;

class HistorySource implements ContextSource
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
        $key = $owner->corpusOwnerKey();

        return ConversationMessage::where('user_id', $key)->whereKey($fragment->resourceId)
            ->where('on_active_path', true)->where('content_hash', $fragment->recordVersion)
            ->where('conversation_id', $fragment->provenance['conversation_id'])
            ->where('sequence', $fragment->provenance['sequence'])
            ->where('role', $fragment->provenance['role'])
            ->where('provider', $fragment->provenance['provider'])
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $key))->exists();
    }
}
