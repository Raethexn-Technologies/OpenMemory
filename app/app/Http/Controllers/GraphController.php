<?php

namespace App\Http\Controllers;

use App\Models\MemoryEdge;
use App\Services\MemoryGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GraphController extends Controller
{
    public function __construct(
        private readonly MemoryGraphService $graph,
    ) {}

    /**
     * Render the graph explorer page.
     */
    public function index(): Response
    {
        return Inertia::render('Memory/Graph');
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
