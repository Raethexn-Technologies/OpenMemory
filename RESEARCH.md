# OpenMemoryAgent — Research Agenda

This document is the active research agenda for OpenMemoryAgent. It sits between VISION.md and DEVLOG.md in purpose: VISION.md holds stable design positions, DEVLOG.md holds timestamped discovery records, and this file holds the open questions and the work required to answer them.

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

**Degree distribution endpoint.** `GET /api/graph/topology` computes the degree distribution of the current user's memory graph and fits a power law. The fit quality (R-squared against a log-log plot) determines whether the graph is scale-free. Run this against individual agent partitions and the collective graph to compare.

**Small-world metrics endpoint.** The same endpoint or a companion computes mean clustering coefficient and mean shortest path length, then compares both against a random Erdos-Renyi graph of the same node count and edge density. A graph is small-world if clustering is significantly higher and path length is comparable to the random baseline.

**Multi-scale comparison.** Run both metrics against individual agent graphs and the collective graph separately. The question is whether the collective graph belongs to the same topological class as individual graphs at a larger scale, or whether the shared edge layer produces a structurally different object.

**Topology panel in Three.js.** A metrics overlay in the mission control surface showing degree distribution, clustering coefficient, path length, and power law fit quality as live readouts, updated on each simulation tick and scrubbable through the snapshot history.

### What this track proves when closed

That AI memory organized by Physarum dynamics self-organizes into the same topological class as neural networks and cosmic structure, or that it does not. Either result is a finding. If it does, the galaxy visualization is scientifically accurate, not decorative. If it does not, the divergence tells you something about how Physarum dynamics on discrete memory graphs differs from the continuous physical systems they are modeled on.

**Status: open**

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

### What needs to be built

**Memorability classifier.** Before any memory is written to the graph, the LLM evaluates the candidate content against four criteria: novelty (not already well-represented in the graph), significance (the user engaged with this deeply or stated its importance), durability (likely to be relevant across future contexts), and connection richness (links meaningfully to existing high-weight nodes). The classifier returns one of three decisions: store as a new node, update an existing node with new information, or skip. This runs as a structured prompt call before the summarization step.

**Coverage check against the graph.** The novelty criterion requires a cosine similarity check against the user's existing nodes before storage. If the candidate memory has cosine similarity above a configurable threshold with an existing node, the decision should be to update that node rather than create a new one. This prevents the graph from accumulating duplicate representations of the same knowledge.

**Event-driven trigger.** Rather than firing at the end of every turn, the storage decision fires when specific conditions are met in the conversation: a decision is made, a preference is stated, a problem is solved, a significant piece of information is provided by the user, or a long-running topic concludes. These conditions are evaluated by the LLM at each turn, and the storage pipeline runs only when at least one condition is met. This reduces the storage rate dramatically while preserving high-signal memories.

**Memorability audit panel.** A view within the Memory Inspector that shows, for each stored memory, the memorability score it received at storage time and the criteria it met. This makes the storage trigger's behavior inspectable. If the classifier is storing too much noise or skipping genuinely important content, the panel makes the pattern visible so the criteria can be tuned.

**A/B comparison experiment.** Run two conversation sessions with the same content: one with the current store-everything approach and one with the storage trigger active. Compare the graph density, the ratio of high-weight to low-weight nodes after 100 turns, and the retrieval precision on a held-out evaluation set. The storage trigger is better if retrieval precision improves and graph noise decreases, with the same or higher recall on genuinely important content.

### What this track proves when closed

That a memorability classifier producing four-criteria judgments before storage results in a graph with higher mean edge weight, lower noise, and better retrieval precision than a store-everything baseline, and that the improvement is measurable on a held-out evaluation set. The secondary finding is the identified set of event-driven trigger conditions that best predict whether a conversation turn contains durable information worth keeping.

**Status: open**

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

**Status: open**

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

## Closed tracks

None yet. This agenda opened on 2026-03-13.

---

*This document is updated when tracks open, when their scope changes, and when they close. Closed track findings move into VISION.md. The discovery record for each track lives in the corresponding DEVLOG entry.*
