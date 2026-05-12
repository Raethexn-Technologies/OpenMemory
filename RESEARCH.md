# OpenMemory: Research Agenda

This document is the active research agenda for OpenMemory. It sits between VISION.md and DEVLOG.md in purpose: VISION.md holds stable design positions, DEVLOG.md holds timestamped discovery records, and this file holds the open questions and the work required to answer them.

The agenda is not a product backlog. Each track corresponds to a scientific claim that is either unproven, partially proven, or disproven by the current implementation. When a track closes, the finding graduates into VISION.md and a DEVLOG entry records what was learned. New tracks open when the work reveals questions that were not visible before it started.

---

## How the project builds itself

The project is discovery-driven. Each visualization gap reveals a research question. Each research question requires a measurement. Each measurement requires an endpoint or command. Each endpoint enables a new visualization that exposes the next gap. The cycle is self-generating, and the to-do list is a research agenda that rewrites itself as the work progresses.

This is not an accident of process. It is a consequence of building observability infrastructure for a system whose behavior is not fully understood. You cannot specify in advance what you need to see, because you do not yet know what the system is doing. You build the first instrument, look at what it shows, and the next question becomes visible.

---

## Track 1: Prove the topological claim

### What opened this track

The observation that cosmic structure, biological neural networks, and Physarum polycephalum networks all belong to the same mathematical class: scale-free networks with small-world topology. The scientific question is whether a memory graph organized by Physarum dynamics converges on the same topological class as the biological systems that inspired the model.

If yes, this is a three-way convergence across physics, biology, and AI memory: cosmic structure, neural connectivity, and AI memory graphs all exhibiting the same degree distribution, clustering coefficient, and path length properties. That convergence is a publishable finding, not just a metaphor.

### What needs to be built

**Degree distribution endpoint.** `GET /api/graph/topology` computes the degree distribution of the current user's memory graph and fits a power law using ordinary least squares regression on log(P(k)) vs log(k) for k >= 1. The endpoint returns the fitted exponent gamma, the R-squared of the fit, a boolean `is_scale_free` flag (true when gamma is in [2,3] and R-squared is at or above 0.80), and the mean local clustering coefficient computed by the Watts and Strogatz (1998) formula. This endpoint is implemented in `GraphController::topology()`. Run it after `php artisan simulate:day` to obtain an initial measurement. The fit quality determines whether the graph is scale-free. Run this against individual agent partitions and the collective graph to compare.

**Small-world metrics endpoint.** The same endpoint or a companion computes mean clustering coefficient and mean shortest path length, then compares both against a random Erdos-Renyi graph of the same node count and edge density. A graph is small-world if clustering is significantly higher and path length is comparable to the random baseline.

**Multi-scale comparison.** Run both metrics against individual agent graphs and the collective graph separately. The question is whether the collective graph belongs to the same topological class as individual graphs at a larger scale, or whether the shared edge layer produces a structurally different object.

**Topology panel in Three.js.** A metrics overlay in the mission control surface showing degree distribution, clustering coefficient, path length, and power law fit quality as live readouts, updated on each simulation tick and scrubbable through the snapshot history.

### What this track proves when closed

That AI memory organized by Physarum dynamics self-organizes into the same topological class as neural networks and cosmic structure, or that it does not. Either result is a finding. If it does, the galaxy visualization is scientifically accurate, not decorative. If it does not, the divergence tells you something about how Physarum dynamics on discrete memory graphs differs from the continuous physical systems they are modeled on.

**Status: endpoint implemented, measurement pending.** `GET /api/graph/topology` is live. The scale-free claim moves from asserted to measurable by running the endpoint against a populated graph. The finding should be recorded as a DEVLOG entry and this status updated accordingly.

---

## Track 2: Galaxy-accurate visualization

### What opened this track

The current Three.js surface renders clusters as heat spheres and shared edges as violet lines between partitions. It does not render the internal structure of a single agent's memory partition in a way that makes the hub-and-filament topology visible. The galaxy metaphor is scientifically precise enough that the visualization should reflect the actual structure rather than a simplified proxy for it.

### What needs to be built

**Hub node rendering.** Within a single agent partition, node radius and brightness should scale with total connected edge weight, not just degree count. High-weight hub nodes are the stars. Low-weight peripheral nodes are the dust.

**Filament rendering.** High-weight Physarum paths between hubs should be rendered as visible, thick connections. Low-weight connections should be rendered as faint, near-invisible threads. The visual contrast between a well-traveled path and a decaying one should be immediately legible without reading a weight value.

**Interstellar medium.** Connections at or near the weight floor (0.05) should render differently from connections that have accumulated meaningful weight. One approach: floor-weight edges rendered as a diffuse haze around hub nodes rather than as explicit lines, reflecting that they exist but carry no useful flux.

**Galaxy cluster rendering.** At the multi-agent level, each agent partition should read as a distinct galaxy: a self-contained structure with internal hub-and-filament layout, separated from other partitions by visible space. Shared edges are the intracluster medium connecting galaxies, rendered with thickness proportional to accumulated shared weight.

**LLM harness layer.** A separate visual layer above the graph layer representing the context assembly, retrieval, and prompt engineering that shapes which memory paths get reinforced. This is the cosmic web within which the galaxy clusters exist. It does not need to show internal LLM mechanics; it needs to show which retrieval parameters are active, which model is in use, and what the current context window limit is. These are the gravitational parameters of the harness.

### What this track proves when closed

That the mission control surface renders the actual topological structure of the memory graph accurately enough that an operator can read cluster density, hub centrality, and filament strength from the visual without consulting a data panel. The test is whether a researcher unfamiliar with the system can correctly identify the highest-weight hub nodes and the strongest inter-hub paths from the visualization alone.

**Status: open**

---

## Track 3: Society emergence and specialization

### What opened this track

The observation that agents with different conversation histories develop divergent Physarum weight distributions through differential reinforcement alone, without any assignment or coordination. This is specialization in the complexity science sense. The current alignment panel shows a point-in-time Jaccard similarity score. It does not show the trend, so you cannot see specialization happening.

### What needs to be built

**Alignment trend view.** Plot Jaccard similarity per agent pair across the snapshot history as a time series. A falling trend means the pair is specializing away from each other. A stable high score means they are working the same knowledge domain. A sudden drop after stable alignment is an anomaly worth investigating.

**Specialization detection.** A background process that flags agent pairs whose alignment trend has been falling for more than a configurable number of consecutive snapshots. Surface this as a notification or visual indicator in the Three.js scene.

**Domain ownership panel.** For each agent, show which cluster regions it primarily activates versus which it shares with other agents. Agents with high concentration in one cluster are specialists. Agents spread across many clusters are generalists. Agents with high betweenness centrality in the shared edge layer are connectors, the agents whose memory overlaps most with the largest number of peers.

**Role emergence in the scene.** Render specialists, generalists, and connectors with distinct visual identifiers in the galaxy cluster view. The topology should be self-evident from the scene: a specialist looks like a galaxy with one bright core; a generalist looks like a spiral with many arms; a connector's galaxy is positioned at the convergence of multiple filaments.

### What this track proves when closed

That specialization emerges from differential reinforcement without any assigned roles, and that it is detectable and visually legible from the mission control surface. The scientific claim is that the society structure (who knows what, who connects whom) is a property of the collective Physarum dynamics, not of any design decision made when creating the agents.

**Status: open**

---

## Track 4: Emergent collective memory experiment

### What opened this track

The core unproven claim of the multi-agent layer: that the collective Physarum graph encodes knowledge at the group level that no individual agent holds. This is emergent collective memory in the strict sense. It is currently a hypothesis supported by the architecture but not yet tested by a controlled experiment.

### What needs to be built

**Domain seeding tool.** A seeding interface that seeds agents with genuinely non-overlapping subsets of a structured knowledge domain, rather than random samples of the owner's public nodes. The controlled starting condition is essential: if agents begin with overlapping content, the experiment cannot isolate emergent paths from individually-established ones.

**Emergence detection.** After a run of simulation ticks, compare the collective cluster topology against the union of individual cluster topologies weighted by each agent's trust score. Identify clusters in the collective graph that are absent from every individual graph at the start of the run and appear during it.

**Emergence timeline.** For each emergent cluster, trace which agents contributed the cross-reinforcement events that first established it, and at which simulation tick. This is the moment of emergence: a path that neither agent had established alone but that the collective reinforcement created. The temporal axis scrubber makes this traceable in the Three.js surface.

**Experiment report endpoint.** `GET /api/agents/emergence-report` returns the list of emergent clusters, the contributing agents, the emergence timestamps, and the current weight of each emergent cluster. This is the primary scientific output of the experiment.

### What this track proves when closed

That the collective Physarum graph either does or does not produce memory structures that exist only at the group level. If emergent clusters appear, the claim is proven: the society knows something that no individual in it knows, encoded as high-weight paths through content that no agent accessed frequently enough alone to establish. If no emergent clusters appear, collective Physarum is individual Physarum with shared bookkeeping, which is still a useful operational architecture but makes a weaker scientific claim.

**Status: open**

---

## Track 5: Closing the observability gaps

### What opened this track

The five-layer analysis in DEVLOG Entry 012. Layer 1 (what entered context) is solved by `active_node_ids`. The remaining four layers are open gaps between what the system records and what an operator needs to know.

### Layer 2: Was the memory actually used?

The `active_node_ids` field identifies what entered the context window. It does not prove the model attended to those records. The zkTAM framework (Kinic, 2025) applies zero-knowledge proofs to prove a response was conditioned on specific verified records. The precondition for zkTAM is exactly what `active_node_ids` provides.

What needs to be built first: a controlled experiment using the existing infrastructure. Same conversation, same model, same turn, memory context present versus absent. Compare response quality on a held-out evaluation set. If responses with memory present score meaningfully higher, the retrieval system is doing useful work and the zkTAM investment is justified. If scores are indistinguishable, the memory graph may be adding latency without improving outputs, and the correct next step is improving retrieval relevance before adding proof infrastructure.

**Status: experiment design needed**

### Layer 3: What caused the reinforcement?

When a cluster is reinforced, the edge weight records that those nodes were co-accessed. It does not record whether they were causally relevant to the response. Physarum weights track access frequency, not influence.

This gap is not solvable with the current LLM API surface. Attention distributions over the context window are not exposed by any major provider. Logging `active_node_ids` and comparing response quality with and without those nodes (Layer 2 experiment) is the closest available proxy. Document this as a hard limit of the current observability surface.

**Status: blocked on LLM API exposure, documented as hard limit**

### Layer 4: Iteration depth

No visibility into how many internal steps, tool calls, or retries an agent took before producing a response. This requires structured execution tracing at the orchestration layer, not at the memory graph layer. It is a separate instrument that logs agent execution traces and connects them to the memory graph via the turn ID.

What needs to be built: a trace log model that records turn ID, agent ID, step count, and tool calls per turn. Connect trace records to the `active_node_ids` field so the operator can see both what was accessed and how many steps it took to arrive at the response.

**Status: open, separate from memory graph**

### Layer 5: Cross-canister memory coherence

Memories written to different ICP canisters at different times have no global index. The `supersedes` and `contradicts` edge types exist in the graph schema for within-partition contradiction tracking. Cross-canister coherence requires a different approach: an ICP canister that maintains a signed index of graph fingerprints per principal, allowing contradiction detection across partitions.

This is the longest time-scale problem on the list and the one that requires ICP infrastructure beyond what is currently deployed.

**Status: open, ICP infrastructure required**

---

## Visualization as the primary research instrument

Every track above has a visualization component. This is deliberate. The scientific claims this project is making are about emergent structure in a dynamic system. Emergent structure is not legible from a table of numbers. It requires a rendering that makes the topology visible at the right scale, with the right level of detail, at the right moment in time.

The Three.js mission control surface is not a dashboard added on top of the research. It is the primary instrument through which the research findings become observable. The galaxy visualization, the filament rendering, the alignment trend, the emergence timeline, and the topology metrics overlay are all parts of the same instrument. Building them in isolation from the scientific questions they answer produces a demo. Building them in response to specific observability gaps produces a research instrument.

The order of work follows from this: build the measurement first, then build the visualization that makes the measurement legible, then read what the visualization reveals, then open the next track.

---

## Track 6: The storage trigger

### What opened this track

The observation that storing every turn summary produces noise and that storing only what the user explicitly flags misses the most valuable memories. Neither extreme produces a useful long-term memory graph. The system needs a judgment step that runs before the summarization pipeline and decides whether a given conversation turn is worth remembering at all, and what form that memory should take.

The cognitive psychology literature on encoding and memorability provides the theoretical grounding for this judgment. Craik and Lockhart (1972) established that the durability of a memory trace is determined by the depth at which information is processed, not merely by repetition or recency (Craik, F.I.M. and Lockhart, R.S. (1972). "Levels of processing: A framework for memory research." *Journal of Verbal Learning and Verbal Behavior*, 11(6), 671-684. doi:10.1016/S0022-5371(72)80001-X). Information processed for its meaning, its relationships to prior knowledge, and its relevance to ongoing goals produces more durable traces than information processed only for surface features. The four memorability criteria in this track (novelty, significance, durability, connection richness) map to depth-of-processing dimensions: novelty reflects distinctiveness from existing traces, significance reflects elaborative encoding tied to goals, durability reflects transfer-appropriate processing, and connection richness reflects relational encoding to prior knowledge. Tulving's encoding specificity principle (Tulving, E. (1983). *Elements of Episodic Memory*. Oxford University Press) adds a further constraint: a memory is retrievable only when the retrieval context shares features with the encoding context. For an AI memory system, this means storing memories with the contextual features that will be present when they are needed, not just the content itself.

### What needs to be built

**Memorability classifier.** Before any memory is written to the graph, the LLM evaluates the candidate content against four criteria: novelty (not already well-represented in the graph), significance (the user engaged with this deeply or stated its importance), durability (likely to be relevant across future contexts), and connection richness (links meaningfully to existing high-weight nodes). The classifier returns one of three decisions: store as a new node, update an existing node with new information, or skip. This runs as a structured prompt call before the summarization step.

**Coverage check against the graph.** The novelty criterion requires a cosine similarity check against the user's existing nodes before storage. If the candidate memory has cosine similarity above a configurable threshold with an existing node, the decision should be to update that node rather than create a new one. This prevents the graph from accumulating duplicate representations of the same knowledge.

**Event-driven trigger.** Rather than firing at the end of every turn, the storage decision fires when specific conditions are met in the conversation: a decision is made, a preference is stated, a problem is solved, a significant piece of information is provided by the user, or a long-running topic concludes. These conditions are evaluated by the LLM at each turn, and the storage pipeline runs only when at least one condition is met. This reduces the storage rate dramatically while preserving high-signal memories.

**Memorability audit panel.** A view within the Memory Inspector that shows, for each stored memory, the memorability score it received at storage time and the criteria it met. This makes the storage trigger's behavior inspectable. If the classifier is storing too much noise or skipping genuinely important content, the panel makes the pattern visible so the criteria can be tuned.

**A/B comparison experiment.** Run two conversation sessions with the same content: one with the current store-everything approach and one with the storage trigger active. Compare the graph density, the ratio of high-weight to low-weight nodes after 100 turns, and the retrieval precision on a held-out evaluation set. The storage trigger is better if retrieval precision improves and graph noise decreases, with the same or higher recall on genuinely important content.

### What this track proves when closed

That a memorability classifier producing four-criteria judgments before storage results in a graph with higher mean edge weight, lower noise, and better retrieval precision than a store-everything baseline, and that the improvement is measurable on a held-out evaluation set. The secondary finding is the identified set of event-driven trigger conditions that best predict whether a conversation turn contains durable information worth keeping.

**Status: storage trigger implemented.** `MemorabilityService` runs before `MemorySummarizationService` in the chat pipeline and evaluates four criteria (novelty, significance, durability, connection richness) via a structured LLM prompt. The decision is `store_new`, `update_existing:<nodeId>`, or `skip`. The pipeline short-circuits on `skip` so no summarization or graph write occurs. Duplicate detection is LLM-based rather than cosine similarity (the more robust embedding-based approach remains open). The memorability audit panel and A/B comparison experiment are still open.

---

## Track 7: Long-term memory simulation and visualization

### What opened this track

The question of what a year of memory use looks like, and what it means. The current simulation command (`simulate:day`) produces a single day of memory activity. It is enough to populate the Three.js surface with meaningful structure. It is not enough to answer questions about what happens over months: which knowledge clusters survive decay, which hub nodes emerge from repeated reinforcement, which topic areas rise and fall with the rhythm of real work, and what the graph of a person who has been using this system for a year actually looks like.

The thirty-year question sharpens this further. If the claim is that your memory graph becomes a genuine intellectual autobiography, the research needs to show what an autobiography looks like at different time horizons. That requires a simulation that spans weeks and months, not hours.

### What needs to be built

**`simulate:year` command.** Seeds 52 weekly work episodes with realistic memory creation, Physarum reinforcement, daily decay passes, and periodic consolidation events. Each episode draws from a topic catalog that shifts over time: some topics persist across the full year (the user's core domain), some appear for several weeks and then fade (projects), and some appear briefly and decay completely (one-off problems). The command stores a snapshot at the end of each week so the temporal scrubber has 52 historical states to play back. The full run should complete in under ten seconds.

**Timeline view.** A new surface alongside the Three.js view. The horizontal axis is time (52 weeks). The vertical axis shows cluster count, total edge weight, and mean node degree as three stacked time series. Each cluster is rendered as a colored band that shows when it formed, when it peaked, and when it decayed. Persistent clusters (never decaying below a threshold) appear as solid bands across the full width. Ephemeral clusters appear as short bands that fade. The view makes the lifecycle of knowledge domains legible at a glance.

**Memory biography panel.** Displayed within the Three.js view or as a sidebar. Shows the top ten most-connected hub nodes (what the memory graph identifies as the user's core recurring interests), the oldest surviving memory (what has persisted through all decay cycles), the cluster that peaked and died most dramatically (a finished project), and the decay forecast for the next 30 days (what falls to floor soon unless revisited). This panel answers the practical question "what does my memory graph say about who I am?"

**Consolidation visualization.** When the consolidation job runs (collapsing a dense cluster of episodic nodes into a single semantic node), the Three.js surface should show the event: the cluster contracts visually into a single bright hub node as the episodic nodes fade. This is the episodic-to-semantic transition made visible. It is the mechanism by which thirty years of memory remains navigable rather than becoming an unreadable archive.

### What this track proves when closed

That the memory graph, simulated over one year of realistic use, produces a legible intellectual biography: a small number of persistent hub nodes representing durable knowledge, a larger number of ephemeral clusters tracking completed projects, and a visible decay structure that reflects the recency weighting of the Physarum model. The secondary finding is a baseline for what the graph looks like at different time horizons, which establishes expectations for real users and identifies at what point consolidation becomes necessary.

**Status: consolidation pipeline implemented.** `ConsolidationService` identifies dense episodic clusters (mean internal edge weight >= 0.30, minimum 5 unconsolidated nodes) using the existing cluster detection output, calls the LLM to produce a one-sentence semantic summary, creates a `concept` node, wires `supersedes` edges to all absorbed episodic nodes, re-wires the highest-weight external connections, and marks originals with `consolidated_at`. The `POST /api/graph/consolidate` endpoint and "Consolidate Clusters" button in the graph explorer trigger this in-browser. Node pruning (`POST /api/graph/prune`, "Prune Dormant Nodes" button) removes nodes where every edge has decayed to floor weight and the node has been idle for 90 days. The `simulate:year` command, timeline view, and consolidation visualization remain open.

---

## Track 8: The thirty-year durability horizon

### What opened this track

The question of whether a memory graph seeded today could be meaningfully accessed thirty years from now. This is not a hypothetical edge case; it is the specific claim that separates memory as infrastructure from memory as a product feature. Infrastructure that your keys control, stored on a decentralized network with no shutdown mechanism, needs to be designed for a time horizon that no current AI product is designed for.

The question has three components: technical durability (will the storage layer still be accessible), structural durability (will the graph still be navigable at that scale), and semantic durability (will the content still be interpretable).

### What needs to be built

**Cycle sustainability model.** A calculation of the ICP cycle cost to maintain a memory canister at different memory graph sizes over different time horizons. The current canister design needs a documented estimate: how many cycles does it cost to store and serve a graph of 10,000 nodes for one year, for ten years, for thirty years? This is the economic precondition for the durability claim. The answer determines whether the thirty-year claim is feasible under current ICP pricing and what the user needs to fund to achieve it.

**Canister upgrade protocol.** ICP canisters can be upgraded without losing stable memory, but the upgrade process needs to be documented and tested. A thirty-year memory graph will require multiple canister upgrades as the Motoko interface evolves. The upgrade protocol needs to guarantee that stable memory is preserved across upgrades and that the graph structure is not corrupted by schema changes. Write and test a migration script that upgrades a canister from version N to version N+1 while preserving all existing memory graph records.

**Consolidation at scale.** A graph with thirty years of memory use, even with aggressive decay, will contain hundreds of thousands of nodes. Raw node retrieval at that scale is not usable without an index. The consolidation pipeline that converts episodic clusters into semantic nodes needs to be designed as a first-class archiving process, not a cleanup job. The design question is: what is the right consolidation schedule so that the oldest memories are always in semantic form while recent memories remain episodic and inspectable?

**Legibility test at scale.** Run `simulate:year` three times in sequence (simulating three years of memory use) without running consolidation between runs. Measure retrieval precision and graph traversal time. Then run consolidation and measure again. The test establishes whether the system remains usable without consolidation at three-year scale and whether consolidation restores usability. The finding quantifies how urgently consolidation is needed at different time horizons and sets the maintenance schedule recommendation.

**Export and import protocol.** If the user wants to move their memory graph from one canister to another (changing their ICP subnet, creating a backup, or migrating to a future storage layer), the export format needs to be stable enough to reimport correctly into a fresh canister. Define the canonical export format (the memory graph serialized as a signed JSON document with all node and edge records) and build the import command. The export format is the thirty-year artifact: the thing that would allow someone to read their memory graph decades from now using tools that do not exist yet.

### What this track proves when closed

That the technical, structural, and semantic components of a thirty-year memory graph are each addressed by a concrete mechanism: cycle sustainability, canister upgrade protocol, consolidation pipeline, and export format. The track closes when a simulated three-year memory graph can be exported, re-imported into a fresh canister, and queried with retrieval precision comparable to the live canister. The finding is either that the infrastructure is genuinely durable at the thirty-year horizon or that specific bottlenecks prevent it and need different solutions.

**Status: open**

---

## Track 9: OpenMemory as a cognitive subsystem

### What opened this track

The observation that the three-component model of cognition (perception, reasoning, memory) maps directly onto the current architecture, and that OpenMemory occupies the memory position with a well-defined protocol interface (MCP) that any reasoning layer can connect to. If the memory layer is infrastructure rather than a product feature, the question becomes whether a modular cognitive architecture built on sovereign memory infrastructure performs differently from a single-model system on tasks that require long-horizon context, multi-domain synthesis, or explicit knowledge handoff between specialized components.

The cognitive architecture tradition provides the conceptual precedent. ACT-R (Anderson, J.R., Bothell, D., Byrne, M.D., Douglass, S., Lebiere, C. and Qin, Y. (2004). "An Integrated Theory of the Mind." *Psychological Review*, 111(4), 1036-1060. doi:10.1037/0033-295X.111.4.1036) formalizes memory retrieval as an activation equation where each memory chunk's base-level activation decays with time since last access and recovers with frequency of access, the same behavioral property the Physarum RHO decay and ALPHA reinforcement implement. Soar (Laird, J.E., Newell, A. and Rosenbloom, P.S. (1987). "Soar: An Architecture for General Intelligence." *Artificial Intelligence*, 33(1), 1-64. doi:10.1016/0004-3702(87)90050-6) demonstrated that separating working memory (the reasoning context) from long-term memory (persistent knowledge) with a defined retrieval interface produces a system with human-like performance across a wider range of tasks than any single-memory architecture. Global Workspace Theory (Baars, B.J. (1988). *A Cognitive Theory of Consciousness*. Cambridge University Press) provides the complementary framing: a global workspace accessible to many specialized processes, with attention as the mechanism that selects which content enters the workspace, maps onto the retrieval step that determines which memory nodes enter the LLM context window on each turn. OpenMemory does not implement any of these architectures. It builds a memory infrastructure on which they could be implemented, with the specific advantage that the memory substrate is cryptographically owned, protocol-accessible, and graph-structured with usage-derived edge weights rather than flat and vendor-controlled.

### What needs to be built

**Cognitive architecture harness.** A configuration layer that wires together multiple specialized agents (perception, reasoning, planning, consolidation) through a shared memory graph. Each agent type has a defined role and a defined MCP interface: the perception agent writes structured observations to the graph, the reasoning agent retrieves relevant context and produces responses, the planning agent maintains goal state as a typed graph node, and the consolidation agent runs the episodic-to-semantic compression on a schedule. The harness does not implement new reasoning capability; it defines the interfaces and the routing rules.

**Perception agent.** An agent that reads documents, web pages, or other structured inputs and writes extracted observations to the memory graph using the existing node type taxonomy. The perception agent does not reason about the content; it structures it. The reasoning agent retrieves what the perception agent has stored when it becomes relevant to a task. This separation means the perception and reasoning steps can use different models optimized for each role.

**Planning agent.** An agent that maintains a goal node in the memory graph. Goal state is a first-class graph node with edges to the memory content relevant to achieving it. The planning agent checks after each reasoning turn whether the current output moved toward the goal and updates the goal node accordingly. Goal progress is therefore traceable in the graph history and visible in the temporal scrubber.

**Consolidation agent.** A scheduled agent that identifies dense clusters of episodic nodes with high mutual edge weight, summarizes them into a single semantic node using an LLM call, replaces the cluster with the semantic node in the graph, and retains thin links back to the original episodic nodes for auditability. This is the hippocampal-to-cortical transfer implemented as a graph operation. The Three.js surface should visualize the consolidation event as the cluster contracting into a single bright hub node.

**Benchmark comparison.** Run a set of tasks requiring long-horizon context against two configurations: a single-model baseline with standard context window management, and the modular architecture with OpenMemory as the memory layer. Measure task completion rate, factual consistency across turns, and the number of turns before the system loses track of early context. The modular architecture is better if it maintains context coherence for longer and produces fewer factual contradictions, using the same or less compute per turn.

**Architecture diagram in the Three.js surface.** A toggle that switches the mission control view from showing memory graph topology to showing cognitive architecture topology: which agents are active, what their current roles are, which memory regions each agent has accessed recently, and the interface connections between them. This view makes the cognitive architecture legible as a running system rather than as a static diagram.

### What this track proves when closed

That a modular cognitive architecture with OpenMemory as the memory subsystem either outperforms or matches a single-model baseline on long-horizon context tasks, and that the architectural advantage (if present) scales with the duration of the task and the number of distinct knowledge domains involved. The secondary finding is a reference implementation of each agent type with defined MCP interfaces, which other projects can use as components in their own cognitive architectures.

**Status: perception agent partially instantiated.**

`DocumentIngestionService` is now a concrete implementation of the perception agent role described above. It accepts raw text or Markdown files, chunks them via `DocumentChunkerService` (~1500 chars per chunk, paragraph-boundary aware), runs `GraphExtractionService` per chunk, and stores each chunk as a typed graph node via `MemoryGraphService::storeNode()` with `source = 'document'`. A document anchor node of type `document` is created first with `source = 'document_anchor'`; all chunk nodes wire to it via `part_of` edges (weight 0.9), making the source traceable in graph traversal while keeping anchor listing distinct from chunk nodes that the LLM may also classify as `document`.

`DocumentController` exposes `POST /api/documents/ingest` (file upload or text paste) and `GET /api/documents` (list ingested document anchors). The HTTP API defaults ingested documents to `public` because `MemoryGraphService::findContextSeeds()` and `retrieveContext()` only load public nodes into the LLM context window. `private` and `sensitive` remain valid when the operator wants graph-only storage without LLM recall.

Public document ingests are mirrored into the mock ICP store through `IcpMemoryService::storeMemory()` after graph ingestion succeeds, which keeps the mock-mode MCP read path aware that a document was ingested. The live ICP path remains incomplete because uploaded documents still need browser-side signing before they can be written to the canister under the user's principal.

**Finding opened by this implementation:** The pipeline reuses `GraphExtractionService` and `MemoryGraphService` without modification, which confirms that the node and edge primitives are source-agnostic at the implementation level. Whether the Physarum dynamics treat document-sourced and chat-sourced nodes equivalently is a separate empirical question. It depends on whether the tag vocabulary produced by the LLM for document chunks overlaps with the tag vocabulary produced for chat turns on the same topics. If the two sources produce consistent tags, document nodes and chat nodes will wire together via `same_topic_as` edges and receive mutual reinforcement when co-accessed. If tag vocabularies diverge by source, edges will only form within same-source clusters and the two sources will remain topologically isolated.

**Source-agnostic wiring confirmed (2026-04-09).** The `graph:coherence-check` artisan command injected a synthetic document chunk node with the four most common tags from existing chat nodes (`artificial intelligence, software development, tools, engineering`) and confirmed that a `same_topic_as` edge formed at weight 1.0 to the chat node "Anthony builds AI tools". The wiring layer does not branch on source. Cross-source connections depend on extraction vocabulary consistency, not graph structure. See DEVLOG Entry 022.

The remaining open question is whether `GraphExtractionService` produces overlapping tags for real document content and real chat turns on the same topic. That is a vocabulary measurement, not a wiring fix. It is testable by ingesting a real document and observing the extracted tags against those already in the graph.

The final implementation added explicit `source` and `metadata` handling in `MemoryGraphService::storeNode()`, along with a dedicated `document_anchor` source for anchor nodes. Those changes make provenance explicit without changing the extraction model or the edge-wiring model that document chunks and chat memories both rely on.

**Goal-biased retrieval implemented (2026-04-09).** `MemoryGraphService::findContextSeeds()` now seeds all public `goal` nodes first, then fills the remaining seed slots from the same 60-node bounded candidate pool ranked by total connected edge weight. Goal IDs are excluded from the weighted pool to prevent duplication. Retrieval is no longer purely history-weighted. It is now goal-biased, but it is still not query-aware: the current prompt does not yet affect seed selection.

The planning agent and cognitive architecture harness described above remain open. The benchmark comparison task (modular vs. single-model baseline) requires at least the reasoning and consolidation agents to be implemented before a meaningful comparison is possible.

---

## Track 10: Value verification

### What opened this track

The prior tracks establish implementation feasibility: the graph exists, the dynamics run, the retrieval is goal-biased, and documents wire into the same graph as conversation turns. That is sufficient to claim the system works as designed. It is not sufficient to claim it works better than a simpler alternative, and that claim is the one a provider or adopter needs to see.

The central research question for this track is:

Can user-owned, provider-portable memory improve long-horizon assistant quality without requiring the model provider to own the user's memory store?

This question has three components, organized as three testable claims.

### Claim 1: Better retrieval

Graph-guided, goal-biased retrieval produces more useful context than flat recency retrieval.

The hypothesis is specific: when a user has active goals, the goal-seeded BFS retrieves memory content that is more relevant, more complete, and better aligned with current priorities than a recency-ordered slice of the same corpus.

The experiment compares three strategies against the same memory corpus and the same question set:
- recency: most recently created public nodes, no graph traversal
- graph: BFS from weight-ranked seeds, goal nodes treated as ordinary candidates
- goal_graph: BFS from goal-node seeds first, then weight-ranked seeds

Scoring uses an LLM-as-judge protocol on four dimensions (1-5 each): relevance, completeness, goal alignment, noise ratio. Composite score is the mean of the four. Token efficiency is composite divided by context token count.

The benchmark harness (`php artisan benchmark:retrieval`) runs all three strategies against four synthetic but realistic corpora. Corpora 01-03 are compact personas (20-22 memories, 2-3 goals, 5 questions each). Corpus 04 is a long-horizon engineer corpus (40 memories, 3 goals, 5 questions) designed to force retrieval across up to 12 months of history. `--ablate-goals` reruns the same corpus with goal nodes excluded from seeding so goal contribution can be measured directly. Results are written as JSON and a Markdown report to `storage/benchmarks/`. The JSON includes the retrieved context that was judged, so each score can be audited against the exact records shown to the judge.

The finding this experiment targets: a specific percentage improvement in composite score for goal_graph over recency, and a specific percentage improvement in goal alignment for goal_graph over the weight-only graph strategy.

**Status: two complete runs recorded (2026-04-22).** Corpora 01-03 (45/45 calls): recency leads at composite 3.40, goal_graph -5.3% behind. Corpus 04 long-horizon (30/30 calls including ablation): recency gap narrows to -2.2%, goal alignment lift increases to +7.7% over plain graph. See DEVLOG Entries 025 and 026.

Claim 1 (better retrieval) is not yet confirmed. The recency advantage shrinks on harder corpora but has not flipped at CONTEXT_LIMIT=12. A corpus with more nodes or a smaller context-to-corpus ratio is the next test.

The benchmark measures retrieved context quality, not final answer quality. A later experiment should run the full assistant response path and judge the answer itself after the retrieval context has been injected.

### Claim 2: Safer memory ownership

A provider can use high-quality memory without storing or owning the memory corpus.

The experiment is architectural rather than quantitative. It documents the threat model: what the provider can and cannot access in the current design, and what the guarantees are for each sensitivity level.

Artifacts needed:
- A threat model document describing what provider-side code receives (scoped context records, not raw memory), what it never receives (private or sensitive records, the full memory graph, canister contents), and the cryptographic basis for those guarantees in live ICP mode.
- A demonstration that browser-signed writes prevent server-side forgery: the provider's Laravel backend cannot write to the canister under the user's principal.
- A demonstration that MCP-based reads expose only the public memory slice, and that private and sensitive records are never transmitted regardless of what the MCP client requests.
- A calculation of provider liability reduction: if a user's memory graph is stored in a canister they control, not on a provider's servers, what compliance obligations does the provider avoid?

This claim matters because user life context is high-liability data. A provider may prefer a design where the user owns memory and the assistant receives only scoped context records, rather than being the custodian of memory they cannot fully audit or delete.

**Status: open.** The architecture supports the claim. The threat model document and the demonstrations listed above are not yet written.

### Claim 3: Better goal continuity

Explicit goal nodes improve response alignment across sessions and across different types of knowledge in the graph.

The experiment runs the same memory graph and the same prompt set twice: once with goal nodes included in retrieval, once with goal nodes removed. The question it answers is whether the goal-included condition stays closer to the user's stated projects, priorities, and constraints, and whether that effect is measurable by LLM-as-judge evaluation of whether responses address the user's actual situation rather than just the immediate question.

This is the "second brain" component of the system. Remembering facts is necessary but not sufficient. The system also needs to remember what the user is trying to do, and surface that knowledge when the user asks a question that does not explicitly mention their goals.

**Status: measured (2026-04-22).** `--ablate-goals` flag implemented on `benchmark:retrieval`. On corpus_04 (long-horizon, 40 nodes): goal nodes contribute **+0.40 to goal_alignment** for goal_graph retrieval. They cost **-0.20 composite** because goal nodes occupy retrieval slots that would otherwise hold weight-ranked content more directly relevant to knowledge-retrieval questions. The tradeoff is real and quantified. Goal seeding helps "what should I work on?" questions; it is a net cost on "what was the decision about X?" questions. See DEVLOG Entry 026.

### Terminology for adoption-facing documentation

The biology-inspired terminology used elsewhere in this research agenda is not load-bearing in this track. Adoption-facing documentation and the benchmark reports should use systems language:

- "reinforced graph retrieval with decay" instead of "Physarum dynamics"
- "co-access reinforcement" instead of "Hebbian paths"
- "usage-weighted graph recall" instead of "slime mold memory"
- "provider-portable user memory layer" instead of "second brain"

The biology framing remains in SCIENCE.md and DEVLOG. This track's outputs (the benchmark report, the threat model document, and any external-facing writeups) use systems language throughout.

---

## Closed tracks

None yet. This agenda opened on 2026-03-13.

---

*This document is updated when tracks open, when their scope changes, and when they close. Closed track findings move into VISION.md. The discovery record for each track lives in the corresponding DEVLOG entry.*
