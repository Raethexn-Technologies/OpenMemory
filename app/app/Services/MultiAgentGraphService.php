<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\MemoryNode;
use App\Models\SharedMemoryEdge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages collective Physarum dynamics across multiple agents.
 *
 * Each agent has its own graph partition (graph_user_id) in the memory_nodes and
 * memory_edges tables. When two agents under the same owner both hold nodes derived
 * from the same ICP memory content, a shared edge accumulates collective weight.
 *
 * The shared edge weight update uses a trust-weighted ALPHA:
 *   shared_weight(t+1) = min(1.0, shared_weight(t) + SHARED_ALPHA * agent.trust_score)
 *
 * An agent with trust_score = 0.0 contributes nothing to collective weights, which
 * is the MemoryGraft resistance mechanism: a newly registered or untrusted agent
 * cannot shift the shared graph toward poisoned nodes until it has established a
 * reputation through verified contributions.
 *
 * Content identity is determined by SHA-256 hash of the memory content string. This
 * matches the exact-content join used in MemoryGraphService::reinforceFromMemories()
 * and is correct as long as the same LLM summarization pipeline produces the same
 * content string for the same source exchange.
 */
class MultiAgentGraphService
{
    // Shared edge conductance increment per co-access event.
    // Lower than the personal ALPHA (0.10) because collective reinforcement accumulates
    // from multiple agents and would saturate faster at the same increment rate.
    private const SHARED_ALPHA = 0.06;

    // Initial weight for a newly created shared edge.
    private const SHARED_WEIGHT_INITIAL = 0.3;

    public function __construct(
        private readonly MemoryGraphService $graphService,
    ) {}

    /**
     * After an agent reinforces its local nodes, find other agents under the same
     * owner that hold nodes derived from the same content and update the shared edges
     * between them using trust-weighted ALPHA.
     *
     * @param  string[]  $nodeIds  IDs of nodes the triggering agent just reinforced.
     */
    public function reinforceShared(array $nodeIds, Agent $agent): void
    {
        if (empty($nodeIds)) {
            return;
        }

        $nodes = MemoryNode::where('user_id', $agent->graph_user_id)
            ->whereIn('id', $nodeIds)
            ->get();
        if ($nodes->isEmpty()) {
            return;
        }

        // Build content hash → node map for fast lookup.
        $hashToNode = $nodes->keyBy(fn ($n) => hash('sha256', $n->content));

        // Find all other agents owned by the same user.
        $otherAgents = Agent::where('owner_user_id', $agent->owner_user_id)
            ->where('id', '!=', $agent->id)
            ->get();

        if ($otherAgents->isEmpty()) {
            return;
        }

        $otherAgentIds = $otherAgents->pluck('graph_user_id');

        // Fetch nodes from other agents' partitions that share content with the reinforced set.
        $otherNodes = MemoryNode::whereIn('user_id', $otherAgentIds)->get();

        foreach ($otherNodes as $otherNode) {
            $hash = hash('sha256', $otherNode->content);
            $sourceNode = $hashToNode[$hash] ?? null;

            if (! $sourceNode) {
                continue;
            }

            // Resolve which agent owns this other node.
            $otherAgent = $otherAgents->firstWhere('graph_user_id', $otherNode->user_id);
            if (! $otherAgent) {
                continue;
            }

            $trustAlpha = self::SHARED_ALPHA * $agent->trust_score;
            $this->updateSharedEdge($agent->owner_user_id, $agent, $otherAgent, $sourceNode, $otherNode, $hash, $trustAlpha);
        }
    }

    /**
     * Retrieve context for an agent, with nodes that have strong collective weight
     * from peer agents promoted in the ordering.
     *
     * The personal graph provides the candidate set. Shared edges from other agents
     * add a collective boost to nodes they have also reinforced, which means nodes
     * the group considers important rise above nodes only this agent has accessed.
     *
     * @return array<int, array{id: string, content: string, timestamp: string, collective_weight: float}>
     */
    public function retrieveCollectiveContext(Agent $agent, int $limit = 12): array
    {
        $personalContext = $this->graphService->retrieveContext($agent->graph_user_id, $limit);

        if (empty($personalContext)) {
            return [];
        }

        $contentHashes = array_map(fn ($c) => hash('sha256', $c['content']), $personalContext);

        // Sum collective boost per content hash from all shared edges involving this agent.
        $sharedEdges = SharedMemoryEdge::where('owner_user_id', $agent->owner_user_id)
            ->where(function ($q) use ($agent) {
                $q->where('agent_a_id', $agent->id)
                    ->orWhere('agent_b_id', $agent->id);
            })
            ->whereIn('content_hash', $contentHashes)
            ->with(['agentA', 'agentB'])
            ->get();

        $collectiveBoosts = [];
        foreach ($sharedEdges as $edge) {
            $peerAgent = $edge->agent_a_id === $agent->id ? $edge->agentB : $edge->agentA;
            $boost = $edge->weight * ($peerAgent?->trust_score ?? 0.5);
            $hash = $edge->content_hash;
            $collectiveBoosts[$hash] = ($collectiveBoosts[$hash] ?? 0.0) + $boost;
        }

        // Annotate each record with its collective weight and re-sort.
        $annotated = array_map(function ($record) use ($collectiveBoosts) {
            $hash = hash('sha256', $record['content']);
            $record['collective_weight'] = $collectiveBoosts[$hash] ?? 0.0;

            return $record;
        }, $personalContext);

        usort($annotated, fn ($a, $b) => $b['collective_weight'] <=> $a['collective_weight']);

        return $annotated;
    }

    /**
     * Seed an agent's graph partition with copies of the owner's public memory nodes.
     *
     * This creates graph nodes in the agent's partition (graph_user_id) from the same
     * content as the owner's personal nodes, which is what allows shared edges to form.
     * The GraphExtractionService is not called again; the existing label, tags, and
     * type from the owner's nodes are reused directly.
     *
     * This method is the entry point for the simulation: create agents, seed them,
     * then run reinforcement to observe how collective weights develop.
     *
     * @return int Number of nodes seeded.
     */
    public function seedFromOwner(Agent $agent, int $limit = 20): int
    {
        $ownerNodes = MemoryNode::where('user_id', $agent->owner_user_id)
            ->where('sensitivity', 'public')
            ->latest()
            ->limit($limit)
            ->get();

        // Load existing agent node content to avoid duplicates without raw SQL hashing.
        $existingContents = MemoryNode::where('user_id', $agent->graph_user_id)
            ->pluck('content')
            ->map(fn ($c) => hash('sha256', $c))
            ->flip()
            ->all();

        $seeded = 0;
        foreach ($ownerNodes as $ownerNode) {
            $hash = hash('sha256', $ownerNode->content);

            if (isset($existingContents[$hash])) {
                continue;
            }

            MemoryNode::create([
                'user_id' => $agent->graph_user_id,
                'type' => $ownerNode->type,
                'sensitivity' => 'public',
                'label' => $ownerNode->label,
                'content' => $ownerNode->content,
                'tags' => $ownerNode->tags,
                'confidence' => $ownerNode->confidence,
                'source' => 'seeded',
            ]);

            $seeded++;
        }

        return $seeded;
    }

    /**
     * Return all shared edges between agents under the given owner, formatted for
     * the simulation UI. Includes agent names and collective weight for each edge.
     *
     * @return array<int, array{content_hash: string, agent_a: string, agent_b: string, weight: float, access_count: int}>
     */
    public function getSharedEdgeSummary(string $ownerUserId): array
    {
        $edges = SharedMemoryEdge::where('owner_user_id', $ownerUserId)
            ->with(['agentA', 'agentB', 'nodeA'])
            ->orderByDesc('weight')
            ->get();

        return $edges->map(fn ($e) => [
            'id' => $e->id,
            'content_hash' => $e->content_hash,
            'content_preview' => mb_substr($e->nodeA?->content ?? '', 0, 100),
            'agent_a' => $e->agentA?->name ?? 'unknown',
            'agent_b' => $e->agentB?->name ?? 'unknown',
            'weight' => round($e->weight, 3),
            'access_count' => $e->access_count,
            'last_accessed_at' => $e->last_accessed_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Delete an agent and all graph data stored in its partition.
     *
     * Agent partitions are keyed by graph_user_id in memory_nodes and memory_edges,
     * so this cleanup must happen explicitly before removing the agent record.
     */
    public function deleteAgent(Agent $agent): void
    {
        DB::transaction(function () use ($agent) {
            MemoryNode::where('user_id', $agent->graph_user_id)->delete();
            $agent->delete();
        });
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function updateSharedEdge(
        string $ownerUserId,
        Agent $agentA,
        Agent $agentB,
        MemoryNode $nodeA,
        MemoryNode $nodeB,
        string $contentHash,
        float $trustAlpha,
    ): void {
        // Canonical ordering by UUID prevents duplicate edges in both directions.
        if ($agentA->id > $agentB->id) {
            [$agentA, $agentB] = [$agentB, $agentA];
            [$nodeA, $nodeB] = [$nodeB, $nodeA];
        }

        $edge = SharedMemoryEdge::where('owner_user_id', $ownerUserId)
            ->where('agent_a_id', $agentA->id)
            ->where('agent_b_id', $agentB->id)
            ->where('content_hash', $contentHash)
            ->first();

        if (! $edge) {
            SharedMemoryEdge::create([
                'owner_user_id' => $ownerUserId,
                'agent_a_id' => $agentA->id,
                'agent_b_id' => $agentB->id,
                'node_a_id' => $nodeA->id,
                'node_b_id' => $nodeB->id,
                'content_hash' => $contentHash,
                'weight' => min(1.0, self::SHARED_WEIGHT_INITIAL + $trustAlpha),
                'access_count' => 1,
                'last_accessed_at' => Carbon::now(),
            ]);
        } else {
            $edge->weight = min(1.0, $edge->weight + $trustAlpha);
            $edge->access_count = $edge->access_count + 1;
            $edge->last_accessed_at = Carbon::now();
            $edge->save();
        }
    }
}
