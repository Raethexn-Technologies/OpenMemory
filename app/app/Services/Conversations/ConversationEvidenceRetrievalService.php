<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\QueryRelevanceScorer;
use Illuminate\Support\Carbon;

/**
 * Selects message-level evidence from the imported corpus.
 *
 * Scoring is deterministic and lexical, reusing QueryRelevanceScorer so that
 * conversation retrieval, graph retrieval, and document evidence retrieval agree
 * on what counts as a query term. No embeddings, no index, no model call. That
 * is a deliberate starting point rather than a claim of sufficiency: it makes
 * every answer reproducible and auditable before any learned component is
 * introduced, and it means retrieval works on a machine with no API key.
 *
 * Two properties matter more than the ranking function.
 *
 * First, every returned item is addressable. A citation names a message ID that
 * resolves to a stored row, which resolves to a conversation, a provider, and a
 * timestamp. A citation that cannot be opened is worse than no citation, because
 * it looks like evidence.
 *
 * Second, the excerpt is a window around the match rather than the whole
 * message. What reaches a model is a few hundred characters that the query
 * actually selected, not a person's transcript.
 */
class ConversationEvidenceRetrievalService
{
    public function __construct(
        private readonly QueryRelevanceScorer $scorer,
    ) {}

    /**
     * Retrieve ranked message evidence for a question.
     *
     * @param  array{providers?: array<int,string>, from?: string|null, to?: string|null, roles?: array<int,string>, active_path_only?: bool}  $filters
     * @return array{
     *   terms: array<int, string>,
     *   evidence: array<int, array<string, mixed>>,
     *   conversations: array<int, array<string, mixed>>,
     *   candidate_count: int,
     *   matched_count: int
     * }
     */
    public function retrieve(string $userId, string $query, int $limit = 12, array $filters = []): array
    {
        $terms = $this->scorer->terms($query);

        if ($terms === []) {
            return [
                'terms' => [],
                'evidence' => [],
                'conversations' => [],
                'candidate_count' => 0,
                'candidate_limit_reached' => false,
                'matched_count' => 0,
            ];
        }

        $candidates = $this->candidates($userId, $terms, $filters);
        $cap = max(1, min(self::CANDIDATE_POOL, (int) ($filters['candidate_limit'] ?? self::CANDIDATE_POOL)));
        $overflow = $candidates->count() > $cap;
        $candidates = $candidates->take($cap);
        $excerptChars = isset($filters['excerpt_chars'])
            ? max(1, min(600, (int) $filters['excerpt_chars']))
            : (int) config('conversations.ask.excerpt_chars', 600);

        $scored = [];

        foreach ($candidates as $message) {
            $conversation = $message->conversation;
            $score = $this->scorer->score(
                $terms,
                (string) $message->content_text,
                (string) ($conversation?->title ?? ''),
            );

            if ($score <= 0.0) {
                continue;
            }

            $scored[] = [
                'message' => $message,
                'conversation' => $conversation,
                'score' => $score,
            ];
        }

        // Deterministic ordering: score first, then newest, then ID. Without the
        // tie-breakers, two runs over the same corpus could cite different rows.
        usort($scored, function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];

            if ($byScore !== 0) {
                return $byScore;
            }

            $leftTime = $left['message']->provider_created_at?->getTimestamp() ?? 0;
            $rightTime = $right['message']->provider_created_at?->getTimestamp() ?? 0;

            return $rightTime <=> $leftTime ?: strcmp((string) $left['message']->id, (string) $right['message']->id);
        });

        $matched = count($scored);
        $selected = array_slice($scored, 0, max(1, $limit));

        $evidence = [];
        $conversations = [];

        foreach ($selected as $item) {
            /** @var ConversationMessage $message */
            $message = $item['message'];
            /** @var Conversation|null $conversation */
            $conversation = $item['conversation'];

            $evidence[] = [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'provider' => $message->provider,
                'role' => $message->role,
                'title' => $conversation?->title,
                'occurred_at' => $message->provider_created_at?->toIso8601String(),
                'model_slug' => $message->model_slug,
                'on_active_path' => (bool) $message->on_active_path,
                'sequence' => $message->sequence,
                'stored_at' => $message->created_at?->toIso8601String(),
                'record_version' => (string) $message->content_hash,
                'excerpt' => $this->excerpt((string) $message->content_text, $terms, $excerptChars),
                'excerpt_truncated' => mb_strlen((string) $message->content_text) > $excerptChars,
                'score' => round((float) $item['score'], 4),
            ];

            $conversationId = (string) $message->conversation_id;

            if (! isset($conversations[$conversationId])) {
                $conversations[$conversationId] = [
                    'conversation_id' => $conversationId,
                    'provider' => $message->provider,
                    'title' => $conversation?->title,
                    'occurred_at' => $conversation?->occurredAt()?->toIso8601String(),
                    'message_hits' => 0,
                ];
            }

            $conversations[$conversationId]['message_hits']++;
        }

        return [
            'terms' => $terms,
            'evidence' => $evidence,
            'conversations' => array_values($conversations),
            'candidate_count' => $candidates->count(),
            'candidate_limit_reached' => $overflow,
            'matched_count' => $matched,
        ];
    }

    /**
     * Narrow the corpus with SQL before scoring it in PHP.
     *
     * A full-corpus scan would be correct but would scale with the size of a
     * person's whole history on every question. The LIKE prefilter is a
     * recall-preserving narrowing step for the exact-token scorer that follows:
     * a message the scorer could score above zero must contain at least one
     * query term as a substring.
     *
     * @param  array<int, string>  $terms
     * @param  array{providers?: array<int,string>, from?: string|null, to?: string|null, roles?: array<int,string>, active_path_only?: bool}  $filters
     * @return \Illuminate\Support\Collection<int, ConversationMessage>
     */
    private function candidates(string $userId, array $terms, array $filters)
    {
        $query = ConversationMessage::query()
            ->select(['id', 'conversation_id', 'user_id', 'provider', 'role', 'content_text',
                'provider_created_at', 'sequence', 'on_active_path', 'created_at', 'content_hash', 'model_slug'])
            ->with('conversation')
            ->where('user_id', $userId)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $userId));

        if (! empty($filters['providers'])) {
            $query->whereIn('provider', $filters['providers']);
        }

        if (! empty($filters['roles'])) {
            $query->whereIn('role', $filters['roles']);
        }

        if (($filters['active_path_only'] ?? true) === true) {
            $query->where('on_active_path', true);
        }

        if (! empty($filters['from'])) {
            $query->where('provider_created_at', '>=', Carbon::parse($filters['from'])->utc());
        }

        if (! empty($filters['to'])) {
            $query->where('provider_created_at', '<=', Carbon::parse($filters['to'])->utc());
        }

        $query->where(function ($outer) use ($terms) {
            foreach ($terms as $term) {
                $outer->orWhereRaw("content_text LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($term).'%']);
            }
        });

        return $query
            ->orderByDesc('provider_created_at')
            ->orderBy('id')
            ->limit(max(1, min(self::CANDIDATE_POOL, (int) ($filters['candidate_limit'] ?? self::CANDIDATE_POOL))) + 1)
            ->get();
    }

    /**
     * Upper bound on rows scored for one question.
     *
     * Bounded work per query keeps latency stable as a corpus grows from a
     * hundred conversations to tens of thousands. The pool is ordered newest
     * first, so the bound biases toward recent history when a very common term
     * matches more rows than the pool can hold. That bias is a known limitation
     * of the lexical baseline and is recorded in the retrieval trace.
     */
    private const CANDIDATE_POOL = 2000;

    /**
     * Build a window of text centred on the first matching term.
     *
     * @param  array<int, string>  $terms
     */
    private function excerpt(string $text, array $terms, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $position = 0;

        foreach ($terms as $term) {
            $found = mb_stripos($text, $term);

            if ($found !== false) {
                $position = $found;
                break;
            }
        }

        $start = max(0, $position - (int) ($maxChars / 3));
        $excerpt = mb_substr($text, $start, $maxChars);

        return ($start > 0 ? '... ' : '') . trim($excerpt) . ' ...';
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
