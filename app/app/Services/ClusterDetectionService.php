<?php

namespace App\Services;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;

/**
 * Detects communities in a user's memory graph using weighted label propagation.
 *
 * Label propagation (Raghavan et al. 2007) is a near-linear-time community detection
 * algorithm. Each node starts as its own community. In each iteration every node adopts
 * the label held by the majority of its weighted neighbours. The algorithm converges when
 * no label changes in a full pass, typically within 5-20 iterations on sparse graphs.
 *
 * The weighted variant used here accumulates edge weight by neighbour label rather than
 * counting neighbours directly, so strongly connected pairs pull the community label more
 * than weakly connected ones. Tie-breaking by lowest UUID string ensures determinism given
 * the same graph state.
 *
 * Output per cluster:
 *   id          - the winning label UUID (identifies the cluster across snapshot history)
 *   node_ids    - array of member node UUIDs
 *   node_count  - number of nodes in the cluster
 *   mean_weight - mean weight of internal edges (edges with both endpoints in the cluster)
 *
 * Isolated nodes (no edges) each form their own singleton cluster.
 */
class ClusterDetectionService
{
    private const MAX_ITERATIONS = 50;

    /**
     * Run label propagation on the given user's memory graph and return clusters.
     *
     * @return array<int, array{id: string, node_ids: string[], node_count: int, mean_weight: float}>
     */
    public function detect(string $userId): array
    {
        $nodeIds = MemoryNode::where('user_id', $userId)
            ->pluck('id')
            ->all();

        if (empty($nodeIds)) {
            return [];
        }

        $edges = MemoryEdge::where('user_id', $userId)
            ->whereIn('from_node_id', $nodeIds)
            ->whereIn('to_node_id', $nodeIds)
            ->get(['from_node_id', 'to_node_id', 'weight']);

        // Build an undirected adjacency map: node -> [neighbour -> accumulated_weight].
        // Multiple parallel edges between the same pair are summed.
        $adj = [];
        foreach ($nodeIds as $id) {
            $adj[$id] = [];
        }
        foreach ($edges as $edge) {
            $adj[$edge->from_node_id][$edge->to_node_id] =
                ($adj[$edge->from_node_id][$edge->to_node_id] ?? 0.0) + $edge->weight;
            $adj[$edge->to_node_id][$edge->from_node_id] =
                ($adj[$edge->to_node_id][$edge->from_node_id] ?? 0.0) + $edge->weight;
        }

        // Initialise: every node is its own community.
        $labels = [];
        foreach ($nodeIds as $id) {
            $labels[$id] = $id;
        }

        // Iterate until convergence or the iteration cap.
        for ($iter = 0; $iter < self::MAX_ITERATIONS; $iter++) {
            $order = $nodeIds;
            sort($order);

            $changed = false;
            foreach ($order as $nodeId) {
                $neighbours = $adj[$nodeId];
                if (empty($neighbours)) {
                    continue;
                }

                // Accumulate weight by neighbour label.
                $labelWeights = [];
                foreach ($neighbours as $neighbourId => $weight) {
                    $lbl = $labels[$neighbourId];
                    $labelWeights[$lbl] = ($labelWeights[$lbl] ?? 0.0) + $weight;
                }

                // Pick the label with the highest accumulated weight.
                // Break ties by the lexicographically smallest UUID so the result is
                // deterministic when the same graph state is processed twice.
                $best = null;
                $bestWeight = -1.0;
                foreach ($labelWeights as $lbl => $w) {
                    if ($w > $bestWeight || ($w === $bestWeight && ($best === null || $lbl < $best))) {
                        $best = $lbl;
                        $bestWeight = $w;
                    }
                }

                if ($best !== null && $best !== $labels[$nodeId]) {
                    $labels[$nodeId] = $best;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        // Group node IDs by final label.
        $groups = [];
        foreach ($labels as $nodeId => $label) {
            $groups[$label][] = $nodeId;
        }

        // Compute mean internal edge weight for each cluster.
        $edgeList = $edges->all();
        $result = [];
        foreach ($groups as $label => $members) {
            $memberSet = array_flip($members);
            $internalWeights = [];
            foreach ($edgeList as $edge) {
                if (isset($memberSet[$edge->from_node_id], $memberSet[$edge->to_node_id])) {
                    $internalWeights[] = $edge->weight;
                }
            }

            $meanWeight = count($internalWeights) > 0
                ? array_sum($internalWeights) / count($internalWeights)
                : 0.0;

            $result[] = [
                'id' => $label,
                'node_ids' => array_values($members),
                'node_count' => count($members),
                'mean_weight' => round($meanWeight, 4),
            ];
        }

        return $result;
    }
}
