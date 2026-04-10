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
     * Three strategies are supported via the $strategy parameter:
     *   'recency': most recently created public nodes, no graph traversal.
     *   'graph': BFS from weight-ranked seeds, goal nodes treated as ordinary candidates.
     *   'goal_graph': BFS from goal-node seeds first, then weight-ranked seeds.
     *
     * Returns an empty array on cold start (no public nodes in the graph yet). The caller
     * is responsible for falling back to flat ICP recall in that case. Returns records in
     * the same shape as IcpMemoryService::getPublicMemories().
     *
     * @return array<int, array{id: string, content: string, timestamp: string}>
     */
    public function retrieveContext(string $userId, int $limit = 12, string $strategy = 'goal_graph'): array
    {
        if (! in_array($strategy, ['recency', 'graph', 'goal_graph'], true)) {
            throw new \InvalidArgumentException("Unknown retrieval strategy: {$strategy}");
        }

        if ($strategy === 'recency') {
            return $this->retrieveByRecency($userId, $limit);
        }

        $goalSeeding = $strategy === 'goal_graph';
        $seeds = $this->findContextSeeds($userId, 4, $goalSeeding);

        if ($seeds->isEmpty()) {
            return [];
        }

        $collected = collect($seeds);
        $visited = $seeds->pluck('id');
        $frontier = $seeds->pluck('id');

        while ($collected->count() < $limit && $frontier->isNotEmpty()) {
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
                ->filter();

            $collected = $collected->merge($neighbors)->unique('id')->take($limit);
            $visited = $visited->merge($neighbors->pluck('id'))->unique();
            $frontier = $neighbors->pluck('id');
        }

        return $collected
            ->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->content,
                'timestamp' => $n->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->values()
            ->all();
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
            ->latest()
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

                return ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);
            })
            ->take($remaining)
            ->values();

        return $goalSeeds->merge($weightedSeeds)->values();
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

    private function retrieveByRecency(string $userId, int $limit): array
    {
        return MemoryNode::where('user_id', $userId)
            ->where('sensitivity', 'public')
            ->whereNull('consolidated_at')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->content,
                'timestamp' => $n->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    // Physarum model constants (Tero et al. 2010, discrete form).
    // ALPHA: conductance increment per co-access event.
    // RHO:   daily retention factor (1 - decay_rate); 0.97 yields ~3 % daily decay.
    // WEIGHT_FLOOR: minimum weight; edges never fully disappear from the graph.
    private const ALPHA = 0.10;

    private const RHO = 0.97;

    private const WEIGHT_FLOOR = 0.05;

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
