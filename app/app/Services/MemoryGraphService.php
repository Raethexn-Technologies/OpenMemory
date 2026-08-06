<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages the brain-like memory graph: nodes, edges, and neighborhood traversal.
 *
 * Nodes represent units of memory (facts, people, projects, events, concepts).
 * Edges represent semantic relationships between nodes, auto-wired by shared tags
 * and explicit entity references (people, projects) extracted by GraphExtractionService.
 */
class MemoryGraphService
{
    /**
     * Retrieval strategies supported by retrieveContext().
     *
     *   'recency': most recently created public nodes, no graph traversal.
     *   'graph': BFS from weight-ranked seeds, goal nodes treated as ordinary candidates.
     *   'goal_graph': BFS from goal-node seeds first, then weight-ranked seeds.
     *   'query_lexical': top query-relevant nodes directly, no graph traversal.
     *   'query_graph': BFS from seeds ranked by lexical relevance to the current query.
     *   'hybrid_query_graph': BFS from seeds ranked by a combined query, weight, and
     *      recency score, with goal seeds admitted only when lexically query-relevant.
     */
    public const STRATEGIES = ['recency', 'graph', 'goal_graph', 'query_lexical', 'query_graph', 'hybrid_query_graph'];

    public function __construct(
        private readonly QueryRelevanceScorer $queryScorer,
    ) {}

    /**
     * Store a memory node and auto-wire edges to related existing nodes.
     *
     * @param  array  $extracted  Output from GraphExtractionService::extract()
     * @param  string  $source  Origin of the memory: 'chat', 'document', 'manual', or 'extracted'
     * @param  array  $metadata  Arbitrary JSON metadata (e.g. source_document_id, chunk_index)
     */
    public function storeNode(
        string $userId,
        string $content,
        array $extracted,
        ?string $sessionId = null,
        string $source = 'chat',
        array $metadata = [],
    ): MemoryNode {
        $node = MemoryNode::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'type' => $extracted['type'],
            'sensitivity' => $extracted['sensitivity'],
            'label' => $extracted['label'],
            'content' => $content,
            'tags' => $extracted['tags'],
            'confidence' => 1.0,
            'source' => $source,
            'metadata' => $metadata ?: null,
        ]);

        // Auto-wire tag-based similarity edges
        $this->wireTagEdges($node, $userId);

        // Auto-wire person anchor nodes
        foreach ($extracted['people'] as $name) {
            $this->wirePersonEdge($node, $userId, $name);
        }

        // Auto-wire project anchor nodes
        foreach ($extracted['projects'] as $name) {
            $this->wireProjectEdge($node, $userId, $name);
        }

        return $node;
    }

    /**
     * Return the full graph for a user as nodes + edges arrays for D3.
     *
     * @param  array  $filters  Optional: types[], sensitivity[]
     */
    public function getGraph(string $userId, array $filters = []): array
    {
        $query = MemoryNode::where('user_id', $userId);

        if (! empty($filters['types'])) {
            $query->whereIn('type', $filters['types']);
        }

        // Default: public only. Caller must explicitly request private/sensitive.
        $sensitivity = ! empty($filters['sensitivity']) ? $filters['sensitivity'] : ['public'];
        $query->whereIn('sensitivity', $sensitivity);

        $nodes = $query->orderBy('created_at', 'desc')->get();
        $nodeIds = $nodes->pluck('id');

        $edges = MemoryEdge::where('user_id', $userId)
            ->whereIn('from_node_id', $nodeIds)
            ->whereIn('to_node_id', $nodeIds)
            ->get();

        return [
            'nodes' => $nodes->map(fn ($n) => $this->nodeToArray($n))->values(),
            'edges' => $edges->map(fn ($e) => $this->edgeToArray($e))->values(),
        ];
    }

    /**
     * Return a node and its neighborhood up to $depth hops.
     */
    public function getNeighborhood(string $userId, string $nodeId, int $depth = 2, array $filters = []): array
    {
        $nodeQuery = $this->nodeQuery($userId, $filters);
        $node = (clone $nodeQuery)->whereKey($nodeId)->firstOrFail();

        $visited = collect([$nodeId]);
        $allNodes = collect([$node]);
        $allEdges = collect();
        $frontier = collect([$nodeId]);

        for ($d = 0; $d < $depth; $d++) {
            $edges = MemoryEdge::where('user_id', $userId)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_node_id', $frontier)
                        ->orWhereIn('to_node_id', $frontier);
                })->get();

            $neighborIds = $edges
                ->flatMap(fn ($e) => [$e->from_node_id, $e->to_node_id])
                ->unique()
                ->diff($visited);

            if ($neighborIds->isEmpty()) {
                break;
            }

            $neighbors = (clone $nodeQuery)->whereIn('id', $neighborIds)->get();
            $visibleIds = $neighbors->pluck('id')->merge($frontier)->unique()->values();

            $edges = $edges->filter(fn ($edge) => $visibleIds->contains($edge->from_node_id) &&
                $visibleIds->contains($edge->to_node_id)
            );

            $allEdges = $allEdges->merge($edges)->unique('id');
            $allNodes = $allNodes->merge($neighbors)->unique('id');
            $visited = $visited->merge($neighbors->pluck('id'))->unique();
            $frontier = $neighbors->pluck('id');
        }

        return [
            'nodes' => $allNodes->map(fn ($n) => $this->nodeToArray($n))->values(),
            'edges' => $allEdges->map(fn ($e) => $this->edgeToArray($e))->values(),
        ];
    }

    /**
     * Create a directed relationship edge between two existing nodes.
     *
     * Exposed publicly so callers outside this service (e.g. DocumentIngestionService)
     * can wire specific relationships without accessing private internals. The underlying
     * createEdgeIfAbsent() call is idempotent: duplicate edges are silently skipped.
     */
    public function createRelationship(
        string $userId,
        string $fromNodeId,
        string $toNodeId,
        string $relationship,
        float $weight = 0.5,
    ): void {
        $this->createEdgeIfAbsent($userId, $fromNodeId, $toNodeId, $relationship, $weight);
    }

    // ── Physarum / Hebbian weight dynamics ───────────────────────────────────

    /**
     * Reinforce the nodes and edges that were loaded into the LLM context window.
     *
     * Implements the discrete form of the Tero et al. (2010) conductance update:
     *   w(t+1) = min(1.0,  w(t) + ALPHA)
     *
     * Called immediately after getPublicMemories() returns a set of node IDs,
     * so the edges between co-accessed nodes accumulate weight proportional to
     * how often the LLM finds them relevant together (Hebbian co-activation).
     *
     * Node access counts are incremented separately to support ACT-R-style
     * base-level activation retrieval ordering in future iterations.
     *
     * @param  string[]  $nodeIds  IDs of nodes loaded into the LLM context this turn.
     */
    public function reinforce(array $nodeIds, string $userId): void
    {
        if (count($nodeIds) < 2) {
            // A single node has no edges to reinforce between co-accessed peers.
            if (count($nodeIds) === 1) {
                MemoryNode::where('user_id', $userId)
                    ->whereIn('id', $nodeIds)
                    ->increment('access_count', 1, ['last_accessed_at' => Carbon::now()]);
            }

            return;
        }

        $now = Carbon::now();

        // Record node-level access for ACT-R activation tracking.
        MemoryNode::where('user_id', $userId)
            ->whereIn('id', $nodeIds)
            ->increment('access_count', 1, ['last_accessed_at' => $now]);

        // Find all edges that connect any two nodes in the co-accessed set.
        $edges = MemoryEdge::where('user_id', $userId)
            ->where(function ($q) use ($nodeIds) {
                $q->whereIn('from_node_id', $nodeIds)
                    ->whereIn('to_node_id', $nodeIds);
            })
            ->get();

        foreach ($edges as $edge) {
            $edge->weight = min(1.0, $edge->weight + self::ALPHA);
            $edge->access_count = $edge->access_count + 1;
            $edge->last_accessed_at = $now;
            $edge->save();
        }
    }

    /**
     * Apply time-based weight decay to all edges in the graph.
     *
     * Implements the Physarum decay term (Tero et al. 2010):
     *   w(t+1) = max(FLOOR,  w(t) * RHO)
     *
     * RHO = 0.97 means edges lose 3 % of their weight per day when not traversed.
     * An edge with initial weight 0.5 that is never accessed reaches the floor
     * after approximately 100 days. Edges that are regularly reinforced plateau
     * near 1.0 and decay back slowly during idle periods.
     *
     * This method is called by the DecayMemoryEdges artisan command, which should
     * be scheduled to run once per day via the Laravel scheduler.
     */
    public function decay(): void
    {
        // Bulk update keeps decay efficient while staying portable across SQLite and Postgres.
        DB::table('memory_edges')
            ->where('weight', '>', self::WEIGHT_FLOOR)
            ->update([
                'weight' => DB::raw(sprintf(
                    'CASE WHEN weight * %.2F < %.2F THEN %.2F ELSE weight * %.2F END',
                    self::RHO,
                    self::WEIGHT_FLOOR,
                    self::WEIGHT_FLOOR,
                    self::RHO,
                )),
            ]);
    }

    /**
     * Retrieve the Physarum neighbourhood most relevant for the current context window.
     *
     * Seeds are the public nodes with the highest accumulated edge weight. BFS from
     * those seeds collects neighbours in weight-descending order up to $limit.
     *
     * The strategies in self::STRATEGIES are supported via the $strategy parameter.
     * The graph strategies differ only in seed selection; BFS expansion is shared.
     * query_lexical is the query-aware non-graph control and returns direct matches.
     * $query is the current redacted user message. It affects seed selection for the
     * query-aware strategies and is ignored by 'recency', 'graph', and 'goal_graph'.
     *
     * Returns an empty array on cold start (no public nodes in the graph yet). The caller
     * is responsible for falling back to flat ICP recall in that case. Returns records in
     * the same shape as IcpMemoryService::getPublicMemories().
     *
     * @return array<int, array{id: string, content: string, timestamp: string}>
     */
    public function retrieveContext(string $userId, int $limit = 12, string $strategy = 'goal_graph', ?string $query = null): array
    {
        return $this->retrieveContextTraced($userId, $limit, $strategy, $query)['records'];
    }

    /**
     * Search only public, unconsolidated graph records that lexically match a
     * query. Unlike retrieveContext('query_lexical'), this method deliberately
     * does not fall back to recency: an MCP client asking a focused question
     * must not receive unrelated memories when there is no match.
     *
     * @return array<int, array{id: string, content: string, timestamp: string}>
     */
    public function searchPublic(string $userId, string $query, int $limit = 8): array
    {
        $terms = $this->queryScorer->terms($query);
        if ($terms === []) {
            return [];
        }

        [, , $ranked] = $this->rankQueryCandidates($userId, $terms);

        return $this->nodesToRecords($ranked->take($limit)->values());
    }

    /**
     * Retrieve context and return the seed-selection trace alongside the records.
     *
     * The trace explains why each seed entered the retrieval frontier: its lexical
     * query score, its accumulated edge weight, and the combined score where the
     * hybrid strategy applies. The benchmark stores this trace with every judged
     * result so a score can be audited back to the exact seed decision. Content is
     * not duplicated into the trace; only node IDs, types, and score components.
     *
     * @return array{
     *   records: array<int, array{id: string, content: string, timestamp: string}>,
     *   trace: array{strategy: string, query_terms: string[], seed_fallback: string|null, seeds: array, retrieved_ids: string[]}
     * }
     */
    public function retrieveContextTraced(string $userId, int $limit = 12, string $strategy = 'goal_graph', ?string $query = null): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException("Unknown retrieval strategy: {$strategy}");
        }

        $terms = $query !== null ? $this->queryScorer->terms($query) : [];

        $trace = [
            'strategy' => $strategy,
            'strategy_config' => [
                'context_limit' => $limit,
                'seed_count' => min(self::SEED_COUNT, max(0, $limit)),
                'query_pool' => self::QUERY_POOL,
            ],
            'query_terms' => $terms,
            'seed_fallback' => null,
            'seeds' => [],
            'candidate_count' => 0,
            'matched_candidate_count' => 0,
            'candidate_scores' => [],
            'selected_ids' => [],
            'direct_lexical_ids' => [],
            'graph_added_ids' => [],
            'traversal_depth' => 0,
            'traversed_edge_count' => 0,
            'retrieved_ids' => [],
        ];

        if ($strategy === 'recency') {
            $records = $this->retrieveByRecency($userId, $limit);
            $trace['selected_ids'] = array_column($records, 'id');
            $trace['retrieved_ids'] = array_column($records, 'id');

            return ['records' => $records, 'trace' => $trace];
        }

        if ($strategy === 'query_lexical') {
            [$records, $lexicalTrace] = $this->retrieveByQueryLexical($userId, $limit, $terms);
            $trace = array_merge($trace, $lexicalTrace);
            $trace['retrieved_ids'] = array_column($records, 'id');

            return ['records' => $records, 'trace' => $trace];
        }

        [$seeds, $seedDetail, $fallback, $candidateScores, $candidateCount, $matchedCandidateCount] = $this->selectSeeds(
            $userId,
            $strategy,
            $terms,
            min(self::SEED_COUNT, max(0, $limit)),
        );
        $trace['seeds'] = $seedDetail;
        $trace['seed_fallback'] = $fallback;
        $trace['candidate_scores'] = $candidateScores;
        $trace['candidate_count'] = $candidateCount;
        $trace['matched_candidate_count'] = $matchedCandidateCount;
        $trace['direct_lexical_ids'] = $this->directLexicalSeedIds($seedDetail);

        if ($seeds->isEmpty()) {
            return ['records' => [], 'trace' => $trace];
        }

        $expanded = $this->expandFromSeeds($userId, $seeds, $limit);
        $collected = $expanded['nodes'];

        $records = $this->nodesToRecords($collected);

        $trace['selected_ids'] = array_column($records, 'id');
        $trace['graph_added_ids'] = $expanded['expanded_ids'];
        $trace['traversal_depth'] = $expanded['traversal_depth'];
        $trace['traversed_edge_count'] = $expanded['traversed_edge_count'];
        $trace['retrieved_ids'] = array_column($records, 'id');

        return ['records' => $records, 'trace' => $trace];
    }

    /**
     * Select BFS seeds for one graph strategy.
     *
     * Deterministic fallbacks keep every strategy usable on every input:
     * a query-aware strategy without usable query terms degrades to its
     * query-blind counterpart ('graph' for query_graph, 'goal_graph' for
     * hybrid_query_graph), and a query that matches nothing degrades the
     * same way. The fallback taken is reported in the trace.
     *
     * @param  string[]  $terms
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: string|null, 3: array, 4: int, 5: int}
     */
    private function selectSeeds(string $userId, string $strategy, array $terms, int $seedCount): array
    {
        if ($strategy === 'graph' || $strategy === 'goal_graph') {
            $seeds = $this->findContextSeeds($userId, $seedCount, $strategy === 'goal_graph');

            return [$seeds, $this->describeSeeds($seeds), null, [], 0, 0];
        }

        if ($terms === []) {
            $goalSeeding = $strategy === 'hybrid_query_graph';
            $seeds = $this->findContextSeeds($userId, $seedCount, $goalSeeding);

            return [$seeds, $this->describeSeeds($seeds), 'no_query_terms', [], 0, 0];
        }

        [$seeds, $seedDetail, $candidateScores, $candidateCount, $matchedCandidateCount] = $strategy === 'query_graph'
            ? $this->findQuerySeeds($userId, $seedCount, $terms)
            : $this->findHybridSeeds($userId, $seedCount, $terms);

        if ($seeds->isEmpty()) {
            $goalSeeding = $strategy === 'hybrid_query_graph';
            $seeds = $this->findContextSeeds($userId, $seedCount, $goalSeeding);

            return [$seeds, $this->describeSeeds($seeds), 'no_lexical_match', $candidateScores, $candidateCount, $matchedCandidateCount];
        }

        return [$seeds, $seedDetail, null, $candidateScores, $candidateCount, $matchedCandidateCount];
    }

    /**
     * BFS expansion shared by all graph strategies. Neighbours are admitted in
     * edge-weight order and must be public and unconsolidated; private, sensitive,
     * and consolidated nodes are filtered at every hop regardless of strategy.
     */
    private function expandFromSeeds(string $userId, \Illuminate\Support\Collection $seeds, int $limit): array
    {
        $collected = collect($seeds)->unique('id')->take($limit)->values();
        $visited = $seeds->pluck('id');
        $frontier = $collected->pluck('id');
        $expandedIds = [];
        $traversalDepth = 0;
        $traversedEdgeCount = 0;

        while ($collected->count() < $limit && $frontier->isNotEmpty()) {
            $traversalDepth++;
            $edges = MemoryEdge::where('user_id', $userId)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_node_id', $frontier)
                        ->orWhereIn('to_node_id', $frontier);
                })
                ->orderByDesc('weight')
                ->orderBy('id')
                ->get();
            $traversedEdgeCount += $edges->count();

            $neighborIds = $edges
                ->flatMap(fn ($e) => [$e->from_node_id, $e->to_node_id])
                ->unique()
                ->diff($visited);

            if ($neighborIds->isEmpty()) {
                break;
            }

            $neighborsById = MemoryNode::where('user_id', $userId)
                ->where('sensitivity', 'public')
                ->whereNull('consolidated_at')
                ->whereIn('id', $neighborIds)
                ->get()
                ->keyBy('id');

            $neighbors = $neighborIds
                ->map(fn ($id) => $neighborsById->get($id))
                ->filter()
                ->values();

            $admitted = $neighbors->take($limit - $collected->count())->values();
            $expandedIds = array_values(array_unique(array_merge($expandedIds, $admitted->pluck('id')->all())));

            $collected = $collected->merge($admitted)->unique('id')->take($limit)->values();
            $visited = $visited->merge($neighbors->pluck('id'))->unique();
            $frontier = $admitted->pluck('id');
        }

        return [
            'nodes' => $collected,
            'expanded_ids' => $expandedIds,
            'traversal_depth' => $traversalDepth,
            'traversed_edge_count' => $traversedEdgeCount,
        ];
    }

    /**
     * Find the seed nodes for context retrieval.
     *
     * Goal nodes are always retrieved first, regardless of edge weight.
     * A goal represents a declared intention the user is actively working toward.
     * Seeding BFS from goal nodes biases context assembly toward knowledge connected
     * to those goals, so the LLM receives relevant document chunks and past memories
     * rather than only historically high-weight hubs that may not be task-relevant.
     *
     * Remaining seed slots are filled with the highest-weight non-goal public nodes
     * from a bounded candidate pool (60 most recently created). Goal IDs are excluded
     * from the weighted pool to prevent duplication. The sort logic is unchanged:
     * total connected edge weight descending, creation time as a tiebreaker.
     */
    private function findContextSeeds(string $userId, int $count, bool $goalSeeding = true): \Illuminate\Support\Collection
    {
        // Goal nodes fill first regardless of edge weight (unless goal seeding is disabled).
        $goalSeeds = $goalSeeding
            ? MemoryNode::where('user_id', $userId)
                ->where('type', 'goal')
                ->where('sensitivity', 'public')
                ->whereNull('consolidated_at')
                ->orderByDesc('created_at')
                ->orderBy('id')
                ->get()
            : collect();

        $remaining = max(0, $count - $goalSeeds->count());

        if ($remaining === 0) {
            return $goalSeeds->take($count)->values();
        }

        // Fill remaining slots with the highest-weight public nodes.
        // Exclude already-selected goal IDs to prevent duplication.
        $goalIds = $goalSeeds->pluck('id');

        $candidates = MemoryNode::where('user_id', $userId)
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->when($goalIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $goalIds))
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(60)
            ->get();

        if ($candidates->isEmpty()) {
            return $goalSeeds->values();
        }

        $candidateIds = $candidates->pluck('id');

        $edges = MemoryEdge::where('user_id', $userId)
            ->where(function ($q) use ($candidateIds) {
                $q->whereIn('from_node_id', $candidateIds)
                    ->orWhereIn('to_node_id', $candidateIds);
            })
            ->get();

        $scores = $candidates->mapWithKeys(fn ($node) => [
            $node->id => $edges
                ->filter(fn ($e) => $e->from_node_id === $node->id || $e->to_node_id === $node->id)
                ->sum('weight'),
        ]);

        $weightedSeeds = $candidates
            ->sort(function ($left, $right) use ($scores) {
                $scoreComparison = $scores[$right->id] <=> $scores[$left->id];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return $this->compareByRecencyThenId($left, $right);
            })
            ->take($remaining)
            ->values();

        return $goalSeeds->merge($weightedSeeds)->values();
    }

    /**
     * Find seeds ranked purely by lexical relevance to the current query.
     *
     * The candidate pool is the QUERY_POOL most recently created public,
     * unconsolidated nodes. Only nodes with a nonzero lexical score qualify;
     * ties break on creation time (newer first). Goal nodes receive no special
     * treatment: they are seeded only when the query mentions them.
     *
     * @param  string[]  $terms
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: array, 3: int, 4: int}
     */
    private function findQuerySeeds(string $userId, int $count, array $terms): array
    {
        [$candidates, $scores, $ranked] = $this->rankQueryCandidates($userId, $terms);

        $seeds = $ranked->take($count)->values();
        $seedIds = $seeds->pluck('id')->flip();

        $detail = $seeds->map(fn ($node) => [
            'node_id' => $node->id,
            'type' => $node->type,
            'selected_by' => 'query',
            'query_score' => $scores[$node->id],
        ])->all();

        $candidateScores = $ranked
            ->take(20)
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'type' => $node->type,
                'query_score' => $scores[$node->id],
                'selected' => $seedIds->has($node->id),
            ])
            ->values()
            ->all();

        return [$seeds, $detail, $candidateScores, $candidates->count(), $ranked->count()];
    }

    /**
     * Find seeds ranked by a combined query, edge-weight, and recency score.
     *
     * Each component is normalized to [0, 1] within the candidate pool, then
     * combined as 0.5 * query + 0.3 * weight + 0.2 * recency. The weighting
     * expresses the design position that the current question matters most,
     * accumulated usage second, and freshness third; the constants are fixed
     * and documented rather than tuned per corpus.
     *
     * Goal nodes are handled adaptively: a goal seed is admitted ahead of the
     * combined ranking only when its lexical query score is nonzero. A goal the
     * query does not touch competes like any other node, so planning questions
     * pull goals in and historical fact questions do not pay the goal-slot cost.
     *
     * @param  string[]  $terms
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: array, 3: int, 4: int}
     */
    private function findHybridSeeds(string $userId, int $count, array $terms): array
    {
        $candidates = $this->queryCandidatePool($userId);

        if ($candidates->isEmpty()) {
            return [collect(), [], [], 0, 0];
        }

        $queryScores = $candidates->mapWithKeys(fn ($node) => [
            $node->id => $this->queryScorer->score($terms, $node->content, $node->label ?? '', $node->tags ?? []),
        ]);

        $matchedCandidateCount = $queryScores->filter(fn ($score) => $score > 0)->count();

        if ($matchedCandidateCount === 0) {
            return [collect(), [], [], $candidates->count(), 0];
        }

        $candidateIds = $candidates->pluck('id');
        $edges = MemoryEdge::where('user_id', $userId)
            ->where(function ($q) use ($candidateIds) {
                $q->whereIn('from_node_id', $candidateIds)
                    ->orWhereIn('to_node_id', $candidateIds);
            })
            ->get();

        $weightScores = $candidates->mapWithKeys(fn ($node) => [
            $node->id => $edges
                ->filter(fn ($e) => $e->from_node_id === $node->id || $e->to_node_id === $node->id)
                ->sum('weight'),
        ]);

        $timestamps = $candidates->mapWithKeys(fn ($node) => [
            $node->id => $node->created_at?->getTimestamp() ?? 0,
        ]);

        $queryMax = max(1e-9, $queryScores->max());
        $weightMax = max(1e-9, $weightScores->max());
        $tsMin = $timestamps->min();
        $tsRange = max(1, $timestamps->max() - $tsMin);

        $recencyScores = $candidates->mapWithKeys(fn ($node) => [
            $node->id => round(($timestamps[$node->id] - $tsMin) / $tsRange, 6),
        ]);

        $combined = $candidates->mapWithKeys(fn ($node) => [
            $node->id => round(
                0.5 * ($queryScores[$node->id] / $queryMax)
                + 0.3 * ($weightScores[$node->id] / $weightMax)
                + 0.2 * $recencyScores[$node->id],
                6,
            ),
        ]);

        // Query-relevant goals fill first; everything else competes on combined score.
        $relevantGoals = $candidates
            ->filter(fn ($node) => $node->type === 'goal' && $queryScores[$node->id] > 0)
            ->sort(function ($left, $right) use ($queryScores) {
                $scoreComparison = $queryScores[$right->id] <=> $queryScores[$left->id];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return $this->compareByRecencyThenId($left, $right);
            })
            ->take($count)
            ->values();

        $goalIds = $relevantGoals->pluck('id')->flip();

        $ranked = $candidates
            ->reject(fn ($node) => $goalIds->has($node->id))
            ->sort(function ($left, $right) use ($combined) {
                $scoreComparison = $combined[$right->id] <=> $combined[$left->id];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return $this->compareByRecencyThenId($left, $right);
            })
            ->take(max(0, $count - $relevantGoals->count()))
            ->values();

        $seeds = $relevantGoals->merge($ranked)->values();

        $detail = $seeds->map(fn ($node) => [
            'node_id' => $node->id,
            'type' => $node->type,
            'selected_by' => $goalIds->has($node->id) ? 'query_relevant_goal' : 'hybrid',
            'query_score' => $queryScores[$node->id],
            'weight_score' => round($weightScores[$node->id], 4),
            'recency_score' => $recencyScores[$node->id],
            'combined_score' => $combined[$node->id],
        ])->all();

        $seedIds = $seeds->pluck('id')->flip();
        $candidateScores = $candidates
            ->sort(function ($left, $right) use ($combined) {
                $scoreComparison = $combined[$right->id] <=> $combined[$left->id];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return $this->compareByRecencyThenId($left, $right);
            })
            ->take(20)
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'type' => $node->type,
                'query_score' => $queryScores[$node->id],
                'weight_score' => round($weightScores[$node->id], 4),
                'recency_score' => $recencyScores[$node->id],
                'combined_score' => $combined[$node->id],
                'selected' => $seedIds->has($node->id),
            ])
            ->values()
            ->all();

        return [$seeds, $detail, $candidateScores, $candidates->count(), $matchedCandidateCount];
    }

    /**
     * Bounded candidate pool for query-aware seed selection. Larger than the
     * weight-seed pool because query matching should reach older memories that
     * no longer rank on recency or accumulated weight.
     */
    private function queryCandidatePool(string $userId): \Illuminate\Support\Collection
    {
        return MemoryNode::where('user_id', $userId)
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(self::QUERY_POOL)
            ->get();
    }

    /**
     * Direct lexical retrieval baseline: same scoped candidate pool and scorer
     * as query_graph, but no graph traversal, edge weighting, or goal seeding.
     *
     * @param  string[]  $terms
     * @return array{0: array, 1: array}
     */
    private function retrieveByQueryLexical(string $userId, int $limit, array $terms): array
    {
        if ($terms === []) {
            $records = $this->retrieveByRecency($userId, $limit);

            return [$records, [
                'seed_fallback' => 'no_query_terms',
                'selected_ids' => array_column($records, 'id'),
                'retrieved_ids' => array_column($records, 'id'),
            ]];
        }

        [$candidates, $scores, $ranked] = $this->rankQueryCandidates($userId, $terms);
        $selected = $ranked->take($limit)->values();
        $selectedIds = $selected->pluck('id')->flip();

        if ($selected->isEmpty()) {
            $records = $this->retrieveByRecency($userId, $limit);

            return [$records, [
                'seed_fallback' => 'no_lexical_match',
                'candidate_count' => $candidates->count(),
                'matched_candidate_count' => 0,
                'selected_ids' => array_column($records, 'id'),
                'retrieved_ids' => array_column($records, 'id'),
            ]];
        }

        $seedDetail = $selected->map(fn ($node) => [
            'node_id' => $node->id,
            'type' => $node->type,
            'selected_by' => 'query_lexical',
            'query_score' => $scores[$node->id],
        ])->all();

        $candidateScores = $ranked
            ->take(20)
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'type' => $node->type,
                'query_score' => $scores[$node->id],
                'selected' => $selectedIds->has($node->id),
            ])
            ->values()
            ->all();

        $records = $this->nodesToRecords($selected);

        return [$records, [
            'seed_fallback' => null,
            'seeds' => $seedDetail,
            'candidate_count' => $candidates->count(),
            'matched_candidate_count' => $ranked->count(),
            'candidate_scores' => $candidateScores,
            'selected_ids' => array_column($records, 'id'),
            'direct_lexical_ids' => array_column($seedDetail, 'node_id'),
            'graph_added_ids' => [],
            'traversal_depth' => 0,
            'traversed_edge_count' => 0,
            'retrieved_ids' => array_column($records, 'id'),
        ]];
    }

    /**
     * @param  string[]  $terms
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     */
    private function rankQueryCandidates(string $userId, array $terms): array
    {
        $candidates = $this->queryCandidatePool($userId);

        $scores = $candidates->mapWithKeys(fn ($node) => [
            $node->id => $this->queryScorer->score($terms, $node->content, $node->label ?? '', $node->tags ?? []),
        ]);

        $ranked = $candidates
            ->filter(fn ($node) => $scores[$node->id] > 0)
            ->sort(function ($left, $right) use ($scores) {
                $scoreComparison = $scores[$right->id] <=> $scores[$left->id];
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return $this->compareByRecencyThenId($left, $right);
            })
            ->values();

        return [$candidates, $scores, $ranked];
    }

    /**
     * Minimal trace description for seeds chosen by the query-blind strategies.
     */
    private function describeSeeds(\Illuminate\Support\Collection $seeds): array
    {
        return $seeds->map(fn ($node) => [
            'node_id' => $node->id,
            'type' => $node->type,
            'selected_by' => $node->type === 'goal' ? 'goal' : 'weight',
        ])->all();
    }

    private function directLexicalSeedIds(array $seedDetail): array
    {
        return array_values(array_map(
            static fn (array $seed) => $seed['node_id'],
            array_filter($seedDetail, static fn (array $seed) => ($seed['query_score'] ?? 0) > 0),
        ));
    }

    private function compareByRecencyThenId(MemoryNode $left, MemoryNode $right): int
    {
        $recencyComparison = ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);
        if ($recencyComparison !== 0) {
            return $recencyComparison;
        }

        return strcmp((string) $left->id, (string) $right->id);
    }

    /**
     * Trace the retrieval pipeline phase by phase for visualisation.
     *
     * Mirrors retrieveContext() but emits a structured record of every step
     * the algorithm actually performs: which nodes were selected as goal seeds,
     * which were selected by edge-weight score, how each BFS hop expanded the
     * frontier, how many neighbours were filtered out (private/sensitive/consolidated),
     * which final set was assembled, and which edges were reinforced.
     *
     * The trace duplicates the algorithm rather than parameterising retrieveContext
     * with a collector callback, so the hot path stays untouched. If retrieveContext
     * changes, this method must change with it; the two are tested against the same
     * final node-id set in the trace endpoint test.
     *
     * @return array{phases: array<int, array<string, mixed>>, active_node_ids: string[]}
     */
    public function traceRetrieveContext(string $userId, int $limit = 12): array
    {
        $phases = [];

        // ── Phase 1: goal seeds ─────────────────────────────────────────────
        $goalSeeds = MemoryNode::where('user_id', $userId)
            ->where('type', 'goal')
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->get();

        $phases[] = [
            'kind' => 'goal_seed',
            'node_ids' => $goalSeeds->pluck('id')->all(),
        ];

        // ── Phase 2: weight-ranked seed candidates ──────────────────────────
        $remaining = max(0, 4 - $goalSeeds->count());
        $weightedSeeds = collect();
        $candidatePool = collect();
        $candidateScores = [];

        if ($remaining > 0) {
            $goalIds = $goalSeeds->pluck('id');
            $candidates = MemoryNode::where('user_id', $userId)
                ->where('sensitivity', 'public')
                ->whereNull('consolidated_at')
                ->when($goalIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $goalIds))
                ->latest()
                ->limit(60)
                ->get();

            if ($candidates->isNotEmpty()) {
                $candidateIds = $candidates->pluck('id');
                $edges = MemoryEdge::where('user_id', $userId)
                    ->where(function ($q) use ($candidateIds) {
                        $q->whereIn('from_node_id', $candidateIds)
                            ->orWhereIn('to_node_id', $candidateIds);
                    })
                    ->get();

                $scores = $candidates->mapWithKeys(fn ($node) => [
                    $node->id => $edges
                        ->filter(fn ($e) => $e->from_node_id === $node->id || $e->to_node_id === $node->id)
                        ->sum('weight'),
                ]);

                $candidatePool = $candidates;
                $candidateScores = $scores->all();

                $weightedSeeds = $candidates
                    ->sort(function ($left, $right) use ($scores) {
                        $scoreComparison = $scores[$right->id] <=> $scores[$left->id];
                        if ($scoreComparison !== 0) {
                            return $scoreComparison;
                        }

                        return ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);
                    })
                    ->take($remaining)
                    ->values();
            }
        }

        $phases[] = [
            'kind' => 'weight_seed',
            'node_ids' => $weightedSeeds->pluck('id')->all(),
            'considered_ids' => $candidatePool->pluck('id')->all(),
            'scores' => $candidateScores,
        ];

        // ── Phase 3+: BFS expansion ─────────────────────────────────────────
        $seeds = $goalSeeds->merge($weightedSeeds)->values();
        if ($seeds->isEmpty()) {
            $phases[] = ['kind' => 'context_assembled', 'node_ids' => []];
            $phases[] = ['kind' => 'reinforce', 'node_ids' => [], 'edges' => []];

            return ['phases' => $phases, 'active_node_ids' => []];
        }

        $collected = collect($seeds);
        $visited = $seeds->pluck('id');
        $frontier = $seeds->pluck('id');
        $depth = 0;

        while ($collected->count() < $limit && $frontier->isNotEmpty()) {
            $depth++;
            $edges = MemoryEdge::where('user_id', $userId)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_node_id', $frontier)
                        ->orWhereIn('to_node_id', $frontier);
                })
                ->orderByDesc('weight')
                ->get();

            $neighborIds = $edges
                ->flatMap(fn ($e) => [$e->from_node_id, $e->to_node_id])
                ->unique()
                ->diff($visited)
                ->values();

            if ($neighborIds->isEmpty()) {
                break;
            }

            $neighborsById = MemoryNode::where('user_id', $userId)
                ->where('sensitivity', 'public')
                ->whereNull('consolidated_at')
                ->whereIn('id', $neighborIds)
                ->get()
                ->keyBy('id');

            $neighbors = $neighborIds
                ->map(fn ($id) => $neighborsById->get($id))
                ->filter()
                ->values();

            // Pair each admitted neighbour with the highest-weight edge that brought it in.
            $frontierSet = $frontier->flip();
            $admitted = $neighbors->map(function ($node) use ($edges, $frontierSet) {
                $bringIn = $edges->first(function ($e) use ($node, $frontierSet) {
                    return ($e->from_node_id === $node->id && $frontierSet->has($e->to_node_id))
                        || ($e->to_node_id === $node->id && $frontierSet->has($e->from_node_id));
                });

                return [
                    'node_id' => $node->id,
                    'via_edge_id' => $bringIn?->id,
                    'edge_weight' => $bringIn?->weight,
                    'source_frontier_id' => $bringIn
                        ? ($frontierSet->has($bringIn->from_node_id) ? $bringIn->from_node_id : $bringIn->to_node_id)
                        : null,
                ];
            })->values()->all();

            $rejectedCount = $neighborIds->diff($neighborsById->keys())->count();

            $phases[] = [
                'kind' => 'bfs_hop',
                'depth' => $depth,
                'frontier_ids' => $frontier->values()->all(),
                'admitted' => $admitted,
                'rejected_neighbor_count' => $rejectedCount,
            ];

            $collected = $collected->merge($neighbors)->unique('id')->take($limit);
            $visited = $visited->merge($neighbors->pluck('id'))->unique();
            $frontier = $neighbors->pluck('id');
        }

        // ── Phase N-1: assembled context ────────────────────────────────────
        $finalIds = $collected->pluck('id')->all();
        $phases[] = [
            'kind' => 'context_assembled',
            'node_ids' => $finalIds,
        ];

        // ── Phase N: reinforce (predicted edge updates) ─────────────────────
        // We project the +ALPHA update without applying it; the caller is responsible
        // for calling reinforce() if it wants the persistent side effect.
        $reinforcedEdges = empty($finalIds) ? collect() : MemoryEdge::where('user_id', $userId)
            ->whereIn('from_node_id', $finalIds)
            ->whereIn('to_node_id', $finalIds)
            ->get();

        $phases[] = [
            'kind' => 'reinforce',
            'node_ids' => $finalIds,
            'edges' => $reinforcedEdges->map(fn ($e) => [
                'id' => $e->id,
                'source' => $e->from_node_id,
                'target' => $e->to_node_id,
                'weight_before' => $e->weight,
                'weight_after' => min(1.0, $e->weight + self::ALPHA),
            ])->values()->all(),
        ];

        return [
            'phases' => $phases,
            'active_node_ids' => $finalIds,
        ];
    }

    /**
     * Look up the graph nodes that correspond to a set of ICP memory records,
     * call reinforce() on their IDs, and return those IDs for the API response.
     *
     * ICP memory records and graph nodes are linked by content string equality.
     * This is the correct join point because the graph node is created from the
     * same content string that ICP stores, so matching on content is exact.
     *
     * @param  array<int, array{content: string, ...}>  $memories  Records from IcpMemoryService::getPublicMemories()
     * @return string[] Graph node IDs that were reinforced, for the active_node_ids response field.
     */
    public function reinforceFromMemories(array $memories, string $userId): array
    {
        if (empty($memories)) {
            return [];
        }

        $contents = array_column($memories, 'content');

        $nodeIds = MemoryNode::where('user_id', $userId)
            ->whereIn('content', $contents)
            ->pluck('id')
            ->all();

        $this->reinforce($nodeIds, $userId);

        return $nodeIds;
    }

    private function nodesToRecords(\Illuminate\Support\Collection $nodes): array
    {
        return $nodes
            ->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->content,
                'timestamp' => $n->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function retrieveByRecency(string $userId, int $limit): array
    {
        $nodes = MemoryNode::where('user_id', $userId)
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->nodesToRecords($nodes);
    }

    // Physarum model constants (Tero et al. 2010, discrete form).
    // ALPHA: conductance increment per co-access event.
    // RHO:   daily retention factor (1 - decay_rate); 0.97 yields ~3 % daily decay.
    // WEIGHT_FLOOR: minimum weight; edges never fully disappear from the graph.
    private const ALPHA = 0.10;

    private const RHO = 0.97;

    private const WEIGHT_FLOOR = 0.05;

    // Retrieval constants.
    // SEED_COUNT: BFS seeds selected per retrieval, shared by all graph strategies.
    // QUERY_POOL: candidate pool size for query-aware seed scoring. Wider than the
    // 60-node weight pool so a query can reach memories that recency and accumulated
    // weight no longer surface.
    private const SEED_COUNT = 4;

    private const QUERY_POOL = 200;

    // ── Edge auto-wiring ──────────────────────────────────────────────────────

    private function wireTagEdges(MemoryNode $node, string $userId): void
    {
        if (empty($node->tags)) {
            return;
        }

        // Check the 100 most recent nodes for tag overlap
        $existing = MemoryNode::where('user_id', $userId)
            ->where('id', '!=', $node->id)
            ->latest()
            ->limit(100)
            ->get();

        foreach ($existing as $other) {
            $shared = array_intersect($node->tags ?? [], $other->tags ?? []);
            if (count($shared) >= 1) {
                $weight = min(1.0, count($shared) * 0.3);
                $this->createEdgeIfAbsent($userId, $node->id, $other->id, 'same_topic_as', $weight);
            }
        }
    }

    private function wirePersonEdge(MemoryNode $node, string $userId, string $personName): void
    {
        $anchor = MemoryNode::where('user_id', $userId)
            ->where('type', 'person')
            ->where('sensitivity', $node->sensitivity)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($personName)])
            ->first();

        if (! $anchor) {
            $anchor = MemoryNode::create([
                'user_id' => $userId,
                'type' => 'person',
                'sensitivity' => $node->sensitivity,
                'label' => $personName,
                'content' => "Person anchor: {$personName}",
                'tags' => ['person', strtolower($personName)],
                'confidence' => 0.8,
                'source' => 'extracted',
            ]);
        }

        $this->createEdgeIfAbsent($userId, $node->id, $anchor->id, 'about_person', 0.9);
    }

    private function wireProjectEdge(MemoryNode $node, string $userId, string $projectName): void
    {
        $anchor = MemoryNode::where('user_id', $userId)
            ->where('type', 'project')
            ->where('sensitivity', $node->sensitivity)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($projectName)])
            ->first();

        if (! $anchor) {
            $anchor = MemoryNode::create([
                'user_id' => $userId,
                'type' => 'project',
                'sensitivity' => $node->sensitivity,
                'label' => $projectName,
                'content' => "Project anchor: {$projectName}",
                'tags' => ['project', strtolower($projectName)],
                'confidence' => 0.8,
                'source' => 'extracted',
            ]);
        }

        $this->createEdgeIfAbsent($userId, $node->id, $anchor->id, 'part_of', 0.9);
    }

    private function createEdgeIfAbsent(
        string $userId,
        string $fromId,
        string $toId,
        string $relationship,
        float $weight = 0.5,
    ): void {
        $exists = MemoryEdge::where('user_id', $userId)
            ->where(function ($query) use ($fromId, $toId, $relationship) {
                $query->where(function ($inner) use ($fromId, $toId, $relationship) {
                    $inner->where('from_node_id', $fromId)
                        ->where('to_node_id', $toId)
                        ->where('relationship', $relationship);
                })->orWhere(function ($inner) use ($fromId, $toId, $relationship) {
                    $inner->where('from_node_id', $toId)
                        ->where('to_node_id', $fromId)
                        ->where('relationship', $relationship);
                });
            })
            ->exists();

        if (! $exists) {
            MemoryEdge::create([
                'user_id' => $userId,
                'from_node_id' => $fromId,
                'to_node_id' => $toId,
                'relationship' => $relationship,
                'weight' => $weight,
            ]);
        }
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    private function nodeToArray(MemoryNode $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'sensitivity' => $n->sensitivity,
            'label' => $n->label,
            'content' => $n->content,
            'tags' => $n->tags ?? [],
            'confidence' => $n->confidence,
            'source' => $n->source,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    private function edgeToArray(MemoryEdge $e): array
    {
        return [
            'id' => $e->id,
            'source' => $e->from_node_id,
            'target' => $e->to_node_id,
            'relationship' => $e->relationship,
            'weight' => $e->weight,
        ];
    }

    private function nodeQuery(string $userId, array $filters = []): Builder
    {
        $query = MemoryNode::query()->where('user_id', $userId);

        if (! empty($filters['types'])) {
            $query->whereIn('type', $filters['types']);
        }

        $query->whereIn('sensitivity', $this->visibleSensitivities($filters));

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function visibleSensitivities(array $filters = []): array
    {
        return ! empty($filters['sensitivity']) ? $filters['sensitivity'] : ['public'];
    }
}
