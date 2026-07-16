<?php

namespace App\Services;

/**
 * Deterministic lexical relevance scoring between a user query and a memory node.
 *
 * This is the no-embedding baseline for query-aware retrieval. The scorer runs
 * entirely locally: no model call, no network access, no stored index. It uses
 * the same tokenization approach as EvidenceRetrievalService so that graph
 * retrieval and evidence retrieval agree on what counts as a query term.
 *
 * Scoring model per node:
 *   - each query term found as a node content token scores 1.0 (1.25 when the term
 *     is six characters or longer, since longer terms are more discriminative)
 *   - a term matching a node tag token adds 0.75; tags are the graph's extracted
 *     semantic vocabulary, so a tag hit is stronger evidence than a content hit
 *   - a term found as a node label token adds 0.5
 *   - term coverage (matched terms / query terms) adds up to 3.0
 *
 * A node that matches nothing scores exactly 0.0, which callers use as the
 * "no lexical signal" sentinel for fallback behaviour.
 */
class QueryRelevanceScorer
{
    /**
     * Extract significant lowercase terms from a redacted user query.
     *
     * @return string[]
     */
    public function terms(string $query): array
    {
        preg_match_all('/[\pL\pN][\pL\pN_-]{1,}/u', mb_strtolower($query), $matches);

        $stopwords = array_flip([
            'about', 'after', 'again', 'also', 'answer', 'because', 'before',
            'could', 'does', 'from', 'have', 'into', 'that', 'their', 'there',
            'these', 'they', 'this', 'what', 'when', 'where', 'which', 'will',
            'with', 'would', 'your', 'the', 'should', 'were', 'been', 'over',
        ]);

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $term) => mb_strlen($term) >= 3 && ! isset($stopwords[$term]),
        )));
    }

    /**
     * Score one node against pre-extracted query terms.
     *
     * @param  string[]  $terms  Output of terms()
     * @param  string[]  $tags
     */
    public function score(array $terms, string $content, string $label = '', array $tags = []): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $contentTokens = $this->tokenSet($content);
        $labelTokens = $this->tokenSet($label);
        $tagTokens = $this->tokenSet(implode(' ', array_filter($tags, 'is_string')));

        $matched = 0;
        $points = 0.0;

        foreach ($terms as $term) {
            $termMatched = false;

            if (isset($contentTokens[$term])) {
                $points += mb_strlen($term) >= 6 ? 1.25 : 1.0;
                $termMatched = true;
            }

            if (isset($tagTokens[$term])) {
                $points += 0.75;
                $termMatched = true;
            }

            if (isset($labelTokens[$term])) {
                $points += 0.5;
                $termMatched = true;
            }

            if ($termMatched) {
                $matched++;
            }
        }

        if ($matched === 0) {
            return 0.0;
        }

        $coverage = $matched / count($terms);

        return round($points + ($coverage * 3.0), 4);
    }

    /**
     * Build a token lookup for exact term matching.
     *
     * Compound tokens are indexed both as written and by their hyphen/underscore
     * parts, so "rate-limiting" can match either "rate-limiting" or "limiting"
     * without allowing unrelated substring matches inside longer words.
     *
     * @return array<string, true>
     */
    private function tokenSet(string $text): array
    {
        preg_match_all('/[\pL\pN][\pL\pN_-]{1,}/u', mb_strtolower($text), $matches);

        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            if (mb_strlen($token) >= 3) {
                $tokens[$token] = true;
            }

            foreach (preg_split('/[_-]+/u', $token) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $tokens[$part] = true;
                }
            }
        }

        return $tokens;
    }
}
