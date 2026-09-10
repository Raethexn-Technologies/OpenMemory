<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\QueryRelevanceScorer;
use Illuminate\Support\Carbon;

/**
 * Deterministic descriptive statistics over the imported corpus.
 *
 * Everything here is counting. There is no model call, no classifier, and no
 * inference about the person. The reason is not cost: it is that the first
 * useful thing a longitudinal corpus can say about someone is factual, and a
 * factual layer is the only honest foundation for anything interpretive built
 * later. "This subject appears in 17 conversations between March and August, and
 * not since" is checkable. "You value autonomy" is not.
 *
 * The theme timeline answers the temporal questions directly: when a subject
 * first appears, when it was last mentioned, whether it is rising or falling,
 * whether it went dormant and came back, and which providers it appears under.
 * Each bucket carries the conversation IDs behind it, so every number on a chart
 * can be opened and read.
 */
class CorpusOverviewService
{
    public function __construct(
        private readonly QueryRelevanceScorer $scorer,
    ) {}

    /**
     * Corpus-level counts, per-provider breakdown, and monthly volume.
     *
     * @return array<string, mixed>
     */
    public function overview(string $userId): array
    {
        $providers = Conversation::query()
            ->where('user_id', $userId)
            ->groupBy('provider')
            ->select('provider')
            ->selectRaw('COUNT(*) as conversation_count')
            ->selectRaw('SUM(message_count) as message_count')
            ->selectRaw('MIN(first_message_at) as first_at')
            ->selectRaw('MAX(last_message_at) as last_at')
            ->orderBy('provider')
            ->get()
            ->map(static fn ($row) => [
                'provider' => (string) $row->provider,
                'conversation_count' => (int) $row->conversation_count,
                'message_count' => (int) $row->message_count,
                'first_at' => $row->first_at ? Carbon::parse($row->first_at)->toIso8601String() : null,
                'last_at' => $row->last_at ? Carbon::parse($row->last_at)->toIso8601String() : null,
            ])
            ->values()
            ->all();

        $conversationTotal = array_sum(array_column($providers, 'conversation_count'));
        $messageTotal = array_sum(array_column($providers, 'message_count'));

        $firstAt = null;
        $lastAt = null;

        foreach ($providers as $provider) {
            if ($provider['first_at'] !== null && ($firstAt === null || $provider['first_at'] < $firstAt)) {
                $firstAt = $provider['first_at'];
            }

            if ($provider['last_at'] !== null && ($lastAt === null || $provider['last_at'] > $lastAt)) {
                $lastAt = $provider['last_at'];
            }
        }

        return [
            'conversation_count' => $conversationTotal,
            'message_count' => $messageTotal,
            'provider_count' => count($providers),
            'providers' => $providers,
            'first_at' => $firstAt,
            'last_at' => $lastAt,
            'monthly_volume' => $this->monthlyVolume($userId),
            'undated_conversations' => Conversation::query()
                ->where('user_id', $userId)
                ->whereNull('first_message_at')
                ->whereNull('provider_created_at')
                ->count(),
        ];
    }

    /**
     * Conversation counts per calendar month, per provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyVolume(string $userId): array
    {
        $rows = Conversation::query()
            ->where('user_id', $userId)
            ->whereNotNull('first_message_at')
            ->orderBy('first_message_at')
            ->get(['provider', 'first_message_at']);

        $buckets = [];

        foreach ($rows as $row) {
            $month = $row->first_message_at->format('Y-m');
            $buckets[$month] ??= ['month' => $month, 'total' => 0, 'providers' => []];
            $buckets[$month]['total']++;
            $buckets[$month]['providers'][$row->provider] = ($buckets[$month]['providers'][$row->provider] ?? 0) + 1;
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Track one subject through the corpus over time.
     *
     * @param  array{providers?: array<int,string>}  $filters
     * @return array<string, mixed>
     */
    public function themeTimeline(string $userId, string $subject, array $filters = []): array
    {
        $terms = $this->scorer->terms($subject);

        if ($terms === []) {
            return [
                'subject' => $subject,
                'terms' => [],
                'total_messages' => 0,
                'total_conversations' => 0,
                'first_at' => null,
                'last_at' => null,
                'months' => [],
                'providers' => [],
                'dormant_months' => 0,
            ];
        }

        $query = ConversationMessage::query()
            ->where('user_id', $userId)
            ->whereNotNull('provider_created_at')
            ->where('on_active_path', true);

        if (! empty($filters['providers'])) {
            $query->whereIn('provider', $filters['providers']);
        }

        $query->where(function ($outer) use ($terms) {
            foreach ($terms as $term) {
                $outer->orWhere('content_text', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%');
            }
        });

        $rows = $query
            ->orderBy('provider_created_at')
            ->limit(self::TIMELINE_POOL)
            ->get(['id', 'conversation_id', 'provider', 'provider_created_at', 'content_text', 'role']);

        $months = [];
        $providers = [];
        $conversationIds = [];
        $first = null;
        $last = null;
        $total = 0;

        foreach ($rows as $row) {
            // The SQL prefilter is a substring test; the scorer is the authority
            // on whether a term genuinely appears as a token.
            if ($this->scorer->score($terms, (string) $row->content_text) <= 0.0) {
                continue;
            }

            $total++;
            $month = $row->provider_created_at->format('Y-m');
            $months[$month] ??= ['month' => $month, 'messages' => 0, 'conversation_ids' => []];
            $months[$month]['messages']++;

            if (count($months[$month]['conversation_ids']) < self::MAX_EVIDENCE_PER_MONTH) {
                $months[$month]['conversation_ids'][(string) $row->conversation_id] = true;
            }

            $providers[$row->provider] = ($providers[$row->provider] ?? 0) + 1;
            $conversationIds[(string) $row->conversation_id] = true;
            $first ??= $row->provider_created_at;
            $last = $row->provider_created_at;
        }

        ksort($months);

        $months = array_values(array_map(static function (array $bucket) {
            $bucket['conversation_ids'] = array_keys($bucket['conversation_ids']);

            return $bucket;
        }, $months));

        arsort($providers);

        return [
            'subject' => $subject,
            'terms' => $terms,
            'total_messages' => $total,
            'total_conversations' => count($conversationIds),
            'first_at' => $first?->toIso8601String(),
            'last_at' => $last?->toIso8601String(),
            'months' => $months,
            'providers' => $providers,
            'dormant_months' => $this->dormantMonths($months),
            'pool_exhausted' => $rows->count() >= self::TIMELINE_POOL,
        ];
    }

    /**
     * Count calendar months between the first and last mention with no mentions.
     *
     * A subject that appears, disappears for a year, and returns is a different
     * pattern from one mentioned steadily, and the gap count is the cheapest
     * honest way to tell them apart.
     *
     * @param  array<int, array<string, mixed>>  $months
     */
    private function dormantMonths(array $months): int
    {
        if (count($months) < 2) {
            return 0;
        }

        $firstMonth = Carbon::createFromFormat('Y-m-d', $months[0]['month'] . '-01')->startOfMonth();
        $lastMonth = Carbon::createFromFormat('Y-m-d', $months[count($months) - 1]['month'] . '-01')->startOfMonth();
        $span = ($lastMonth->year - $firstMonth->year) * 12 + ($lastMonth->month - $firstMonth->month) + 1;

        return max(0, $span - count($months));
    }

    private const TIMELINE_POOL = 5000;

    private const MAX_EVIDENCE_PER_MONTH = 25;

    /**
     * Distinct providers present in the corpus, for filter controls.
     *
     * @return array<int, string>
     */
    public function providers(string $userId): array
    {
        return Conversation::query()
            ->where('user_id', $userId)
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }

    /**
     * Most frequent conversation title words, used to suggest subjects to explore.
     *
     * Titles are provider-generated summaries of what a conversation was about,
     * which makes them a cheap and reasonably honest source of subject
     * candidates. This is a frequency count over titles, nothing more, and it is
     * presented that way rather than as discovered topics.
     *
     * @return array<int, array{term: string, conversations: int}>
     */
    public function frequentTitleTerms(string $userId, int $limit = 20): array
    {
        $titles = Conversation::query()
            ->where('user_id', $userId)
            ->whereNotNull('title')
            ->orderByDesc('first_message_at')
            ->limit(self::TIMELINE_POOL)
            ->pluck('title');

        $counts = [];

        foreach ($titles as $title) {
            foreach (array_unique($this->scorer->terms((string) $title)) as $term) {
                if (mb_strlen($term) < 4) {
                    continue;
                }

                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }

        arsort($counts);

        $result = [];

        foreach (array_slice($counts, 0, $limit, true) as $term => $count) {
            if ($count < 2) {
                continue;
            }

            $result[] = ['term' => (string) $term, 'conversations' => (int) $count];
        }

        return $result;
    }
}
