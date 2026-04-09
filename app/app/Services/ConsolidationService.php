<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\LLM\LlmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consolidates dense episodic memory clusters into semantic concept nodes.
 *
 * Over time the memory graph accumulates many episodic nodes recording individual
 * conversation turns. When a set of episodic nodes becomes densely interconnected
 * (high mean internal edge weight, indicating frequent co-activation), they represent
 * a coherent topic that is better encoded as a single semantic node.
 *
 * This mirrors the hippocampal-to-cortical memory transfer described in systems
 * consolidation theory (McClelland et al. 1995): episodic traces that are repeatedly
 * reactivated together are compressed into stable semantic representations.
 *
 * Algorithm:
 *   1. Identify candidate clusters using the existing cluster detection output.
 *   2. Filter to clusters where mean internal edge weight >= MIN_WEIGHT and
 *      cluster size >= MIN_CLUSTER_SIZE.
 *   3. For each qualifying cluster, call the LLM to produce a one-sentence summary.
 *   4. Create a new 'concept' node with that summary.
 *   5. Wire 'supersedes' edges from the concept node to every episodic node.
 *   6. Re-wire the best external edges (highest weight connections leaving the cluster)
 *      to connect from the concept node instead, preserving graph topology.
 *   7. Mark all episodic nodes with consolidated_at = now().
 *
 * Consolidated nodes remain in the graph for audit purposes but are excluded from
 * context retrieval and from future consolidation passes.
 */
class ConsolidationService
{
    private const MIN_CLUSTER_SIZE = 5;

    private const MIN_WEIGHT = 0.3;

    // Max external edges to re-wire per cluster to keep the concept node's
    // degree bounded. Highest-weight external edges are preferred.
    private const MAX_EXTERNAL_REWIRE = 10;

    private const SUMMARIZE_PROMPT = <<<'PROMPT'
You are a knowledge graph consolidation agent.

Given a set of related memory facts about a user, write a single compact sentence that captures the common theme or persistent state they all describe. This sentence will become a long-term semantic memory node.

Rules:
- Write one sentence, maximum 30 words
- Focus on the enduring fact, not the individual episodes
- Do not start with "The user" — write naturally
- Output ONLY the sentence — no label, no explanation
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
        private readonly ClusterDetectionService $clusterDetector,
    ) {}

    /**
     * Run one consolidation pass for the given user.
     *
     * Returns a summary of what was consolidated.
     *
     * @return array{clusters_evaluated: int, clusters_consolidated: int, nodes_consolidated: int, concept_nodes_created: int}
     */
    public function consolidate(string $userId): array
    {
        $clusters = $this->clusterDetector->detect($userId);

        $evaluated = 0;
        $consolidated = 0;
        $nodesConsolidated = 0;
        $conceptsCreated = 0;

        foreach ($clusters as $cluster) {
            $evaluated++;

            if ($cluster['node_count'] < self::MIN_CLUSTER_SIZE) {
                continue;
            }

            if (($cluster['mean_weight'] ?? 0) < self::MIN_WEIGHT) {
                continue;
            }

            $nodeIds = $cluster['node_ids'];

            // Skip clusters that have already been consolidated.
            $unconsolidated = MemoryNode::where('user_id', $userId)
                ->whereIn('id', $nodeIds)
                ->whereNull('consolidated_at')
                ->where('type', '!=', 'goal')
                ->pluck('id')
                ->all();

            if (count($unconsolidated) < self::MIN_CLUSTER_SIZE) {
                continue;
            }

            $conceptNode = $this->consolidateCluster($userId, $unconsolidated);

            if ($conceptNode !== null) {
                $consolidated++;
                $nodesConsolidated += count($unconsolidated);
                $conceptsCreated++;
            }
        }

        return [
            'clusters_evaluated'    => $evaluated,
            'clusters_consolidated' => $consolidated,
            'nodes_consolidated'    => $nodesConsolidated,
            'concept_nodes_created' => $conceptsCreated,
        ];
    }

    /**
     * Consolidate one cluster of episodic nodes into a single concept node.
     *
     * Returns the created concept MemoryNode, or null if the LLM summary fails.
     */
    private function consolidateCluster(string $userId, array $episodicIds): ?MemoryNode
    {
        $nodes = MemoryNode::where('user_id', $userId)
            ->whereIn('id', $episodicIds)
            ->get(['id', 'label', 'content', 'sensitivity', 'tags']);

        if ($nodes->isEmpty()) {
            return null;
        }

        // Ask the LLM to summarize the cluster into one semantic sentence.
        $facts = $nodes->map(fn ($n) => "- {$n->content}")->implode("\n");
        $messages = [
            ['role' => 'user', 'content' => "Memory facts to consolidate:\n{$facts}"],
        ];

        $summary = trim($this->llm->chat(self::SUMMARIZE_PROMPT, $messages));

        if (empty($summary)) {
            Log::warning('ConsolidationService: empty LLM summary', ['episodic_ids' => $episodicIds]);

            return null;
        }

        // Determine the dominant sensitivity level (most restrictive wins).
        $sensitivities = $nodes->pluck('sensitivity')->unique()->values();
        $sensitivity = $sensitivities->contains('sensitive')
            ? 'sensitive'
            : ($sensitivities->contains('private') ? 'private' : 'public');

        // Merge tags from all episodic nodes.
        $allTags = $nodes->flatMap(fn ($n) => $n->tags ?? [])->unique()->values()->take(8)->all();

        // Create the semantic concept node.
        $concept = MemoryNode::create([
            'user_id'     => $userId,
            'type'        => 'concept',
            'sensitivity' => $sensitivity,
            'label'       => mb_substr($summary, 0, 80),
            'content'     => $summary,
            'tags'        => $allTags,
            'confidence'  => 0.9,
            'source'      => 'consolidated',
        ]);

        $now = Carbon::now();

        // Wire 'supersedes' edges from the concept to each episodic node.
        foreach ($episodicIds as $episodicId) {
            MemoryEdge::create([
                'user_id'      => $userId,
                'from_node_id' => $concept->id,
                'to_node_id'   => $episodicId,
                'relationship' => 'supersedes',
                'weight'       => 1.0,
            ]);
        }

        // Re-wire the highest-weight external edges to the new concept node.
        $externalEdges = MemoryEdge::where('user_id', $userId)
            ->where(function ($q) use ($episodicIds) {
                $q->whereIn('from_node_id', $episodicIds)->whereNotIn('to_node_id', $episodicIds);
                $q->orWhereIn('to_node_id', $episodicIds)->whereNotIn('from_node_id', $episodicIds);
            })
            ->orderByDesc('weight')
            ->limit(self::MAX_EXTERNAL_REWIRE)
            ->get();

        foreach ($externalEdges as $edge) {
            $externalNodeId = in_array($edge->from_node_id, $episodicIds)
                ? $edge->to_node_id
                : $edge->from_node_id;

            $alreadyExists = MemoryEdge::where('user_id', $userId)
                ->where(function ($q) use ($concept, $externalNodeId) {
                    $q->where('from_node_id', $concept->id)->where('to_node_id', $externalNodeId);
                    $q->orWhere('from_node_id', $externalNodeId)->where('to_node_id', $concept->id);
                })
                ->exists();

            if (! $alreadyExists) {
                MemoryEdge::create([
                    'user_id'      => $userId,
                    'from_node_id' => $concept->id,
                    'to_node_id'   => $externalNodeId,
                    'relationship' => $edge->relationship,
                    'weight'       => $edge->weight,
                ]);
            }
        }

        // Mark all episodic nodes as consolidated.
        MemoryNode::where('user_id', $userId)
            ->whereIn('id', $episodicIds)
            ->update(['consolidated_at' => $now]);

        return $concept;
    }
}
