<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryAwareRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(
        string $userId,
        string $content,
        string $label = '',
        array $tags = [],
        string $type = 'memory',
        string $sensitivity = 'public',
    ): MemoryNode {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => $type,
            'sensitivity' => $sensitivity,
            'label' => $label ?: $content,
            'content' => $content,
            'tags' => $tags,
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
    }

    private function selfEdge(MemoryNode $node, float $weight): void
    {
        MemoryEdge::create([
            'user_id' => $node->user_id,
            'from_node_id' => $node->id,
            'to_node_id' => $node->id,
            'relationship' => 'related_to',
            'weight' => $weight,
        ]);
    }

    // query_graph seed selection.

    public function test_query_graph_seeds_query_relevant_node_over_high_weight_hub(): void
    {
        $service = app(MemoryGraphService::class);

        // Old, low-weight node that matches the query.
        $relevant = $this->makeNode('u1', 'Chose PostgreSQL over MongoDB for reporting joins.', 'DB decision', ['postgresql']);
        $relevant->created_at = now()->subDays(300);
        $relevant->saveQuietly();

        // Recent, high-weight hub that does not match the query.
        $hub = $this->makeNode('u1', 'Weekly standup moved thirty minutes earlier.', 'Standup time', ['ops']);
        $this->selfEdge($hub, 1.0);

        $result = $service->retrieveContext('u1', 1, 'query_graph', 'Why did we pick postgresql?');

        $this->assertSame([$relevant->id], array_column($result, 'id'));
    }

    public function test_query_graph_reaches_older_nodes_than_recency(): void
    {
        $service = app(MemoryGraphService::class);

        $old = $this->makeNode('u2', 'SAML integration took three times the estimate.', 'SAML lesson', ['saml']);
        $old->created_at = now()->subDays(400);
        $old->saveQuietly();

        for ($i = 0; $i < 5; $i++) {
            $this->makeNode('u2', "Routine ops note number {$i} about office logistics.", "Ops {$i}", ['ops']);
        }

        $recency = $service->retrieveContext('u2', 3, 'recency', 'What did we learn about saml estimates?');
        $queryAware = $service->retrieveContext('u2', 3, 'query_graph', 'What did we learn about saml estimates?');

        $this->assertNotContains($old->id, array_column($recency, 'id'));
        $this->assertContains($old->id, array_column($queryAware, 'id'));
    }

    public function test_query_graph_gives_goals_no_special_treatment(): void
    {
        $service = app(MemoryGraphService::class);

        $goal = $this->makeNode('u3', 'Goal: raise net revenue retention.', 'NRR goal', ['nrr'], 'goal');
        $match = $this->makeNode('u3', 'Redis caching uses a five minute TTL.', 'Cache TTL', ['redis']);

        $result = $service->retrieveContext('u3', 1, 'query_graph', 'What is our redis caching TTL?');

        $ids = array_column($result, 'id');
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($goal->id, $ids);
    }

    public function test_query_lexical_returns_direct_matches_without_graph_expansion(): void
    {
        $service = app(MemoryGraphService::class);

        $match = $this->makeNode('u3b', 'Postgresql chosen for reporting joins.', 'DB decision', ['postgresql']);
        $neighbor = $this->makeNode('u3b', 'Unrelated partner note connected by graph only.', 'Partner note', ['partners']);

        MemoryEdge::create([
            'user_id' => 'u3b',
            'from_node_id' => $match->id,
            'to_node_id' => $neighbor->id,
            'relationship' => 'related_to',
            'weight' => 1.0,
        ]);

        $lexical = $service->retrieveContextTraced('u3b', 12, 'query_lexical', 'Why postgresql?');
        $graph = $service->retrieveContextTraced('u3b', 12, 'query_graph', 'Why postgresql?');

        $this->assertSame([$match->id], array_column($lexical['records'], 'id'));
        $this->assertSame([], $lexical['trace']['graph_added_ids']);
        $this->assertContains($neighbor->id, array_column($graph['records'], 'id'));
        $this->assertContains($neighbor->id, $graph['trace']['graph_added_ids']);
    }

    // Fallback behaviour.

    public function test_query_graph_without_query_falls_back_to_weight_seeding(): void
    {
        $service = app(MemoryGraphService::class);

        $hub = $this->makeNode('u4', 'Hub memory with accumulated weight.', 'Hub');
        $this->selfEdge($hub, 0.9);

        $result = $service->retrieveContext('u4', 4, 'query_graph');

        $this->assertContains($hub->id, array_column($result, 'id'));
    }

    public function test_query_graph_with_no_lexical_match_falls_back_to_weight_seeding(): void
    {
        $service = app(MemoryGraphService::class);

        $hub = $this->makeNode('u5', 'Hub memory with accumulated weight.', 'Hub');
        $this->selfEdge($hub, 0.9);

        $traced = $service->retrieveContextTraced('u5', 4, 'query_graph', 'zeppelin quarks nebula');

        $this->assertSame('no_lexical_match', $traced['trace']['seed_fallback']);
        $this->assertContains($hub->id, $traced['trace']['retrieved_ids']);
    }

    public function test_hybrid_with_no_lexical_match_falls_back_to_goal_graph(): void
    {
        $service = app(MemoryGraphService::class);

        $goal = $this->makeNode('u5b', 'Goal: ship the billing launch.', 'Billing goal', ['billing'], 'goal');
        $recent = $this->makeNode('u5b', 'Recent operations note.', 'Ops note', ['ops']);
        $this->selfEdge($recent, 0.9);

        $traced = $service->retrieveContextTraced('u5b', 4, 'hybrid_query_graph', 'zeppelin quarks nebula');

        $this->assertSame('no_lexical_match', $traced['trace']['seed_fallback']);
        $this->assertContains($goal->id, $traced['trace']['retrieved_ids']);
    }

    // hybrid_query_graph adaptive goal seeding.

    public function test_hybrid_admits_goal_seed_only_when_query_relevant(): void
    {
        $service = app(MemoryGraphService::class);

        $goal = $this->makeNode('u6', 'Goal: roll out distributed tracing for webhooks.', 'Tracing goal', ['tracing'], 'goal');
        for ($i = 0; $i < 6; $i++) {
            $node = $this->makeNode('u6', "Unrelated memory {$i} about billing metrics.", "Billing {$i}", ['billing']);
            $this->selfEdge($node, 0.8);
        }

        $planning = $service->retrieveContext('u6', 4, 'hybrid_query_graph', 'How should I plan the tracing rollout?');
        $historical = $service->retrieveContext('u6', 4, 'hybrid_query_graph', 'What do we know about billing metrics?');

        $this->assertContains($goal->id, array_column($planning, 'id'), 'Query-relevant goal must be seeded.');
        $this->assertNotContains($goal->id, array_column($historical, 'id'), 'Query-irrelevant goal must not consume a seed slot.');
    }

    public function test_hybrid_without_query_behaves_like_goal_graph(): void
    {
        $service = app(MemoryGraphService::class);

        $goal = $this->makeNode('u7', 'Goal: ship the EU region.', 'EU goal', [], 'goal');
        $hub = $this->makeNode('u7', 'High weight hub node.', 'Hub');
        $this->selfEdge($hub, 0.9);

        $traced = $service->retrieveContextTraced('u7', 4, 'hybrid_query_graph');

        $this->assertSame('no_query_terms', $traced['trace']['seed_fallback']);
        $this->assertContains($goal->id, $traced['trace']['retrieved_ids']);
        $this->assertContains($hub->id, $traced['trace']['retrieved_ids']);
    }

    // Security regressions: sensitivity and consolidation filtering.

    public function test_query_graph_never_returns_private_or_sensitive_nodes_even_on_exact_match(): void
    {
        $service = app(MemoryGraphService::class);

        $private = $this->makeNode('u8', 'Salary negotiation notes for the acme offer.', 'Salary notes', ['salary'], 'memory', 'private');
        $sensitive = $this->makeNode('u8', 'Acme salary figure discussed with recruiter.', 'Salary figure', ['salary'], 'memory', 'sensitive');
        $public = $this->makeNode('u8', 'Public acme project kickoff happened.', 'Acme kickoff', ['acme']);

        foreach (['query_graph', 'hybrid_query_graph'] as $strategy) {
            $result = $service->retrieveContext('u8', 12, $strategy, 'acme salary negotiation figure');

            $ids = array_column($result, 'id');
            $this->assertNotContains($private->id, $ids, "{$strategy} leaked a private node.");
            $this->assertNotContains($sensitive->id, $ids, "{$strategy} leaked a sensitive node.");
            $this->assertContains($public->id, $ids);
        }
    }

    public function test_query_graph_excludes_consolidated_nodes_matching_the_query(): void
    {
        $service = app(MemoryGraphService::class);

        $consolidated = $this->makeNode('u9', 'Legacy webhook retry policy details.', 'Old webhook policy', ['webhooks']);
        $consolidated->consolidated_at = now();
        $consolidated->saveQuietly();

        $active = $this->makeNode('u9', 'Current webhook circuit breaker settings.', 'Webhook breakers', ['webhooks']);

        $result = $service->retrieveContext('u9', 12, 'query_graph', 'webhook policy settings');

        $ids = array_column($result, 'id');
        $this->assertNotContains($consolidated->id, $ids);
        $this->assertContains($active->id, $ids);
    }

    public function test_query_graph_does_not_cross_user_boundaries(): void
    {
        $service = app(MemoryGraphService::class);

        $other = $this->makeNode('other-user', 'Postgresql upgrade completed.', 'PG upgrade', ['postgresql']);
        $mine = $this->makeNode('u10', 'Postgresql chosen for reporting.', 'PG decision', ['postgresql']);

        $result = $service->retrieveContext('u10', 12, 'query_graph', 'postgresql');

        $ids = array_column($result, 'id');
        $this->assertNotContains($other->id, $ids);
        $this->assertContains($mine->id, $ids);
    }

    public function test_bfs_expansion_from_query_seeds_still_filters_private_neighbors(): void
    {
        $service = app(MemoryGraphService::class);

        $seed = $this->makeNode('u11', 'Public grafana consolidation decision.', 'Grafana', ['grafana']);
        $privateNeighbor = $this->makeNode('u11', 'Private vendor pricing details.', 'Vendor pricing', ['vendors'], 'memory', 'private');

        MemoryEdge::create([
            'user_id' => 'u11',
            'from_node_id' => $seed->id,
            'to_node_id' => $privateNeighbor->id,
            'relationship' => 'related_to',
            'weight' => 1.0,
        ]);

        $result = $service->retrieveContext('u11', 12, 'query_graph', 'grafana consolidation');

        $ids = array_column($result, 'id');
        $this->assertContains($seed->id, $ids);
        $this->assertNotContains($privateNeighbor->id, $ids);
    }

    // Trace contract.

    public function test_traced_retrieval_reports_seed_scores_and_matches_records(): void
    {
        $service = app(MemoryGraphService::class);

        $this->makeNode('u12', 'Token bucket rate limiting replaced fixed windows.', 'Rate limiting', ['rate-limiting']);

        $traced = $service->retrieveContextTraced('u12', 12, 'query_graph', 'token bucket rate limiting');

        $this->assertSame('query_graph', $traced['trace']['strategy']);
        $this->assertContains('token', $traced['trace']['query_terms']);
        $this->assertNotEmpty($traced['trace']['seeds']);
        $this->assertSame('query', $traced['trace']['seeds'][0]['selected_by']);
        $this->assertGreaterThan(0, $traced['trace']['seeds'][0]['query_score']);
        $this->assertSame(array_column($traced['records'], 'id'), $traced['trace']['retrieved_ids']);
        $this->assertSame(array_column($traced['records'], 'id'), $traced['trace']['selected_ids']);
        $this->assertContains($traced['records'][0]['id'], $traced['trace']['direct_lexical_ids']);
    }

    public function test_trace_does_not_include_node_content_labels_or_tags(): void
    {
        $service = app(MemoryGraphService::class);

        $this->makeNode('u13', 'Full memory content that must stay out of traces.', 'Trace label secret', ['trace-secret']);

        $traced = $service->retrieveContextTraced('u13', 12, 'query_graph', 'trace');

        $encoded = json_encode($traced['trace']);

        $this->assertStringNotContainsString('Full memory content that must stay out of traces.', $encoded);
        $this->assertStringNotContainsString('Trace label secret', $encoded);
        $this->assertStringNotContainsString('trace-secret', $encoded);
    }

    public function test_unknown_strategy_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MemoryGraphService::class)->retrieveContext('u14', 12, 'embedding_graph', 'query');
    }
}
