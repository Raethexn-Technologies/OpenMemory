<?php

namespace App\Http\Controllers;

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
