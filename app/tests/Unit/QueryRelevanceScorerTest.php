<?php

namespace Tests\Unit;

use App\Services\QueryRelevanceScorer;
use PHPUnit\Framework\TestCase;

class QueryRelevanceScorerTest extends TestCase
{
    private QueryRelevanceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new QueryRelevanceScorer;
    }

    public function test_terms_lowercases_and_drops_stopwords_and_short_tokens(): void
    {
        $terms = $this->scorer->terms('What should I do about the PostgreSQL migration?');

        $this->assertContains('postgresql', $terms);
        $this->assertContains('migration', $terms);
        $this->assertNotContains('what', $terms);
        $this->assertNotContains('the', $terms);
        $this->assertNotContains('about', $terms);
        $this->assertNotContains('i', $terms);
    }

    public function test_terms_deduplicates(): void
    {
        $terms = $this->scorer->terms('redis redis REDIS caching');

        $this->assertSame(['redis', 'caching'], $terms);
    }

    public function test_terms_returns_empty_for_stopword_only_query(): void
    {
        $this->assertSame([], $this->scorer->terms('what should they have'));
    }

    public function test_score_zero_when_nothing_matches(): void
    {
        $terms = $this->scorer->terms('kubernetes ingress controller');

        $score = $this->scorer->score($terms, 'Chose PostgreSQL for the main data store.', 'Database choice', ['postgresql', 'database']);

        $this->assertSame(0.0, $score);
    }

    public function test_score_zero_for_empty_terms(): void
    {
        $this->assertSame(0.0, $this->scorer->score([], 'Any content at all.'));
    }

    public function test_content_match_scores_higher_with_longer_terms(): void
    {
        $short = $this->scorer->score(['api'], 'The api gateway timed out.');
        $long = $this->scorer->score(['gateway'], 'The api gateway timed out.');

        $this->assertGreaterThan($short, $long);
    }

    public function test_tag_match_adds_to_content_match(): void
    {
        $terms = ['postgresql'];

        $contentOnly = $this->scorer->score($terms, 'We picked postgresql for reporting.', 'DB decision', []);
        $contentAndTag = $this->scorer->score($terms, 'We picked postgresql for reporting.', 'DB decision', ['postgresql']);

        $this->assertGreaterThan($contentOnly, $contentAndTag);
    }

    public function test_label_match_counts_when_content_does_not_mention_term(): void
    {
        $score = $this->scorer->score(['migration'], 'Cutover completed in ninety seconds.', 'Postgres migration plan', []);

        $this->assertGreaterThan(0.0, $score);
    }

    public function test_score_does_not_match_substrings_inside_unrelated_tokens(): void
    {
        $score = $this->scorer->score(['api'], 'The capital planning memo was approved.', '', []);

        $this->assertSame(0.0, $score);
    }

    public function test_score_matches_hyphenated_compound_parts(): void
    {
        $score = $this->scorer->score(['limiting'], 'Gateway changes landed.', '', ['rate-limiting']);

        $this->assertGreaterThan(0.0, $score);
    }

    public function test_higher_term_coverage_scores_higher(): void
    {
        $terms = $this->scorer->terms('stripe billing enterprise invoicing');

        $partial = $this->scorer->score($terms, 'Stripe handles card payments.', '', []);
        $full = $this->scorer->score($terms, 'Stripe billing could not model enterprise invoicing.', '', []);

        $this->assertGreaterThan($partial, $full);
    }

    public function test_score_is_deterministic(): void
    {
        $terms = $this->scorer->terms('webhook circuit breaker timeout');
        $content = 'Webhook hardening added circuit breakers and timeouts.';

        $this->assertSame(
            $this->scorer->score($terms, $content, 'Webhook fix', ['webhooks']),
            $this->scorer->score($terms, $content, 'Webhook fix', ['webhooks']),
        );
    }
}
