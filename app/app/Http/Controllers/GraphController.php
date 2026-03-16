<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\GraphSnapshot;
use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\ClusterDetectionService;
use App\Services\MemoryGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GraphController extends Controller
{
    public function __construct(
        private readonly MemoryGraphService $graph,
        private readonly ClusterDetectionService $clusterDetector,
    ) {}

    /**
     * Render the graph explorer page.
     */
    public function index(): Response
    {
        return Inertia::render('Memory/Graph');
    }

    /**
     * Render the Three.js mission control page.
     *
     * Passes the list of agents under the current user so the Vue component can
     * lay out each agent's graph partition in its own spatial region on mount.
     */
    public function threeD(): Response
    {
        $userId = session('chat_user_id');

        $agents = $userId
            ? Agent::where('owner_user_id', $userId)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'graph_user_id' => $a->graph_user_id,
                    'trust_score' => $a->trust_score,
                ])
                ->values()
            : collect();

        return Inertia::render('Memory/ThreeD', ['agents' => $agents]);
    }

    /**
     * Return community clusters detected by weighted label propagation.
     *
     * Each cluster carries an id (the winning label UUID), the member node_ids,
     * the node_count, and the mean_weight of internal edges. The Three.js surface
     * uses mean_weight to color heat spheres from cool blue (low) to hot amber (high).
     */
    public function clusters(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        return response()->json([
            'clusters' => $this->clusterDetector->detect($userId),
        ]);
    }

    /**
     * List recent snapshots for the current user (most recent first, max 96).
     */
    public function snapshotIndex(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $snapshots = GraphSnapshot::where('user_id', $userId)
            ->orderByDesc('snapshot_at')
            ->limit(96)
            ->get(['id', 'snapshot_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'snapshot_at' => $s->snapshot_at->toIso8601String(),
            ]);

        return response()->json(['snapshots' => $snapshots]);
    }

    /**
     * Return the full payload for one snapshot.
     *
     * Returns 404 if the snapshot belongs to a different user.
     */
    public function snapshotShow(string $snapshotId): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $snapshot = GraphSnapshot::where('user_id', $userId)
            ->findOrFail($snapshotId);

        return response()->json($snapshot->payload);
    }

    /**
     * Return the full graph for the current user as JSON.
     * Supports ?types[]=memory&types[]=person and ?sensitivity[]=public filters.
     */
    public function data(Request $request): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $filters = [
            'types' => $request->array('types'),
            'sensitivity' => $request->array('sensitivity'),
        ];

        return response()->json($this->graph->getGraph($userId, $filters));
    }

    /**
     * Run one simulation tick for the current user's personal graph.
     *
     * Retrieves the Physarum neighbourhood, reinforces the retrieved nodes,
     * and returns the active node IDs alongside the updated weights of any
     * edges between those nodes. The browser uses this response to animate
     * which nodes were active and to update edge widths without re-rendering
     * the full graph.
     */
    public function simulate(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $context = $this->graph->retrieveContext($userId);
        $nodeIds = array_column($context, 'id');

        if (! empty($nodeIds)) {
            $this->graph->reinforce($nodeIds, $userId);
        }

        // Return updated edges between the active nodes so the browser can
        // transition their stroke widths without refetching the full graph.
        $updatedEdges = empty($nodeIds) ? [] : MemoryEdge::where('user_id', $userId)
            ->whereIn('from_node_id', $nodeIds)
            ->whereIn('to_node_id', $nodeIds)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'source' => $e->from_node_id,
                'target' => $e->to_node_id,
                'weight' => $e->weight,
            ])
            ->values()
            ->all();

        return response()->json([
            'active_node_ids' => $nodeIds,
            'updated_edges' => $updatedEdges,
        ]);
    }

    /**
     * Return a node and its neighborhood (up to $depth hops).
     */
    public function neighborhood(Request $request, string $nodeId): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');
        $depth = min($request->integer('depth', 2), 4);
        $filters = [
            'sensitivity' => $request->array('sensitivity'),
        ];

        return response()->json($this->graph->getNeighborhood($userId, $nodeId, $depth, $filters));
    }

    /**
     * Compute degree distribution and power-law fit for the current user's graph.
     *
     * Returns:
     *   - degree_distribution: histogram of node degrees (key = degree, value = count)
     *   - power_law.gamma: exponent from log-log linear regression on P(k) vs k
     *   - power_law.r_squared: coefficient of determination of the fit (1.0 = perfect)
     *   - power_law.is_scale_free: true if gamma in [2, 3] and R^2 >= 0.80
     *   - mean_clustering_coefficient: mean local clustering coefficient over all nodes
     *
     * The power-law fit follows Barabasi and Albert (1999): a scale-free graph has
     * P(k) ~ k^(-gamma) with gamma typically between 2 and 3. The fit is performed
     * by ordinary least squares on log(P(k)) vs log(k) for k >= 1.
     *
     * Clustering coefficient per node i follows Watts and Strogatz (1998):
     * C_i = (number of edges between neighbours of i) / (k_i * (k_i - 1) / 2)
     * where k_i is the degree of node i. Mean clustering is the arithmetic mean over
     * all nodes with degree >= 2.
     *
     * Graphs with fewer than 5 nodes return null for gamma, r_squared, and clustering
     * because the statistics are not meaningful at that scale.
     */
    public function topology(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $nodeCount = MemoryNode::where('user_id', $userId)->count();

        if ($nodeCount < 5) {
            return response()->json([
                'node_count' => $nodeCount,
                'edge_count' => 0,
                'degree_distribution' => [],
                'power_law' => [
                    'gamma' => null,
                    'r_squared' => null,
                    'is_scale_free' => null,
                    'criterion' => 'gamma in [2,3] and R^2 >= 0.80',
                ],
                'mean_clustering_coefficient' => null,
                'note' => 'Graph has fewer than 5 nodes. Topology statistics require a minimum of 5 nodes.',
            ]);
        }

        $edges = MemoryEdge::where('user_id', $userId)
            ->get(['from_node_id', 'to_node_id']);

        // Build undirected degree map: each directed edge contributes one degree
        // to each endpoint, treating the graph as undirected for degree analysis.
        $degrees = [];
        $adjacency = [];

        $allNodeIds = MemoryNode::where('user_id', $userId)->pluck('id');
        foreach ($allNodeIds as $id) {
            $degrees[$id] = 0;
        }

        foreach ($edges as $edge) {
            $u = $edge->from_node_id;
            $v = $edge->to_node_id;

            $degrees[$u] = ($degrees[$u] ?? 0) + 1;
            $degrees[$v] = ($degrees[$v] ?? 0) + 1;

            $adjacency[$u][$v] = true;
            $adjacency[$v][$u] = true;
        }

        // Degree distribution histogram: key = degree, value = number of nodes with that degree.
        $distribution = [];
        foreach ($degrees as $deg) {
            $distribution[$deg] = ($distribution[$deg] ?? 0) + 1;
        }
        ksort($distribution);

        // Power-law fit: log-log linear regression of P(k) = count(k)/n vs k, for k >= 1.
        $n = count($degrees);
        $logK = [];
        $logP = [];

        foreach ($distribution as $k => $count) {
            if ($k >= 1) {
                $logK[] = log((float) $k);
                $logP[] = log($count / $n);
            }
        }

        $gamma = null;
        $rSquared = null;

        if (count($logK) >= 3) {
            $m = count($logK);
            $sumX = array_sum($logK);
            $sumY = array_sum($logP);
            $sumXX = array_sum(array_map(fn ($x) => $x * $x, $logK));
            $sumXY = 0.0;
            for ($i = 0; $i < $m; $i++) {
                $sumXY += $logK[$i] * $logP[$i];
            }

            $denom = $m * $sumXX - $sumX * $sumX;

            if (abs($denom) > 1e-12) {
                $slope = ($m * $sumXY - $sumX * $sumY) / $denom;
                $intercept = ($sumY - $slope * $sumX) / $m;
                $gamma = round(-$slope, 4);

                $meanY = $sumY / $m;
                $ssTot = 0.0;
                $ssRes = 0.0;
                for ($i = 0; $i < $m; $i++) {
                    $ssTot += ($logP[$i] - $meanY) ** 2;
                    $ssRes += ($logP[$i] - ($slope * $logK[$i] + $intercept)) ** 2;
                }

                $rSquared = $ssTot > 1e-12 ? round(1.0 - $ssRes / $ssTot, 4) : null;
            }
        }

        $isScaleFree = $gamma !== null && $rSquared !== null
            && $gamma >= 2.0 && $gamma <= 3.0 && $rSquared >= 0.80;

        // Mean local clustering coefficient (Watts and Strogatz 1998).
        // Only computed when the graph is small enough to avoid O(V*d^2) cost.
        $meanClustering = null;

        if ($nodeCount <= 5000) {
            $clusteringValues = [];

            foreach ($adjacency as $node => $neighbors) {
                $k = count($neighbors);
                if ($k < 2) {
                    $clusteringValues[] = 0.0;
                    continue;
                }

                $neighborList = array_keys($neighbors);
                $triangles = 0;
                for ($i = 0; $i < count($neighborList); $i++) {
                    for ($j = $i + 1; $j < count($neighborList); $j++) {
                        if (isset($adjacency[$neighborList[$i]][$neighborList[$j]])) {
                            $triangles++;
                        }
                    }
                }

                $clusteringValues[] = (2.0 * $triangles) / ($k * ($k - 1));
            }

            if (count($clusteringValues) > 0) {
                $meanClustering = round(array_sum($clusteringValues) / count($clusteringValues), 4);
            }
        }

        return response()->json([
            'node_count' => $nodeCount,
            'edge_count' => count($edges),
            'degree_distribution' => $distribution,
            'power_law' => [
                'gamma' => $gamma,
                'r_squared' => $rSquared,
                'is_scale_free' => $isScaleFree,
                'criterion' => 'gamma in [2,3] and R^2 >= 0.80',
            ],
            'mean_clustering_coefficient' => $meanClustering,
            'note' => 'Degree and clustering are computed over the undirected graph induced by the directed edge set. Power-law fit uses ordinary least squares on log(P(k)) vs log(k) for k >= 1.',
        ]);
    }

    /**
     * Run the Physarum decay pass for the current user's graph in-browser.
     *
     * Applies RHO = 0.97 decay to all edges for the current user, floored at 0.05.
     * Equivalent to running `php artisan memory:decay` scoped to this user.
     * Returns the count of edges updated and the new minimum weight after decay.
     */
    public function decay(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');

        $updated = MemoryEdge::where('user_id', $userId)
            ->where('weight', '>', 0.05)
            ->get();

        $count = 0;
        foreach ($updated as $edge) {
            $newWeight = max(0.05, round($edge->weight * 0.97, 6));
            if ($newWeight !== (float) $edge->weight) {
                $edge->weight = $newWeight;
                $edge->save();
                $count++;
            }
        }

        $minWeight = MemoryEdge::where('user_id', $userId)->min('weight') ?? 0.05;
        $maxWeight = MemoryEdge::where('user_id', $userId)->max('weight') ?? 0.05;

        return response()->json([
            'edges_decayed' => $count,
            'min_weight' => round((float) $minWeight, 4),
            'max_weight' => round((float) $maxWeight, 4),
            'rho' => 0.97,
            'floor' => 0.05,
        ]);
    }

    /**
     * Take a graph snapshot for the current user's graph in-browser.
     *
     * Runs weighted label propagation cluster detection and stores the result
     * as a graph_snapshot record. Equivalent to running `php artisan graph:snapshot`.
     * Returns the snapshot ID and cluster count.
     */
    public function snapshot(): JsonResponse
    {
        $userId = session('chat_user_id', 'anonymous');
        $now = now();

        $clusters = $this->clusterDetector->detect($userId);

        $snapshot = GraphSnapshot::create([
            'user_id' => $userId,
            'snapshot_at' => $now,
            'payload' => [
                'clusters' => $clusters,
                'node_count' => MemoryNode::where('user_id', $userId)->count(),
                'edge_count' => MemoryEdge::where('user_id', $userId)->count(),
            ],
        ]);

        $oldest = GraphSnapshot::where('user_id', $userId)
            ->orderByDesc('snapshot_at')
            ->skip(96)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($oldest->isNotEmpty()) {
            GraphSnapshot::whereIn('id', $oldest)->delete();
        }

        return response()->json([
            'snapshot_id' => $snapshot->id,
            'cluster_count' => count($clusters),
            'snapshot_at' => $snapshot->snapshot_at->toIso8601String(),
        ]);
    }
}
