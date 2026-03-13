<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Database\Eloquent\Builder;

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
     */
    public function storeNode(
        string $userId,
        string $content,
        array $extracted,
        ?string $sessionId = null,
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
            'source' => 'chat',
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
