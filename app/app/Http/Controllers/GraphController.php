<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\GraphSnapshot;
use App\Models\MemoryEdge;
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
}
