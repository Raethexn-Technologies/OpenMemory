<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\GraphSnapshot;
use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Models\SharedMemoryEdge;
use App\Services\ClusterDetectionService;
use App\Services\MemoryGraphService;
use App\Services\MultiAgentGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Simulates a realistic 8-hour workday of memory activity for demo purposes.
 *
 * This command bypasses the LLM extraction pipeline and inserts memory nodes
 * directly using pre-written content that covers realistic work, technical,
 * personal, and conceptual categories. It then runs the full Physarum dynamics
 * pipeline — reinforcement, agent seeding, collective simulation, and snapshot
 * capture — so the /graph, /agents, and /3d surfaces have meaningful data to
 * render immediately after setup.
 *
 * Usage:
 *   php artisan simulate:day
 *   php artisan simulate:day --fresh          (wipes existing demo data first)
 *   php artisan simulate:day --memories=60   (more nodes for a denser graph)
 */
class SimulateDay extends Command
{
    private const MAX_SNAPSHOTS_PER_USER = 96;

    protected $signature = 'simulate:day
        {--user= : User ID to simulate for (defaults to the fixed demo UUID)}
        {--fresh : Delete existing user data before seeding}
        {--memories=40 : Number of memory nodes to create (20-80 recommended)}';

    protected $description = 'Seed a realistic 8-hour workday of memory activity for demo and testing';

    // Fixed demo user UUID. Stable across runs so the same URL shows updated data.
    const DEMO_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function handle(
        MemoryGraphService $graphService,
        MultiAgentGraphService $multiAgentService,
        ClusterDetectionService $clusterService,
    ): int {
        $userId = $this->option('user') ?? self::DEMO_USER_ID;
        $targetMemories = max(20, min(80, (int) $this->option('memories')));

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>OpenMemory — Daily Simulation</>');
        $this->line('  Simulating ' . $targetMemories . ' memories across an 8-hour workday.');
        $this->line('  Demo user: <fg=gray>' . $userId . '</>');
        $this->newLine();

        if ($this->option('fresh')) {
            $this->wipeDemoData($userId);
        }

        // ── Phase 1: Memory nodes ─────────────────────────────────────────────

        $this->line('  <fg=yellow>[1/5]</> Creating memory nodes...');
        $nodes = $this->createMemoryNodes($graphService, $userId, $targetMemories);
        $edgeCount = MemoryEdge::where('user_id', $userId)->count();
        $this->line("        <fg=green>✓</> {$nodes->count()} nodes, {$edgeCount} edges auto-wired");

        // ── Phase 2: Physarum reinforcement ───────────────────────────────────

        $this->line('  <fg=yellow>[2/5]</> Running Physarum reinforcement turns...');
        $reinforcedEdges = $this->runReinforcementTurns($graphService, $nodes, $userId);
        $this->line("        <fg=green>✓</> {$reinforcedEdges} edge reinforcements across 6 simulated turns");

        // ── Phase 3: Agents ───────────────────────────────────────────────────

        $this->line('  <fg=yellow>[3/5]</> Creating agents...');
        [$nexus, $beacon, $ghost] = $this->createAgents($userId);
        $this->line("        <fg=green>✓</> Nexus (trust=0.90), Beacon (trust=0.75), Ghost (trust=0.25)");

        // ── Phase 4: Seed agent partitions ────────────────────────────────────

        $this->line('  <fg=yellow>[4/5]</> Seeding agent partitions from owner nodes...');
        $nexusSeeded  = $multiAgentService->seedFromOwner($nexus,  20);
        $beaconSeeded = $multiAgentService->seedFromOwner($beacon, 15);
        $ghostSeeded  = $multiAgentService->seedFromOwner($ghost,  10);

        // Run collective reinforcement so shared edges form between agents.
        $nexusNodes  = MemoryNode::where('user_id', $nexus->graph_user_id)->pluck('id')->all();
        $beaconNodes = MemoryNode::where('user_id', $beacon->graph_user_id)->pluck('id')->all();
        $ghostNodes  = MemoryNode::where('user_id', $ghost->graph_user_id)->pluck('id')->all();

        if (! empty($nexusNodes))  $multiAgentService->reinforceShared($nexusNodes, $nexus);
        if (! empty($beaconNodes)) $multiAgentService->reinforceShared($beaconNodes, $beacon);
        if (! empty($ghostNodes))  $multiAgentService->reinforceShared($ghostNodes, $ghost);

        $sharedEdgeCount = SharedMemoryEdge::where('owner_user_id', $userId)->count();
        $this->line("        <fg=green>✓</> Nexus: {$nexusSeeded} nodes, Beacon: {$beaconSeeded} nodes, Ghost: {$ghostSeeded} nodes");
        $this->line("        <fg=green>✓</> {$sharedEdgeCount} cross-agent shared edges formed");

        // ── Phase 5: Graph snapshot ───────────────────────────────────────────

        $this->line('  <fg=yellow>[5/5]</> Taking cluster snapshot...');
        $clusters = $clusterService->detect($userId);
        GraphSnapshot::create([
            'user_id'     => $userId,
            'snapshot_at' => Carbon::now(),
            'payload'     => ['clusters' => $clusters],
        ]);
        $this->pruneSnapshots($userId);
        $this->line("        <fg=green>✓</> " . count($clusters) . " clusters detected and stored");

        // ── Summary ───────────────────────────────────────────────────────────

        $this->newLine();
        $this->line('  <fg=green;options=bold>Simulation complete.</> Navigate to any surface:');
        $this->newLine();
        $this->line('    <fg=cyan>/chat</>    — chat with ' . $nodes->count() . ' memories active in context');
        $this->line('    <fg=cyan>/memory</>  — inspect all memory records');
        $this->line('    <fg=cyan>/graph</>   — explore the memory graph (' . $nodes->count() . ' nodes, ' . $edgeCount . ' edges)');
        $this->line('    <fg=cyan>/agents</>  — manage Nexus, Beacon, and Ghost');
        $this->line('    <fg=cyan>/3d</>      — mission control surface with ' . count($clusters) . ' clusters');
        $this->newLine();

        return Command::SUCCESS;
    }

    // ── Memory content ────────────────────────────────────────────────────────

    /**
     * Create memory nodes covering four realistic topic clusters:
     * technical decisions, Atlas project planning, research concepts, and
     * personal workflow preferences. Tag overlap auto-wires edges between
     * related nodes via MemoryGraphService::storeNode().
     *
     * The $targetMemories parameter selects a proportional subset of the full
     * content catalog so the graph scales gracefully at different densities.
     */
    private function createMemoryNodes(
        MemoryGraphService $graphService,
        string $userId,
        int $targetMemories,
    ) {
        $catalog = $this->memoryCatalog();
        $selected = collect($catalog)->shuffle()->take($targetMemories);

        $nodes = collect();
        foreach ($selected as $memory) {
            $node = $graphService->storeNode($userId, $memory['content'], [
                'type'        => $memory['type'],
                'sensitivity' => $memory['sensitivity'],
                'label'       => $memory['label'],
                'tags'        => $memory['tags'],
                'people'      => $memory['people'],
                'projects'    => $memory['projects'],
            ]);
            $nodes->push($node);
        }

        return $nodes;
    }

    /**
     * Simulate six realistic LLM context window events by calling
     * MemoryGraphService::reinforce() on topically related node groups.
     *
     * Each turn corresponds to a different type of work session: a coding
     * session reinforces technical nodes together, a sprint planning session
     * reinforces Atlas project nodes together, and so on. This builds up
     * Physarum edge weights that reflect genuine co-activation patterns
     * rather than uniform weight across all edges.
     *
     * Returns the total number of edge weight increments applied.
     */
    private function runReinforcementTurns(
        MemoryGraphService $graphService,
        $nodes,
        string $userId,
    ): int {
        $byTag = fn(string $tag) => $nodes
            ->filter(fn($n) => in_array($tag, $n->tags ?? []))
            ->pluck('id')
            ->all();

        $byType = fn(string $type) => $nodes
            ->filter(fn($n) => $n->type === $type)
            ->pluck('id')
            ->all();

        // Six turns simulating different work contexts across the day.
        $turns = [
            $byTag('architecture'),           // morning architecture review
            $byTag('atlas'),                  // sprint planning session
            array_merge($byTag('database'), $byTag('performance')),  // performance investigation
            $byTag('research'),               // reading / concept exploration
            array_merge($byType('person'), $byTag('atlas')),         // team sync
            array_merge($byTag('workflow'), $byType('task')),        // end-of-day review
        ];

        $edgeBefore = MemoryEdge::where('user_id', $userId)->sum('access_count');

        foreach ($turns as $nodeIds) {
            if (count($nodeIds) >= 2) {
                $graphService->reinforce($nodeIds, $userId);
            }
        }

        $edgeAfter = MemoryEdge::where('user_id', $userId)->sum('access_count');

        return (int) ($edgeAfter - $edgeBefore);
    }

    /**
     * Create three agents with distinct trust scores.
     *
     * Nexus is the high-trust technical specialist. Beacon is a mid-trust
     * generalist with project management focus. Ghost is a newly registered
     * low-trust agent, demonstrating the MemoryGraft resistance property:
     * its contributions to shared edges are attenuated by its trust score.
     */
    private function createAgents(string $userId): array
    {
        $agentDefs = [
            ['name' => 'Nexus',  'trust_score' => 0.90],
            ['name' => 'Beacon', 'trust_score' => 0.75],
            ['name' => 'Ghost',  'trust_score' => 0.25],
        ];

        $agents = [];
        foreach ($agentDefs as $def) {
            // Skip if an agent with this name already exists for this owner.
            $existing = Agent::where('owner_user_id', $userId)
                ->where('name', $def['name'])
                ->first();

            if ($existing) {
                $agents[] = $existing;
                continue;
            }

            $agents[] = Agent::create([
                'owner_user_id' => $userId,
                'graph_user_id' => Str::uuid()->toString(),
                'name'          => $def['name'],
                'trust_score'   => $def['trust_score'],
            ]);
        }

        return $agents;
    }

    /**
     * Delete all memory nodes, edges, agents, shared edges, and snapshots
     * belonging to the demo user. Preserves all other users' data.
     */
    private function wipeDemoData(string $userId): void
    {
        $this->line('  <fg=red>--fresh:</> Wiping existing demo data...');

        $agents = Agent::where('owner_user_id', $userId)->get();
        foreach ($agents as $agent) {
            MemoryNode::where('user_id', $agent->graph_user_id)->delete();
        }

        SharedMemoryEdge::where('owner_user_id', $userId)->delete();
        Agent::where('owner_user_id', $userId)->delete();
        GraphSnapshot::where('user_id', $userId)->delete();
        MemoryNode::where('user_id', $userId)->delete();

        $this->line('        <fg=green>✓</> Demo data cleared');
        $this->newLine();
    }

    private function pruneSnapshots(string $userId): void
    {
        $oldest = GraphSnapshot::where('user_id', $userId)
            ->orderByDesc('snapshot_at')
            ->skip(self::MAX_SNAPSHOTS_PER_USER)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($oldest->isNotEmpty()) {
            GraphSnapshot::whereIn('id', $oldest)->delete();
        }
    }

    // ── Memory catalog ────────────────────────────────────────────────────────

    /**
     * A catalog of 60 realistic work-day memories across four topic clusters.
     * The simulation selects a proportional subset based on --memories.
     *
     * Tags are chosen so related memories form edges via the tag-overlap
     * auto-wiring in MemoryGraphService::wireTagEdges(). The four clusters
     * (technical, atlas-project, research-concepts, workflow) are distinct
     * enough to produce visible community structure in the graph.
     */
    private function memoryCatalog(): array
    {
        return [

            // ── Technical / architecture cluster ─────────────────────────────

            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Chose PostgreSQL JSON operators over application-level parsing',
                'content' => 'After benchmarking, PostgreSQL jsonb operators handle tag intersection 4x faster than pulling records into PHP and filtering in memory. Switched the graph edge query to use a jsonb containment operator.',
                'tags' => ['database', 'performance', 'architecture', 'postgresql'],
                'people' => [], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Chose argon2id over bcrypt for password hashing',
                'content' => 'bcrypt is time-tested but memory-hard algorithms like argon2id are more resistant to GPU-accelerated cracking. Laravel supports argon2id natively. Switched the hashing driver in config/hashing.php.',
                'tags' => ['security', 'architecture', 'authentication'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Rejected GraphQL in favour of REST for the memory API',
                'content' => 'GraphQL would allow flexible querying of the graph but adds resolver complexity and requires a schema layer the team is not familiar with. The graph endpoints are narrow enough that REST routes are cleaner.',
                'tags' => ['architecture', 'api', 'rest'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'N+1 query pattern found in Bob\'s pull request',
                'content' => 'PR #142 loads agent records inside a foreach loop over shared edges. Each iteration triggers a separate SELECT. Flagged in review: needs eager loading with ->with([\'agentA\', \'agentB\']) before the loop.',
                'tags' => ['database', 'performance', 'code-review'],
                'people' => ['Bob'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Redis chosen for session storage',
                'content' => 'Session data needs sub-millisecond reads on every request. Redis handles this with predictable latency. PostgreSQL session storage was considered but ruled out to avoid adding read load to the primary database.',
                'tags' => ['architecture', 'performance', 'database'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'TypeScript strict mode enabled across the frontend',
                'content' => 'Turned on strict: true in tsconfig.json after the post-lunch refactor session. Caught three implicit any errors in the graph data transform layer. The extra verbosity is worth the correctness guarantee.',
                'tags' => ['frontend', 'typescript', 'architecture'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Laravel queues chosen for async memory processing',
                'content' => 'Graph extraction and ICP writes happen after the LLM response returns. Making these synchronous would add 800-1200ms to every chat turn. Laravel queues dispatch these as background jobs with no user-facing latency.',
                'tags' => ['architecture', 'performance', 'backend'],
                'people' => [], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Memory leak found in WebSocket handler',
                'content' => 'The WebSocket connection handler was not cleaning up event listeners on disconnect. After 200 connections, memory usage was 40% higher than expected. Fixed by calling removeAllListeners() in the close handler.',
                'tags' => ['backend', 'performance', 'debugging'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Cache invalidation bug resolved',
                'content' => 'The cache key for public memory records was not including the user principal, so two users with overlapping session timing were occasionally seeing each other\'s cached memory lists. Fixed by including the principal hash in the cache key.',
                'tags' => ['database', 'debugging', 'security'],
                'people' => ['Bob'], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Event sourcing considered and rejected for the graph layer',
                'content' => 'Event sourcing would give a full history of every edge weight change but requires replay infrastructure and schema discipline the team cannot currently maintain. Snapshot-based history via graph_snapshots table achieves the same observability goal with less complexity.',
                'tags' => ['architecture', 'database'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],

            // ── Atlas project cluster ─────────────────────────────────────────

            [
                'type' => 'project', 'sensitivity' => 'public',
                'label' => 'Atlas milestone 3 deadline is six weeks away',
                'content' => 'Milestone 3 covers the multi-agent simulation UI and the cluster snapshot history. Six weeks means the Three.js surface and the temporal axis scrubber both need to land in the next sprint. Alice confirmed the deadline in the standup.',
                'tags' => ['atlas', 'sprint', 'milestone', 'planning'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'task', 'sensitivity' => 'public',
                'label' => 'Write unit tests for the graph service before end of day',
                'content' => 'The Physarum reinforcement math has no coverage yet. Need tests that verify the ALPHA increment, the RHO decay, and the floor clamp. Agreed with Alice this would be done today.',
                'tags' => ['atlas', 'testing', 'planning'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'task', 'sensitivity' => 'public',
                'label' => 'Review PR #142 from Alice',
                'content' => 'Alice\'s PR adds the alignment Jaccard calculation to the agents endpoint. The content hash comparison approach replaces the earlier UUID comparison that produced near-zero overlap. Review scheduled for this afternoon.',
                'tags' => ['atlas', 'code-review'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'task', 'sensitivity' => 'public',
                'label' => 'Document the multi-agent architecture before the retrospective',
                'content' => 'The VISION.md section on collective Physarum needs to be in place before Thursday\'s retrospective. Dr. Chen will be attending and wants the research framing written up, not just the code.',
                'tags' => ['atlas', 'documentation', 'planning'],
                'people' => ['Dr. Chen'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'event', 'sensitivity' => 'public',
                'label' => 'Sprint retrospective scheduled for Thursday',
                'content' => 'The Atlas sprint retrospective is Thursday at 2pm. Agenda: what slowed us down in the last sprint, whether the Physarum dynamics are producing the expected graph structure, and the roadmap for the society experiment.',
                'tags' => ['atlas', 'sprint', 'planning'],
                'people' => ['Alice', 'Bob', 'Sarah'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Three tasks assigned to Bob in sprint planning',
                'content' => 'Bob is taking the shared edge summary endpoint, the agent graph partition fetch, and the CSRF header fix in ThreeD.vue. All three are blockers for the 3D surface to show realistic data.',
                'tags' => ['atlas', 'sprint', 'planning'],
                'people' => ['Bob'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Sarah joined as UX designer for Atlas',
                'content' => 'Sarah is the new UX designer joining the Atlas project. Her first task is reviewing the Three.js mission control surface for operational legibility — specifically whether the cluster heat colors communicate urgency clearly.',
                'tags' => ['atlas', 'team'],
                'people' => ['Sarah'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'project', 'sensitivity' => 'public',
                'label' => 'Lighthouse prototype due next Friday',
                'content' => 'The Lighthouse prototype is a standalone demo of the ICP ownership layer without the graph dynamics. It needs to show browser-signed writes and canister-level access enforcement. Due Friday before the investor demo.',
                'tags' => ['lighthouse', 'milestone', 'planning'],
                'people' => [], 'projects' => ['Lighthouse'],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'public',
                'label' => 'Alice wants a weekly sync on Atlas project progress',
                'content' => 'Alice proposed a standing 30-minute weekly sync every Monday morning to review the research agenda tracks and update RESEARCH.md. First sync is next Monday at 9am.',
                'tags' => ['atlas', 'planning', 'team'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'task', 'sensitivity' => 'public',
                'label' => 'Authentication layer refactor agreed in standup',
                'content' => 'The current authentication layer mixes session handling with identity management in a way that will break when Internet Identity is added. The refactor separates the two concerns before the ICP live mode changes.',
                'tags' => ['atlas', 'architecture', 'authentication'],
                'people' => [], 'projects' => ['Atlas'],
            ],

            // ── Research / concepts cluster ───────────────────────────────────

            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Tero et al. (2010): Physarum polycephalum finds optimal transport networks',
                'content' => 'The 2010 Science paper by Tero et al. showed that slime mold, when placed at food sources matching Tokyo train stations, grows a transport network closely matching the actual Tokyo rail network. The organism minimizes path length and fault tolerance simultaneously using a conductance feedback loop. This is the mathematical model underlying the memory graph edge weights.',
                'tags' => ['research', 'physarum', 'biology', 'mathematics'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'CAP theorem: ICP is a CP system',
                'content' => 'Under the CAP theorem, distributed systems can guarantee at most two of: Consistency, Availability, Partition tolerance. ICP chooses CP: update calls go through consensus (strong consistency) but may be unavailable during network partitions. This is why the graph dynamics live in PostgreSQL (fast, slightly inconsistent) while ownership proofs live on ICP (slow, strongly consistent).',
                'tags' => ['research', 'distributed-systems', 'mathematics'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Hebbian learning: neurons that fire together wire together',
                'content' => 'Donald Hebb\'s 1949 postulate states that when two neurons activate simultaneously, the synaptic connection between them strengthens. The memory graph implements this at the edge level: when two memory nodes are loaded into the same LLM context window, the edge weight between them increments by ALPHA=0.10. Co-activation is the signal; weight accumulation is the result.',
                'tags' => ['research', 'neuroscience', 'mathematics', 'physarum'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Scale-free networks: most real networks follow a power-law degree distribution',
                'content' => 'Barabasi and Albert (1999) showed that networks grown by preferential attachment produce a degree distribution following a power law P(k) ~ k^(-gamma). A few nodes accumulate most connections (hubs); most nodes have few connections. This structure appears in the WWW, citation networks, protein interaction networks, and the human connectome. If Physarum dynamics on the memory graph also produce a power-law distribution, the memory graph belongs to the same topological class.',
                'tags' => ['research', 'mathematics', 'distributed-systems'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Raghavan et al. (2007): near-linear time community detection via label propagation',
                'content' => 'Label propagation assigns each node its own label, then iteratively updates each node to adopt the most common label among its weighted neighbours. The algorithm converges when no node changes label. Raghavan et al. showed this runs in near-linear time and produces community partitions comparable in quality to more expensive methods. The cluster detection in this project uses weighted label propagation with deterministic tie-breaking.',
                'tags' => ['research', 'mathematics', 'clustering'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Zero-knowledge proofs and the zkTAM framework',
                'content' => 'Kinic\'s zkTAM (Trustless Agentic Memory) framework applies zero-knowledge proofs to prove that an LLM response was conditioned on specific verified memory records. The precondition is active_node_ids: the exact node IDs loaded into context per turn. The proof shows the response could only have been generated given those specific inputs, without revealing the inputs themselves.',
                'tags' => ['research', 'cryptography', 'distributed-systems'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Stigmergy: coordination through shared environment traces',
                'content' => 'Stigmergy is indirect coordination through modification of a shared environment. Ant colonies use pheromone gradients: individual ants follow and reinforce successful trails without communicating directly. The Physarum shared edge layer is stigmergic: agents do not communicate with each other, but their independent reinforcement of shared memory content produces a collective weight structure that reflects group relevance.',
                'tags' => ['research', 'emergence', 'biology', 'physarum'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'concept', 'sensitivity' => 'public',
                'label' => 'Small-world networks: high clustering with short path lengths',
                'content' => 'Watts and Strogatz (1998) defined small-world networks as graphs with clustering coefficients much higher than a random graph of the same size, but average path lengths comparable to the random baseline. The human brain, the C. elegans connectome, and the power grid all exhibit small-world structure. Small-world topology allows local specialisation (high clustering within clusters) while maintaining global integration (short paths between any two nodes).',
                'tags' => ['research', 'mathematics', 'neuroscience'],
                'people' => [], 'projects' => [],
            ],

            // ── Personal / workflow cluster ───────────────────────────────────

            [
                'type' => 'memory', 'sensitivity' => 'private',
                'label' => 'Prefers dark mode terminal with vim keybindings',
                'content' => 'Uses Ghostty terminal with a dark Catppuccin theme and vim mode enabled. The muscle memory for hjkl navigation is strong enough that switching to arrow keys in any context causes friction.',
                'tags' => ['workflow', 'tools', 'preference'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'private',
                'label' => 'Standups should stay under 15 minutes',
                'content' => 'Standups that run over 15 minutes consistently lose the thread. The format that works: what did yesterday produce, what is today\'s one thing, and what is blocked. No design discussions in standup.',
                'tags' => ['workflow', 'preference', 'team'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'private',
                'label' => 'Uses Pomodoro technique: 25-minute work blocks',
                'content' => 'The 25-minute focused work block followed by a 5-minute break matches the attention curve well for deep technical work. After four blocks, a longer break. The timer is a physical one, not a phone app, to avoid notification interruptions.',
                'tags' => ['workflow', 'productivity', 'preference'],
                'people' => [], 'projects' => [],
            ],
            [
                'type' => 'memory', 'sensitivity' => 'private',
                'label' => 'Dr. Chen suggests rate limiting before load testing',
                'content' => 'Dr. Chen\'s advice: implement rate limiting on the chat and memory endpoints before any load testing. Without it, a misbehaving client can saturate the LLM API budget in minutes. The limit should be per-principal, not per-IP.',
                'tags' => ['workflow', 'security', 'planning'],
                'people' => ['Dr. Chen'], 'projects' => [],
            ],
            [
                'type' => 'task', 'sensitivity' => 'public',
                'label' => 'API endpoint documentation finished',
                'content' => 'Completed the OpenAPI spec for all graph and agent endpoints. Included example request and response payloads for the /api/agents/alignment endpoint since the Jaccard output format was not obvious from the route signature alone.',
                'tags' => ['documentation', 'api', 'workflow'],
                'people' => [], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'person', 'sensitivity' => 'public',
                'label' => 'Alice is the project lead for Atlas',
                'content' => 'Alice owns the Atlas project roadmap and final call on architectural decisions. She prefers async communication over syncs when possible and wants all significant decisions recorded in DEVLOG before they are implemented.',
                'tags' => ['team', 'atlas', 'workflow'],
                'people' => ['Alice'], 'projects' => ['Atlas'],
            ],
            [
                'type' => 'person', 'sensitivity' => 'public',
                'label' => 'Bob specialises in database performance',
                'content' => 'Bob\'s background is database engineering. He is the first person to loop in when query performance is a concern. He runs the weekly database office hours on Wednesdays at 4pm.',
                'tags' => ['team', 'database', 'performance'],
                'people' => ['Bob'], 'projects' => [],
            ],
            [
                'type' => 'person', 'sensitivity' => 'public',
                'label' => 'Dr. Chen is the research advisor for the Physarum work',
                'content' => 'Dr. Chen supervises the academic side of the OpenMemory research. She has a background in complex systems and network science. Her main concern is that the Physarum dynamics are grounded in the original Tero et al. formulation, not a loose metaphor.',
                'tags' => ['team', 'research', 'physarum'],
                'people' => ['Dr. Chen'], 'projects' => [],
            ],
        ];
    }
}
