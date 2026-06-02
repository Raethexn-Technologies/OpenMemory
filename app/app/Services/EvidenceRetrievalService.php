<?php

namespace App\Services;

use App\Models\EvidenceFact;

/**
 * Selects source-backed evidence facts from graph-retrieved document nodes.
 *
 * This is the query-aware bridge between graph retrieval and grounded answer
 * generation. It deliberately uses deterministic lexical scoring for V1. That
 * makes the behavior cheap, explainable, and easy to test before adding an
 * embedding index or normalized fact triples.
 */
class EvidenceRetrievalService
{
    /**
     * @param  string[]  $sourceNodeIds
     * @return array<int, array{
     *   fact_id: string,
     *   fact_text: string,
     *   source_node_id: string,
     *   source_document_id: string|null,
     *   source_label: string|null,
     *   span_start: int|null,
     *   span_end: int|null,
     *   confidence: float,
     *   score: float,
     *   metadata: array<string, mixed>
     * }>
     */
    public function retrieve(string $userId, string $query, array $sourceNodeIds, int $limit = 8): array
    {
        if ($limit < 1 || empty($sourceNodeIds)) {
            return [];
        }

        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $sourceNodeIds = array_values(array_unique(array_filter($sourceNodeIds)));

        $facts = EvidenceFact::with(['sourceNode', 'sourceDocument'])
            ->where('user_id', $userId)
            ->whereIn('source_node_id', $sourceNodeIds)
            ->whereHas('sourceNode', function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->where('source', 'document')
                    ->where('sensitivity', 'public')
                    ->whereNull('consolidated_at');
            })
            ->get();

        return $facts
            ->map(function (EvidenceFact $fact) use ($terms) {
                $score = $this->score($fact->fact_text, $terms, $fact->confidence);

                return [
                    'fact_id' => $fact->id,
                    'fact_text' => $fact->fact_text,
                    'source_node_id' => $fact->source_node_id,
                    'source_document_id' => $fact->source_document_id,
                    'source_label' => $fact->sourceDocument?->label ?? $fact->sourceNode?->label,
                    'span_start' => $fact->span_start,
                    'span_end' => $fact->span_end,
                    'confidence' => $fact->confidence,
                    'score' => $score,
                    'metadata' => $fact->metadata ?? [],
                ];
            })
            ->filter(fn (array $fact) => $fact['score'] > 0)
            ->sort(function (array $left, array $right) {
                $scoreComparison = $right['score'] <=> $left['score'];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $confidenceComparison = $right['confidence'] <=> $left['confidence'];
                if ($confidenceComparison !== 0) {
                    return $confidenceComparison;
                }

                return strcmp($left['fact_id'], $right['fact_id']);
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    private function terms(string $query): array
    {
        preg_match_all('/[\pL\pN][\pL\pN_-]{1,}/u', mb_strtolower($query), $matches);

        $stopwords = array_flip([
            'about', 'after', 'again', 'also', 'answer', 'because', 'before',
            'could', 'does', 'from', 'have', 'into', 'that', 'their', 'there',
            'these', 'they', 'this', 'what', 'when', 'where', 'which', 'will',
            'with', 'would', 'your', 'the',
        ]);

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $term) => mb_strlen($term) >= 3 && ! isset($stopwords[$term]),
        )));
    }

    /**
     * @param  string[]  $terms
     */
    private function score(string $text, array $terms, float $confidence): float
    {
        $haystack = mb_strtolower($text);
        $matches = 0;
        $weightedMatches = 0.0;

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                continue;
            }

            $matches++;
            $weightedMatches += mb_strlen($term) >= 6 ? 1.25 : 1.0;
        }

        if ($matches === 0) {
            return 0.0;
        }

        $coverage = $matches / max(1, count($terms));

        return round(($weightedMatches * 2.0) + ($coverage * 3.0) + ($confidence * 0.25), 4);
    }
}
