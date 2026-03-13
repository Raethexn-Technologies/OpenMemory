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
     * Return the current shared-edge summary for all agents under this user.
     *
     * Used by the Three.js mission control surface to render cross-partition edges
     * between nodes that two or more agents have both reinforced. Each entry in the
     * response includes the content hash, both agent names, both node IDs, the
     * accumulated shared weight, and the access count. The browser uses node IDs to
     * locate the 3D positions of the two endpoints and draws a violet line between them.
     */
    public function sharedEdges(): \Illuminate\Http\JsonResponse
    {
        $userId = session()->get('chat_user_id');

        $edges = $userId
            ? $this->multiAgentService->getSharedEdgeSummary($userId)
            : [];

        return response()->json(['shared_edges' => $edges]);
    }

    /**
     * Return the full graph for one agent's partition (public nodes and edges only).
     *
     * Used by the Three.js mission control surface to lay out each agent's subgraph
     * in its own spatial region of the scene. Returns 404 if the agent does not
     * belong to the current session user.
     */
    public function graph(string $agentId): \Illuminate\Http\JsonResponse
    {
        $userId = session()->get('chat_user_id');
        $agent = Agent::where('owner_user_id', $userId)->findOrFail($agentId);

        return response()->json(
            $this->graphService->getGraph($agent->graph_user_id, ['sensitivity' => ['public']])
        );
    }

    /**
     * Compute pairwise intent alignment (Jaccard similarity) across all agents.
     *
     * For each agent, this method retrieves the Physarum neighbourhood without
     * mutating any edge weights (read-only). The resulting active node ID sets are
     * compared pairwise. Jaccard(A, B) = |A ∩ B| / |A ∪ B|.
     *
     * A Jaccard of 1.0 means two agents are pulling from identical memory regions.
     * A Jaccard near 0 means they are operating in entirely separate subgraphs.
     * A sudden drop after sustained alignment is an early signal worth investigating.
     *
     * This endpoint is intentionally read-only: no reinforce() call is made so
     * alignment checks do not perturb the Physarum dynamics.
     */
    public function alignment(): \Illuminate\Http\JsonResponse
    {
        $userId = session()->get('chat_user_id');
        $agents = Agent::where('owner_user_id', $userId)->get();

        if ($agents->count() < 2) {
            return response()->json(['pairs' => []]);
        }

        // Retrieve each agent's active context without reinforcing.
        $activeSets = [];
        foreach ($agents as $agent) {
            $context = $this->graphService->retrieveContext($agent->graph_user_id);
            $activeSets[$agent->id] = [
                'name' => $agent->name,
                // Compare semantic overlap across partitions by content hash.
                // Agent-local node UUIDs are always distinct, so Jaccard on node IDs
                // would incorrectly report zero even when two agents retrieved the
                // same underlying memory content.
                'content_hashes' => array_values(array_unique(array_map(
                    fn ($record) => hash('sha256', $record['content']),
                    $context,
                ))),
            ];
        }

        $pairs = [];
        $agentList = $agents->values();
        for ($i = 0; $i < $agentList->count(); $i++) {
            for ($j = $i + 1; $j < $agentList->count(); $j++) {
                $a = $agentList[$i];
                $b = $agentList[$j];

                $setA = $activeSets[$a->id]['content_hashes'];
                $setB = $activeSets[$b->id]['content_hashes'];

                $intersection = count(array_intersect($setA, $setB));
                $union = count(array_unique(array_merge($setA, $setB)));

                $jaccard = $union > 0 ? round($intersection / $union, 4) : 0.0;

                $pairs[] = [
                    'agent_a_id' => $a->id,
                    'agent_b_id' => $b->id,
                    'agent_a_name' => $a->name,
                    'agent_b_name' => $b->name,
                    'jaccard' => $jaccard,
                ];
            }
        }

        usort($pairs, fn ($x, $y) => $y['jaccard'] <=> $x['jaccard']);

        return response()->json(['pairs' => $pairs]);
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
