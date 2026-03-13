<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\SharedMemoryEdge;
use App\Services\MemoryGraphService;
use App\Services\MultiAgentGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function __construct(
        private readonly MultiAgentGraphService $multiAgentService,
        private readonly MemoryGraphService $graphService,
    ) {}

    /**
     * Show the agent simulation page.
     */
    public function index(): Response
    {
        $userId = session()->get('chat_user_id');
        $agents = Agent::where('owner_user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'graph_user_id' => $a->graph_user_id,
                'trust_score' => $a->trust_score,
                'access_count' => $a->access_count,
                'last_active_at' => $a->last_active_at?->toIso8601String(),
            ]);

        $sharedEdges = $userId
            ? $this->multiAgentService->getSharedEdgeSummary($userId)
            : [];

        return Inertia::render('Agents/Index', [
            'agents' => $agents,
            'shared_edges' => $sharedEdges,
        ]);
    }

    /**
     * Create a new agent.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'trust_score' => 'nullable|numeric|min:0|max:1',
        ]);

        $userId = session()->get('chat_user_id');
        if (! $userId) {
            return response()->json(['error' => 'No user identity. Please refresh.'], 422);
        }

        $agent = Agent::create([
            'owner_user_id' => $userId,
            'graph_user_id' => 'agent_'.Str::uuid(),
            'name' => $validated['name'],
            'trust_score' => $validated['trust_score'] ?? 0.5,
        ]);

        return response()->json([
            'id' => $agent->id,
            'name' => $agent->name,
            'graph_user_id' => $agent->graph_user_id,
            'trust_score' => $agent->trust_score,
            'access_count' => 0,
            'last_active_at' => null,
        ], 201);
    }

    /**
     * Update an agent's trust score.
     */
    public function updateTrust(Request $request, string $agentId)
    {
        $validated = $request->validate([
            'trust_score' => 'required|numeric|min:0|max:1',
        ]);

        $userId = session()->get('chat_user_id');
        $agent = Agent::where('owner_user_id', $userId)->findOrFail($agentId);
        $agent->update(['trust_score' => $validated['trust_score']]);

        return response()->json(['trust_score' => $agent->trust_score]);
    }

    /**
     * Seed an agent's graph partition from the owner's public memory nodes.
     *
     * Copies the owner's most recent public memory nodes into the agent's graph
     * partition so shared edges can form when both the agent and the owner (or
     * another agent) reinforce the same content.
     */
    public function seed(string $agentId)
    {
        $userId = session()->get('chat_user_id');
        $agent = Agent::where('owner_user_id', $userId)->findOrFail($agentId);

        $count = $this->multiAgentService->seedFromOwner($agent);

        return response()->json(['seeded' => $count]);
    }

    /**
     * Run graph-guided retrieval for an agent and reinforce shared edges with peers.
     *
     * Returns the collective context (personal nodes boosted by peer collective weight),
     * the active node IDs, and the shared edge state after reinforcement. This is the
     * core simulation endpoint: run it for each agent to observe how collective
     * Physarum weights develop across the agent population.
     */
    public function simulate(Request $request, string $agentId)
    {
        $userId = session()->get('chat_user_id');
        $agent = Agent::where('owner_user_id', $userId)->findOrFail($agentId);

        // Collective context: personal Physarum neighbourhood boosted by peer weights.
        $context = $this->multiAgentService->retrieveCollectiveContext($agent);
        $nodeIds = array_column($context, 'id');

        // Reinforce the personal graph for this agent.
        if (! empty($nodeIds)) {
            $this->graphService->reinforce($nodeIds, $agent->graph_user_id);
        }

        // Reinforce shared edges between this agent and peers that hold the same content.
        $this->multiAgentService->reinforceShared($nodeIds, $agent);

        // Record agent activity.
        $agent->increment('access_count', 1, ['last_active_at' => now()]);

        return response()->json([
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'trust_score' => $agent->trust_score,
            'active_node_ids' => $nodeIds,
            'context' => array_map(fn ($c) => [
                'id' => $c['id'],
                'content' => $c['content'],
                'collective_weight' => $c['collective_weight'] ?? 0.0,
            ], $context),
            'shared_edges' => $this->multiAgentService->getSharedEdgeSummary($userId),
        ]);
    }

    /**
     * Run simulation for all agents under the current user in a single request.
     *
     * Each agent retrieves its collective context and reinforces shared edges.
     * The response includes all agents' results side-by-side and the updated
     * shared edge summary, which is the primary data source for the simulation UI.
     */
    public function simulateAll()
    {
        $userId = session()->get('chat_user_id');
        $agents = Agent::where('owner_user_id', $userId)->get();

        $results = [];
        foreach ($agents as $agent) {
            $context = $this->multiAgentService->retrieveCollectiveContext($agent);
            $nodeIds = array_column($context, 'id');

            if (! empty($nodeIds)) {
                $this->graphService->reinforce($nodeIds, $agent->graph_user_id);
            }

            $this->multiAgentService->reinforceShared($nodeIds, $agent);
            $agent->increment('access_count', 1, ['last_active_at' => now()]);

            $results[] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'trust_score' => $agent->trust_score,
                'active_node_ids' => $nodeIds,
                'context' => array_map(fn ($c) => [
                    'id' => $c['id'],
                    'content' => $c['content'],
                    'collective_weight' => $c['collective_weight'] ?? 0.0,
                ], $context),
            ];
        }

        return response()->json([
            'results' => $results,
            'shared_edges' => $this->multiAgentService->getSharedEdgeSummary($userId),
        ]);
    }

    /**
     * Delete an agent and its graph partition.
     */
    public function destroy(string $agentId)
    {
        $userId = session()->get('chat_user_id');
        $agent = Agent::where('owner_user_id', $userId)->findOrFail($agentId);
        $this->multiAgentService->deleteAgent($agent);

        return response()->json(['ok' => true]);
    }
}
