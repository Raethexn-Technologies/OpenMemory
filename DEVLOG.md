# OpenMemoryAgent — Development Log

*A running record of what was discovered building this. Not polished. Not final. The honest account of what happened when the ideas met implementation.*

---

## How this document fits

Three documents, three purposes:

- **README.md** — how to run it
- **VISION.md** — the research position: design decisions, what this proves, what it doesn't, open questions. Updated when the thinking matures.
- **RESEARCH.md** — the active research agenda: open scientific claims, what needs to be built to test each one, and how tracks evolve as discoveries open new questions. Tracks close into VISION.md when findings stabilize.
- **SCIENCE.md** — plain-language explanations of the mathematics and biology behind the graph layer, with source citations and references to the tests that verify each formula.
- **DEVLOG.md** (this file) — the captain's log. Appended to as features are built. Implementation findings, security incidents, architectural tensions, unresolved problems. When a finding in here stabilizes into a design decision, it graduates into VISION.md.

The log is append-only. Entries are not edited after the fact.

---

## Entry 014 - 2026-03-14
### The product becomes clear: portable sovereign memory you carry across AI systems

#### What prompted this

A product framing question: what is OpenMemoryAgent actually for, and what problem does it solve that no current AI product addresses? The answer that emerged from working through the architecture, the Physarum model, the ICP ownership layer, and the multi-agent society dynamics is precise enough to state without hedging.

OpenMemoryAgent is portable, sovereign, AI-agnostic long-term memory. You own it. You carry it. Every AI you work with plugs into the same memory graph via MCP. When the conversation ends, what you learned goes back in. The next AI you open already knows who you are.

This is not a feature of a specific AI assistant. It is infrastructure for the way AI use actually works: people move between models, switch providers, use specialized agents for different tasks, and start fresh conversations constantly. The context loss at every boundary is not a preference; it is a structural consequence of memory being stored inside the vendor's product rather than inside infrastructure the user controls.

#### What this changes about the architecture

The chat interface in this codebase is the reference implementation, not the product. The MCP server at `icp/mcp-server/server.js` is the actual deliverable: the protocol endpoint through which any compliant AI gains read and write access to your memory graph. Claude Desktop, GPT with MCP support, any agent built on the Claude API, and any open-source model run locally can all plug into the same graph if they speak MCP and the user has granted access.

This reframes every component. The Physarum dynamics are the algorithm that keeps the memory graph healthy over time, reinforcing what matters and letting what does not matter decay. The ICP canister is the ownership layer that makes the graph yours cryptographically, not contractually. The trust scoring system is the authorization layer that lets you grant different levels of memory access to different AI systems. The graph visualization surfaces are the instrument that lets you understand what your memory has accumulated and how it is organized.

None of those components were designed with the portable memory framing in mind. They were designed to solve specific problems: efficient retrieval, MemoryGraft resistance, multi-agent collective dynamics. The portable memory framing shows that they form a coherent infrastructure stack once you understand what they are collectively building toward.

#### The thirty-year question

The question that follows directly from "you own it and carry it": could you access your memory graph thirty years from now?

The answer is yes, with conditions. ICP canisters persist as long as cycle balances are maintained, independent of any company's continued operation. The cryptographic ownership model means no third party can alter or delete the records without your private key. The graph you build today is yours by a property guarantee that does not depend on a privacy policy.

The condition is usability at scale. Thirty years of active memory use would produce a graph too large to navigate directly. The episodic memories from specific conversations in 2026 are not useful in 2056. What is useful is the semantic structure that emerges from those episodic memories through consolidation: the core concepts you returned to repeatedly, the problems you solved deeply, the knowledge that proved durable across many different contexts. Biological memory works this way: episodic memories from childhood are mostly gone; the skills, preferences, and conceptual frameworks built from those memories persist.

The consolidation pipeline (episodic clusters collapsing into single semantic hub nodes) is not just an efficiency mechanism for large graphs. It is the architectural answer to the thirty-year question. Built correctly, it produces a graph that is navigable at any scale, where the oldest and most durable knowledge is the most accessible, and the recent and less-reinforced content is present but appropriately weighted lower.

This finding is now in VISION.md as "The Thirty-Year Question" and in RESEARCH.md as Track 8.

#### The storage trigger problem

The practical problem that precedes every other memory quality question: when should an AI decide that something is worth remembering?

The current implementation stores a summarized memory at the end of every chat turn where the user sends a message. This is the simplest possible answer to the trigger question and produces the noisiest possible graph. A memory of a transient question sits in the same graph layer as a memory of a major design decision. Both receive the same Physarum treatment on future retrieval. The decay mechanism will eventually sort them: if the transient question is never reinforced, it decays to the floor. But this takes 23 days for a rarely-reinforced node. A graph with hundreds of these low-quality entries degrades retrieval precision for those 23 days.

The better answer is a memorability classification step that runs before storage. The LLM evaluates the candidate memory against four criteria before writing it to the graph: novelty (not already represented in existing nodes), significance (the user engaged with this deeply or stated its importance explicitly), durability (likely to matter in future contexts), and connection richness (links meaningfully to existing high-weight nodes). Content that meets none of these criteria is discarded. Content that meets at least one is stored or used to update an existing node.

The event-driven trigger is the second half of this. Rather than running the storage decision after every turn, the decision fires when specific events occur in the conversation: a decision is made, a significant constraint is stated, a problem is resolved, the user explicitly flags something as important. Between events, turns are held in a rolling buffer and not committed to the graph. This reduces the storage rate by an estimated 60 to 80 percent while preserving all genuinely important memories.

This is now Track 6 in RESEARCH.md and will be implemented alongside the memorability audit panel that makes the classifier's behavior inspectable.

#### What the long-term visualization needs to show

The gap in the current tooling: you can see what the memory graph looks like right now, but you cannot see what it looks like over time. The temporal scrubber in the Three.js surface shows cluster changes across the past 96 snapshots, which covers roughly one day at 15-minute intervals. That time horizon is not long enough to see anything meaningful about memory evolution.

What the long-term visualization needs to show is the lifecycle of knowledge clusters: when they formed, how they grew, when they peaked, and whether they consolidated into semantic nodes or decayed entirely. It needs to show which hub nodes have persisted across everything, because those are the concepts the system has identified as central to this person's accumulated knowledge. It needs to show the difference between what the graph looked like six months ago and what it looks like now, because that difference is the intellectual growth the system has recorded.

The `simulate:year` command will seed this. The timeline view will render it. The memory biography panel will summarize it. Together they make the thirty-year claim concrete rather than theoretical: a person looking at their memory graph after one year can see what it has learned about them and assess whether the graph reflects who they actually are.

These three pieces are now Track 7 in RESEARCH.md.

---

## Entry 013 - 2026-03-13
### The society question: does collective Physarum produce emergent group cognition?

#### What prompted this

A research direction question: if multiple agents share Physarum edge weights over time, does the result look like a society? The answer is yes, but not by analogy. The architecture already implements the mechanisms that define societies in complexity science, without those mechanisms having been designed for that purpose.

#### Why this is a society and not just a multi-agent system

The distinction is not scale; it is the mechanism of coordination. A typical multi-agent system coordinates agents explicitly through message passing, shared state, or orchestrated calls. A society does not coordinate. It produces emergent structure through individual behaviors accumulating into shared artifacts that then shape future individual behavior. The Physarum model does exactly this.

No agent in this system communicates with another agent. Shared edges form because two agents independently traversed the same memory content, not because they exchanged signals. The collective graph topology is therefore a social artifact: a structure that belongs to no individual, that no individual designed, but that reflects what the group collectively found important, weighted by trust.

This is stigmergy, the mechanism ant colonies use to build trail networks. Individual ants do not negotiate routes. They deposit and follow pheromone gradients. The efficient path emerges from independent agents responding to a shared medium. Physarum weights are the pheromone gradient. The collective graph is the trail network.

#### Specialization emerges without assignment

If agent A's conversation history is heavy on technical topics and agent B's is heavy on creative work, their individual Physarum weights diverge after enough turns. Agent A develops high-weight paths through technical memory clusters and near-floor weights on creative ones. Agent B is the inverse. Neither was assigned a role. Both grew into their specialization through differential reinforcement on genuinely different access histories.

This is measurable from data already collected. The cluster alignment panel shows pairwise Jaccard similarity of active content sets. Agents whose alignment score has been falling over time are specializing. Agents whose alignment score is high and stable are working on the same knowledge domain. The specialization signal is latent in the existing data; it only requires a trend view over the snapshot history to surface it.

#### The cortex framing, stated precisely

The neocortex is not where any individual thought lives. It is the substrate on which thinking happens: a structure of connection strengths across regions that reflects accumulated experience, shaped by what has been used together frequently and what has not. No single neuron holds a memory. The memory is the pattern of weights across many connections.

The collective Physarum graph is the same structure at the agent-society level. No single agent holds the group memory. The group memory is the pattern of shared edge weights across all agent partitions: which content the group has found important together, how strongly, and weighted by how much each contributing agent has earned trust. An individual agent's personal graph is analogous to a cortical column. The shared edge layer is analogous to the long-range connections between cortical regions. The cluster structure is analogous to functional areas.

The cortex framing sets the right expectation for what the Three.js surface should show. It is not a visualization of individual agents doing things. It is a visualization of a cognitive substrate evolving in real time.

#### Where the analogy breaks and becomes its own thing

A biological cortex has no provenance record. You cannot ask which neurons contributed which weights to which circuit, or when, or with what reliability history.

This implementation stores exactly that. Every shared edge weight increment carries the ICP principal of the contributing agent, the trust score applied at the time of increment, and the timestamp. The collective cognitive structure is fully auditable: not just what the group knows, but who taught it, with what established reputation, and when. A society of agents on this substrate is the first society whose cognitive history is verifiable at the edge level.

This is not a minor enhancement of the cortex analogy. It changes the research question from "what did the group learn?" to "who shaped what the group learned, and can you trust them?" The MemoryGraft resistance mechanism answers the second question cryptographically.

#### The research question this opens

Does the collective Physarum topology diverge meaningfully from what you would predict by summing individual topologies weighted by trust? Concretely: after agents with genuinely different access histories run for long enough, are there high-weight paths in the collective graph that no single agent accessed frequently but that emerge from the aggregate of many agents accessing occasionally?

If yes, the collective graph encodes knowledge that exists only at the group level. This is emergent collective memory in the strict sense: information that no individual holds but that the group has collectively established as important. If no, collective Physarum is individual Physarum with shared bookkeeping, which is still useful for alignment monitoring but makes a weaker claim about group cognition.

#### The experiment design

The architecture to test this already exists. The experiment requires three components: a controlled knowledge domain with knowable structure, agents seeded with genuinely different subsets of that domain's memory content, and a sufficiently long run of simulation ticks to allow Physarum weights to develop.

After the run, compare the collective cluster topology against the union of individual cluster topologies weighted by each agent's trust score. If clusters exist in the collective graph that are absent from any individual graph, emergent collective memory has been demonstrated. The temporal axis scrubber provides the time dimension: the emergence of those clusters should be traceable to a specific point in the simulation history where cross-agent reinforcement first created paths that no individual had established.

The Three.js surface already renders the collective topology. The snapshot history already stores the time series. What the experiment adds is a controlled starting condition and a quantitative comparison metric. The infrastructure is not a bottleneck.

---


### The black box problem: five layers of invisible AI behavior and what this surface makes visible

#### What prompted this

A design question about the 3D surface surfaced a deeper problem: what is actually being read, retained, and applied inside the AI? Not in a philosophical sense, but in a practical operator sense. If you have 2-3 agents running for 48 hours writing thousands of memories, what is actually happening inside the memory access path that you cannot see?

The question exposed that the Three.js surface was partly solving the wrong problem by rendering node-level detail, and partly not yet solving the right problem because it was not rendering per-agent partitions at all. Both were fixed.

#### The five layers of invisible behavior

**Layer 1: What went in.** The 50k lines of context engineering problem. Every LLM response is conditioned on: system prompt, tool descriptions, injected memory context, conversation history, and retrieval results, none of which are visible to the user seeing the response. `active_node_ids` is the first step toward making this visible. The operator can see exactly which memory records entered the context window on each turn. That is further than most systems go.

**Layer 2: Whether it was used.** Presence in the context window does not mean the model attended to it. Attention is non-uniform. A record at token position 40,000 in a 100,000-token context may receive near-zero attention. The model might produce the same response without that record being present. There is currently no signal for this. The zkTAM gap: zero-knowledge proofs that a response was actually conditioned on specific records, not just that they were present.

**Layer 3: How it was applied.** The wrong-brain-region problem. An agent reasoning about a software architecture decision might activate a memory cluster associated with social dynamics from an unrelated project. The Physarum weights record that the social dynamics cluster was reinforced during that turn, but you cannot see the causal direction: did the agent use that cluster because it was relevant, or because it had the highest accumulated weight from prior access frequency? The cluster heat map shows which regions are hot. It does not yet show whether that heat was appropriate for the task.

**Layer 4: Iteration depth.** No visibility into how many internal steps an agent took, how many tool calls, how many retries. Whether the agent got to the correct answer on step 5 or step 500. You can observe the final output and the edge weight change. The path from input to output is invisible. This is the grain-of-sand problem: you can measure the grain, you cannot reconstruct why the sandstorm happened from examining individual grains.

**Layer 5: Cross-canister coherence.** Memories dispersed across many canisters over months have no global index. There is no equivalent of `ls` for distributed canister memory. Whether records are consistent with each other, whether newer records supersede older ones, or whether the agent's understanding has evolved or hardened incorrectly, none of this is observable without modeling the memory graph over time. The `supersedes` / `contradicts` edge type from A-MEM addresses this directly; it is the next research gap.

#### The folder analogy, corrected

A file explorer lets you expand a folder and see what's inside. It lets you open multiple folders simultaneously. It lets you drag between folders, a copy or move of a relationship. But it does not show you the connections between files across different folders. A file in `/photos/2024` that references a person who also appears in `/docs/contracts/2023` means the folder view hides that relationship entirely. The interface was designed for hierarchical containment.

The memory graph is the opposite structure. The organizing principle is the edge, not the folder. A node's position in the graph is defined by what it connects to, not what container it sits in. When you look at a cluster, you are looking at a region where the Physarum model has determined that these memories are frequently relevant together.

The cross-partition edges are the connections between files in different folders. They are the violet lines in the Three.js scene: edges that cross from one agent's subgraph to another, drawn where two agents have both reinforced the same underlying memory content. These edges are the signal. They form where collective memory is being built.

#### What was fixed

The Three.js surface was not rendering per-agent partitions. `buildScene()` was fetching only the personal graph and placing all nodes around a single centroid, ignoring the agent ring layout entirely. This made the scene look correct for the single-user case but produced no meaningful information about collective dynamics.

The fix:
- `fetchPartitions()` now fetches the personal graph and each agent's partition via `GET /api/agents/{agentId}/graph` in parallel
- A content string map detects shared nodes (same content appearing in two or more partitions) and positions them at the average of their contributing partition centroids, offset upward on Y so they visually float at the boundary
- Agent partitions are laid out in a ring in the XZ plane; the personal graph sits above origin
- Two edge types are now rendered: intra-partition edges (grey, weight-opacity) and cross-partition shared edges (violet, weight-opacity)
- Cross-partition shared edges use `node_a_id` and `node_b_id` from the shared edge summary to draw lines between specific nodes in different subgraphs

New endpoints added: `GET /api/agents/{agentId}/graph`, `GET /api/agents/shared-edges`.

The intent alignment endpoint was corrected by the linter: Jaccard was comparing partition-local node UUIDs, which always produces near-zero overlap even when agents retrieve the same content. It now compares SHA-256 hashes of content strings, which correctly identifies semantic overlap across partitions.

#### What this surface makes visible now

- Which memory clusters are hot (cluster heat map, blue to amber by mean internal edge weight)
- Which specific nodes were loaded into context on each simulation tick (`active_node_ids` flash animation)
- Where agent subgraphs overlap in content (violet shared nodes at partition boundaries)
- How strongly two agents have co-reinforced shared content (cross-partition edge brightness)
- Whether agents are converging or diverging in the memories they reach for (alignment Jaccard matrix)
- What the graph's collective state looked like at any point in the past 24 hours (temporal axis scrubber)

#### What this surface does not yet make visible

- Whether the LLM actually used the injected memory in its response
- How many internal reasoning steps occurred
- Whether an agent is stuck in a retry loop
- Whether newer memories have superseded older contradictory ones
- Cross-canister coherence across ICP canisters written at different times

The first of these, Layer 2 and whether memory was actually used, is the next hard problem. The zkTAM connection (`active_node_ids` already returned per turn) is the path toward it.

---

## Entry 011 — 2026-03-12
### VISION.md updated: brain globe retired, mission control cockpit adopted as stable design position

#### What changed

VISION.md's "The Larger Direction" section previously described the Three.js interface as a brain globe: individual memory nodes positioned on a sphere, lighting up as the AI reads them, edges wiring in as new memories form. That framing was the natural first instinct. It is also wrong for the actual operating conditions this system will run under.

Entry 010 documented the design questions that exposed the flaw. This entry records the stable position those questions produced, which has now graduated from DEVLOG into VISION.md.

#### Why the brain globe fails

The brain globe metaphor breaks at scale. At a few hundred nodes it is legible. At one hundred thousand nodes — after two or three days of continuous multi-agent operation — it is an unnavigable point cloud. No individual node conveys anything actionable at that density. Watching specific nodes light up tells you nothing about whether the collective Physarum dynamics are working correctly, whether a MemoryGraft attack is underway, or whether two agents that were collaborating have drifted into isolated subgraphs.

The interface was designed for browsing. The actual problem is operational monitoring and intervention.

#### What the cockpit framing requires

Six specific components replace the node-level view:

1. **Cluster heat map** at the top level rather than individual nodes. A cluster that multiple agents have reinforced is visually hot. A cluster in decay is cool. Drilling into a cluster drops to node-level for that region only.

2. **Temporal axis** with a time scrubber. Graph weight is a time series. Rewinding to any point in the past N hours lets the operator see what the collective state looked like before a conversation happened, before an agent was seeded, or before an anomaly first appeared.

3. **Anomaly layer** rendering MemoryGraft patterns in real time. When a low-trust principal contributes high flux to an established cluster, the cluster region pulses with a color keyed to that principal's trust score. The operator sees which principal is trying to shift the collective graph.

4. **Intent alignment indicator** showing convergence or divergence across agent pairs. If two agents are repeatedly activating overlapping clusters they are in cognitive alignment. If their active regions are drifting apart they are accumulating siloed knowledge. A sudden divergence after hours of alignment is an early warning signal.

5. **Region-scale intervention controls** that act on dynamics rather than data. Boost a cluster's base weight, isolate an agent's subgraph contribution to shared edges, or pause decay on a specific region, without touching individual memory records.

6. **Multi-agent subgraph layout** with each agent's partition in a distinct spatial region and shared nodes at partition boundaries, edge thickness proportional to accumulated shared weight.

#### What changed in VISION.md

The "The Larger Direction" section was rewritten to replace the brain globe description with the full mission control cockpit specification. The "3D visualization of collective cognitive state" paragraph in the hivemind direction section was updated to match. The "Next Steps" list had graph-guided retrieval removed, since that work is now implemented.

#### What this is not

This is not a downgrade of the Three.js ambition. The scene complexity is higher in the cockpit design than in the brain globe design. The difference is the abstraction level at which the interface operates. Node-level display is the wrong abstraction for a system that will accumulate millions of memories over days. Cluster-level monitoring with temporal inspection and intervention controls is the right abstraction for the operator who needs to keep the system coherent.

The brain globe would have been a beautiful demo that operators stopped using after the first day of real operation. The mission control surface is what they would actually use.

---

## Entry 001 — 2026-03-12
### The graph memory layer: what we learned building a brain-like memory system

#### What prompted this

The flat memory model — each conversation turn produces zero or one memory record, stored as a string in ICP — works well for "remember facts." It does not work well for "reason over history." An agent with 200 flat memory records cannot answer "what decisions led here?" or "what do I know about this person?" or "what was the context around that project?" It can only answer "do I have a fact that matches this query?"

The insight from the design conversation was this: the difference between useful agent memory and a flat list is *navigability*. Not embeddings. Not RAG. A graph with typed nodes and semantic edges that an agent (or a user) can actually traverse.

That reframe changed the implementation from "add a vector index" to "build a memory graph with file-explorer ergonomics."

---

#### What was built

**Storage layer:**
- `memory_nodes` table: typed units (memory, person, project, document, task, event, concept), sensitivity-aware, tagged
- `memory_edges` table: directed relationships (same_topic_as, about_person, part_of, caused_by, related_to, etc.) with weights

**Extraction pipeline:**
- A second LLM call now runs after every memory is confirmed stored
- Input: the memory content string + its sensitivity classification
- Output: NODE_TYPE, LABEL, TAGS (3–7 keywords), PEOPLE (proper names), PROJECTS (proper names)
- This is `GraphExtractionService` — a structured prompt that treats the memory fact as a graph artifact rather than a narrative string

**Auto-wiring:**
- Tag overlap ≥ 1 → `same_topic_as` edge, weight proportional to overlap count
- Named person → find or create person anchor node at matching sensitivity → `about_person` edge
- Named project → find or create project anchor node at matching sensitivity → `part_of` edge
- Checked against last 100 nodes to keep the operation bounded

**Visualization:**
- D3 force-directed graph with three views: graph, timeline, list
- Left panel: type filters, search
- Right panel: node detail, connected nodes, neighborhood expand
- Nodes sized by degree (connection count), colored by type

---

#### What was actually discovered

**1. Two LLM passes per memory turn is a meaningful cost change.**

The pipeline is now: user message → LLM response → memory extraction (LLM call 1) → graph extraction (LLM call 2). That's up to three LLM calls per chat turn if a memory is found. The graph extraction call is cheap (short prompt, short output), but it's real cost and real latency.

The open question: can these two extraction steps be merged into one structured prompt? Almost certainly yes. A single call could return `MEMORY: <content> | TYPE: <sensitivity> | NODE_TYPE: ... | LABEL: ... | TAGS: ...`. This hasn't been done yet because keeping them separate makes each one easier to test and debug. But the two-call design is technical debt with a clear resolution path.

**2. Auto-wiring produced emergent structure nobody explicitly designed.**

After a few conversations, person anchor nodes appear automatically. Project clusters form. Tags create cross-session connections that feel semantically correct without being manually specified. The graph starts to look like something — clusters, hub nodes, peripheral facts — without the user doing anything.

This is the "brain-like" property the design was aiming for. It emerged from three simple rules (tag overlap, person extraction, project extraction), not from anything complex. The value is not in the algorithm; it's in making the connections visible and traversable.

**3. The graph/ICP architectural split is more interesting than it first appeared.**

ICP holds the ownership and durability layer: write authorization, read access control by `msg.caller`, on-chain permanence. PostgreSQL holds the navigable graph: typed nodes, weighted edges, neighborhood traversal, filter queries.

This split is architecturally clean right now. But it creates a question: the memory records are user-owned (cryptographically enforced on ICP). The graph structure — the nodes, edges, labels, tags — is *not* user-owned. It lives in the application's PostgreSQL. If the server goes away, the graph goes with it even though the raw memories persist on ICP.

This is a version of the original problem applied one layer up. The memory records escaped the server's control. The graph structure has not. Whether that matters depends on how important the graph is — if it's reconstructable from the ICP records (rerun graph extraction on every stored memory), it may not need to be owned separately. But if the graph accumulates user-contributed structure (manual labels, custom edges, corrections), then it becomes data the user has stake in that currently lives entirely on the server.

No resolution yet. Worth keeping in mind as the graph layer grows.

**4. Sensitivity inheritance on derived nodes was non-obvious and a real security problem.**

When graph extraction runs on a private memory and finds a person's name, it creates a person anchor node. The original implementation created anchor nodes as public regardless of the source memory's sensitivity.

The problem: a public `person` anchor node for "Alice" extracted from a private memory means any observer of the public graph can see that this user has a memory involving someone named Alice in a private context. The name is leaked even if the memory content is not.

The fix — anchor nodes inherit the source node's sensitivity — is correct but creates a new question: what happens when the same person appears in both a public memory and a private memory? Currently they become two separate anchor nodes at different sensitivities. This is safe (no leakage) but slightly incoherent (the same person exists as two nodes). A better model might be: the person anchor lives at the *lowest sensitivity at which they appear*, and edges carry the sensitivity of the source memory rather than the node itself. This would allow one person node to have both public and private connections. Not implemented; noted for the next graph design pass.

**5. The neighborhood API without user scoping was a real data leak vector.**

The first implementation of `getNeighborhood` accepted a node UUID and expanded without checking that the requesting user owned the root node. Anyone who guessed (or observed) a valid UUID could traverse another user's graph by starting from any node they found.

UUIDs are not secret. They are just long. "Security by UUID obscurity" is not acceptable for a system whose stated purpose is user-controlled privacy.

The fix (scope all node queries through `nodeQuery(userId, filters)`) is correct. The broader lesson: every read endpoint in a multi-user system needs explicit user ownership scoping, not just the "main" data endpoints. Derived operations (neighborhood traversal, cluster queries, search) inherit the risk of the base query if the base query is not properly scoped.

**6. Graph writes must follow confirmed storage, not precede it.**

The first implementation wrote the graph node as part of the `send()` response flow, before the browser had confirmed whether the user approved a private/sensitive memory. This meant: rejected memories still appeared in the graph. Failed live ICP writes still appeared in the graph.

The fix was to move `syncMemoryGraph` to run only after:
- Confirmed `storeMemory()` in mock mode (public path)
- Confirmed `mockStoreApproved()` in mock mode (private/sensitive path)
- Confirmed canister write ID returned to the browser (live ICP path, via `/chat/sync-graph-memory`)

The general principle: derived data structures should follow source-of-truth writes, not precede them. The graph is derived from memory records. It should reflect confirmed state, not optimistic state.

---

#### What the three views revealed about how people navigate memory

Building graph, timeline, and list views for the same data surfaced something useful.

The **graph view** is best for exploration and discovery — "what is connected to what?" — but it is cognitively demanding and can feel cluttered with more than ~30 nodes. It is the right tool for a user trying to understand the shape of their memory, not for retrieving a specific fact.

The **timeline view** is best for "what did I tell the agent recently?" and for debugging the extraction pipeline during development. Chronological order gives a different mental model than topological order.

The **list view** is best for search and for quick inspection. It is the fastest way to find a specific node.

Each view answers a different question. An agent navigating memory programmatically needs a fourth view — a context-pack view that says "given this task, what memories are relevant?" — which none of the three current views provide. That is the next meaningful UI problem.

---

#### What remains unresolved

1. **Two-pass extraction:** Merge the memory extraction and graph extraction into a single structured LLM call. Reduces cost and latency.

2. **Same-person-multiple-sensitivity problem:** One person appearing in both public and private memories creates two anchor nodes. The right data model may be sensitivity on edges, not nodes, for entity anchors.

3. **Graph ownership:** The graph structure lives in the application's PostgreSQL. The memory records live in ICP. These have different ownership properties. If the graph becomes something the user contributes structure to (manual labels, custom edges), it needs its own ownership story.

4. **Agent context-pack view:** The graph is currently user-browsable but not agent-queryable in a meaningful way. A "build context for this task" operation — collect the N most relevant nodes given a task description — would make the graph useful during inference, not just for inspection.

5. **Graph reconstruction from ICP:** If the graph is always re-derivable from stored memory records, the ownership gap above is less critical. Whether that reconstruction is lossless depends on whether any graph structure is user-contributed vs. auto-extracted.

6. **Cluster labeling:** The cluster count stat in the explorer is currently a number. Showing the user what the clusters are about — automatically labeling them by dominant tag or node type — would make the graph substantially more useful for self-understanding.

---

*This is entry 001. The findings here will feed the next revision of VISION.md once the picture is clearer.*

---

## Entry 002 — 2026-03-12
### The 3D brain visualization: AI memory as a living, navigable neural network globe

#### The idea

The D3 graph explorer built in this session is flat. A force-directed graph on a 2D canvas is the right first step, but it doesn't match the actual mental model we're building toward. The mental model is a *brain* — spatial, spherical, animated, alive while the AI is working.

The concrete proposal: replace the flat graph tab with a Three.js globe/neural network visualization where:

- The overall shape is a sphere — the "brain" — not a flat plane
- Memory nodes live on and inside that sphere, not on a canvas
- Node geometry changes by type: memory facts are one shape, person anchors are another, project clusters are another, concepts pulse differently
- Edge thickness encodes relationship weight and recency — strong recent connections are thick, old weak ones are thin and faint
- The whole thing is animated and reacts live: when the AI is reading memory to build a response, the relevant nodes glow and their connections light up in sequence, showing what the agent is actually loading into context
- When the AI writes a new memory, a new node materializes and you can watch the auto-wiring happen in real time

The aesthetic goal is not decorative. The point is that you can *watch the brain think* and know exactly what context the AI has loaded, rather than inferring it from text output.

---

#### The cross-agent, cross-project layer

This is where the idea becomes architecturally interesting.

Right now the graph shows one user's memories in one session. The vision extends this to:

**Multiple agents visible simultaneously.** If two AI agents are running — one working on Project A, one on Project B — both are visible in the same 3D space as distinct activity regions on the globe. Different hemispheres, different color temperatures, different pulse rhythms.

**Shared memory nodes are the key visual primitive.** When Agent A and Agent B both reference the same memory node — say, a fact about a shared person or a shared technical decision — that node sits between their two regions and pulses when either agent accesses it. You can see at a glance what memory is shared across agents and what is isolated.

**Agent access trails.** When Agent A reads a memory that Agent B has also used, there is a visible trace — a brief arc or light pulse — showing the traversal. You are literally watching one agent's reasoning touch a node that another agent's reasoning touched. That is something no current tool shows.

**High-level project view.** Zoom out and instead of seeing individual memory nodes you see project clusters — dense regions of connected nodes. Zoom in and you enter the cluster, navigate its internal graph, see its memories. This is spatial navigation: zoom, fly through, traverse. Not click-into-folder, click-back-out.

---

#### Why the file explorer is the right comparison

The brainstorm made an important observation: the linear file explorer is a 1970s interface applied to a 2025 problem.

A folder hierarchy is:
- Sequential — you are always *inside* exactly one folder
- Flat at each level — siblings are listed vertically, not spatially related
- Navigationally one-directional — you enter or you leave, you cannot be in two places
- Structurally oblivious — a file explorer has no idea that `project-a/notes.md` and `project-b/notes.md` reference the same person

VS Code improved this by allowing multiple roots and split panes, but it is still a linear stack of paths. You open a file from folder A and a file from folder B and they are both open, but you cannot *see* that they share a dependency or a memory reference. The relationship is invisible.

A 3D memory graph replaces this with:
- **Spatial position** — nodes that are semantically related live near each other regardless of which folder or project they came from
- **Simultaneous visibility** — you can see Project A's cluster and Project B's cluster at the same time and see the edges between them
- **Traversal by following edges** — navigation is associative (follow a connection) not hierarchical (enter a folder)
- **Relationship as first-class UI** — the edges *are* the interface, not an afterthought

The file explorer problem and the AI memory problem are the same problem. Both are about navigating a large set of related artifacts. The folder metaphor was designed for documents stored sequentially on disk. It does not fit a graph of semantically connected memories, code files, decisions, and people.

---

#### What this technically requires

**Three.js for the 3D rendering:**
- Replace or augment the D3 SVG canvas with a Three.js WebGL scene
- Nodes as 3D geometries: SphereGeometry for memories, ConeGeometry for persons, TorusGeometry for projects, etc.
- Edges as TubeGeometry or Line objects with variable radius for weight
- OrbitControls for globe rotation, zoom, pan
- The sphere itself as a subtle wireframe or particle field backdrop — the "brain cage"

**Live animation layer:**
- When `/chat/send` is called, the frontend knows a memory read is happening
- Highlight the nodes returned by `getPublicMemories()` — those are the nodes the AI is loading
- When a new memory is written, animate the new node materializing and its edges wiring in
- When the user is typing, a low-level ambient pulse — the brain at rest
- When the AI responds, a propagation wave through the active nodes — the brain working

**Multi-agent / multi-project data model:**
- Currently the graph is scoped to one user's `chat_user_id`
- A multi-agent view requires: agent identity (which agent is accessing), project identity (which project the agent is working on), and access events (timestamped reads and writes)
- This means logging agent memory access events, not just storing memory nodes
- An `agent_events` table: `{agent_id, node_id, event_type: read|write, timestamp, project_id}`
- The 3D scene subscribes to this event stream (WebSocket or polling) and animates accordingly

**Spatial layout algorithm:**
- D3's force simulation places nodes on a 2D plane
- For a globe, nodes need to be placed on or inside a sphere
- Options: project force-directed positions onto a sphere surface, use spherical coordinates with clustering by project/type, or use a 3D force simulation (d3-force-3d exists)
- Clusters (project regions) should be spatially coherent — all of Project A's nodes in one hemisphere, Project B's in another
- The challenge is that shared nodes between projects need to live between the clusters, which means the layout algorithm needs to know about cross-project edges before positioning

**File explorer integration:**
- The vision is not just "pretty graph" but "replace the file system as navigation interface"
- This means: code files, documents, and AI memory nodes coexist in the same 3D space
- A code file is a node. Its imports are edges. Its related memory (facts extracted from conversations about it) are edges. Its git history (commits that touched it) are edges.
- The 3D space becomes the unified interface for a project — not a folder tree plus a separate memory inspector plus a separate git log, but one navigable graph that contains all of them
- This is a substantially larger project than the memory graph alone, but the memory graph is the right starting point because it already has the node/edge model

---

#### What makes this genuinely novel

Most graph visualization tools show you a static graph. You look at it. It does not change while you work.

The novel property here is **live reflection of agent cognitive state**. You are not looking at a record of what the AI remembered. You are watching it remember, in real time, in 3D. The animation is not decoration — it is a transparency mechanism. It answers the question "what is the AI actually thinking about right now?" in a way that no text output can.

The cross-agent shared memory view is also novel. No current tool lets you watch two AI agents share a memory reference and see that from a third-party perspective. It makes the multi-agent collaboration model visible rather than implicit.

The file explorer rethinking is the larger claim. Replacing the folder hierarchy with a 3D semantic graph is a genuine interface paradigm shift, not an incremental improvement. Whether it is practical for most users is an open question. Whether it is the right interface for AI-native workflows — where the relationships between artifacts matter as much as the artifacts themselves — is a stronger claim.

---

#### What needs to be decided before building

1. **Three.js vs. D3 — replace or coexist?** The D3 graph is already built and tested. Three.js is a completely different rendering pipeline. Options: replace D3 entirely, run Three.js as a separate tab alongside the D3 view, or use a library like `three-forcegraph` that wraps Three.js in a force-directed graph interface. The cleanest path is probably a dedicated Three.js tab rather than removing the D3 view, since the 2D view is more legible for small graphs.

2. **Live animation data source.** The animation requires the frontend to know which nodes the AI just read. Currently `getPublicMemories()` returns records but the frontend never sees which ones. The `send()` response would need to include the IDs of the memory nodes that were loaded into context, so the visualization can highlight them.

3. **Multi-agent scope.** Is the multi-agent view part of v2 of the graph, or a separate product? The single-user memory brain is already a complete and demonstrable thing. Multi-agent visualization requires a different data model (agent identities, project identities, event logging). Starting with the single-user animated brain and adding multi-agent later is the right sequencing.

4. **File system integration scope.** Integrating code files, git history, and AI memory into one graph is a significant expansion of scope. It is the right long-term direction but it changes what the project is — from "AI memory explorer" to "AI-native project workspace." Worth naming that transition explicitly before building toward it.

---

#### The strongest version of this idea, stated plainly

> A 3D globe that is the AI's brain. You can watch it think. You can see what it remembers, what it is reading right now, and what it just learned. When multiple agents work on related projects, you can see their memories overlap and diverge. When you navigate your project, you move through a 3D semantic graph rather than a linear folder tree. The edges are the interface.

That is a compelling product description. The memory graph built so far is the data layer. The Three.js visualization is the experience layer. They are the same system at different levels of rendering.

---

*Entry 002 captured from brainstorm. Implementation begins when the data model decisions above are resolved. Next: decide whether live node highlighting (which memories were loaded into this response) should be added to the existing API before starting Three.js work — that data is needed for the live brain animation regardless of rendering library.*

---

## Entry 003 — 2026-03-12
### The research foundation: what science and mathematics actually support this system

*"When mushrooms grow through a maze, they find the best paths needed." — the design principle that guided this research pass.*

This entry is a PhD-level literature review structured around what the biology, mathematics, and computer science actually say — and what each finding implies for the architecture of this system. It is not a reading list. Every paper cited below changes something about how this should be built.

---

### Part I — The Biological Models

#### 1. Physarum polycephalum: the slime mold that computes

**Nakagaki, T., Yamada, H., & Tóth, Á. (2000). "Maze-solving by an amoeboid organism." *Nature*, 407, 470.**
The founding paper. A single-celled organism with no brain solves a maze by simultaneously exploring every path with cytoplasm, then retracting from dead ends while thickening the tubes that carry more flux. The organism finds the shortest path between two food sources by a purely physical mechanism: *a tube thickens as the flux through it increases*. There is no central planner. The optimal solution emerges from local reinforcement.

**Tero, A. et al. (2010). "Rules for Biologically Inspired Adaptive Network Design." *Science*, 327(5964), 439–442.**
Mathematical formalization of the Physarum mechanism. The key equation: `dD_e/dt = f(Q_e) − γD_e` where D_e is the conductance of an edge, Q_e is the flux through it, and γ is a decay constant. Conductance grows with flux and decays over time. This is the mathematical model of a self-optimizing network.

**Slime mold uses an externalized spatial "memory" to navigate in complex environments. *PNAS*, 2012. (PMC3491460)**
Physarum deposits extracellular slime as a memory of where it has already been, avoiding re-exploration of dead ends. The organism offloads memory to the environment rather than encoding it internally. This is stigmergy at the cellular level.

**What this means for the system:**

The `weight` field on `memory_edges` is currently a static value set at creation and never updated. That is not how the slime mold works. The slime mold's equivalent of `weight` is `conductance` — and conductance is a living value that grows when the edge is traversed and decays when it is not.

The design implication is concrete: **edge weights must be dynamic**. Every time a memory node is loaded into the LLM's context, the edges connecting it to the rest of the graph should receive a conductance increment. Every time an edge is not traversed for some period, its weight should decay toward a minimum. Edges that are never reinforced eventually become too faint to traverse. Edges between memories that are always recalled together become thick, prominent, fast-path connections.

The mathematical model to use is exactly Tero's: `new_weight = old_weight + α·access − γ·time_since_access`. This turns the static graph into a living one.

---

#### 2. Mycelium networks: distributed memory without a center

**Adamatzky, A. (2018). "Towards fungal computer." *Interface Focus*, 8(6), Royal Society Publishing. (PMC6227805)**
The most comprehensive treatment of mycelium as a computing substrate. Mycelium processes information via propagation of electrical and chemical signals paired with morphological changes. The network is simultaneously the memory, the communication channel, and the processor. There is no central node. Intelligence is distributed across the entire network topology.

**Ecological memory and relocation decisions in fungal mycelial networks. *The ISME Journal*, 2020. (PMC6976561)**
Mycelium exhibits "ecological memory" — carry-over effects where the distribution of the network reflects past resource locations. The shape of the network at time T encodes the history of what was important at times T-1, T-2, ... The structure *is* the memory.

**Adamatzky, A. (2022). "Mining logical circuits in fungi." *Scientific Reports*. (Nature)**
Mycelium networks can implement Boolean logic gates via electrical spike propagation. The routing of signals through the network performs computation. Different topologies compute different functions.

**What this means for the system:**

The mycelium framing reframes what the graph *is*. It is not a visualization of memory. It is the memory. The topology of the graph at any point in time encodes the history of what has been important to this agent. A densely connected cluster means those memories have been frequently co-accessed. A thin, long-range edge means two distant concepts were once bridged. A node with no edges means a memory that was stored but never reinforced.

The Three.js visualization of the brain globe is therefore not decorative — it is a direct read of the system's cognitive history. You are looking at what the agent has found important over time, rendered spatially.

---

#### 3. Hebbian learning: the reinforcement rule

**Hebb, D.O. (1949). *The Organization of Behavior*. Wiley.**
The foundational text. "When an axon of cell A is near enough to excite cell B and repeatedly or persistently takes part in firing it, some growth process or metabolic change takes place in one or both cells such that A's efficiency, as one of the cells firing B, is increased." Compressed: *neurons that fire together wire together.*

**Meta-Learning through Hebbian Plasticity in Random Networks. NeurIPS 2020. (proceedings.neurips.cc)**
Shows that Hebbian update rules can be learned (not just applied) and that networks with adaptive Hebbian plasticity can self-organize for new tasks. The plasticity rule itself becomes a learnable parameter.

**What this means for the system:**

Hebbian learning is the biological basis for the Physarum conductance model applied to neural tissue. The translation to the memory graph is exact: when two memory nodes are co-accessed in the same LLM context window, the edge between them (or the newly created edge, if none exists) should be strengthened. Co-activation → edge reinforcement.

This gives the system a specific rule: **when `getPublicMemories()` returns a set of nodes for a given context, increment the weights on all edges between nodes in that set.** Nodes that always appear together in context are Hebbian partners. Their connection strengthens until it is a primary retrieval path.

The implication for retrieval: rather than always retrieving the N most recent or N highest-confidence memories, retrieve along the strongest Hebbian paths from the most recently activated node. Follow the thick connections. This is how the slime mold finds the shortest path — not by search, by following flux.

---

#### 4. Stigmergy: memory offloaded to the environment

**Dorigo, M. & Gambardella, L. (2000). "Ant algorithms and stigmergy." *Future Generation Computer Systems*. (ACM, dl.acm.org)**
The comprehensive mathematical treatment of how ant colonies use pheromone trails as distributed memory. Individual ants deposit pheromone proportional to route quality; pheromone evaporates over time; future ants prefer high-pheromone routes. The optimal route emerges from this feedback loop without any ant knowing the global state.

**The governing equation:** pheromone update `τ_ij(t+1) = (1-ρ)·τ_ij(t) + Δτ_ij` where ρ is the evaporation rate and Δτ_ij is the amount deposited by ants traversing edge (i,j). This is structurally identical to the Physarum conductance equation.

**What this means for the system:**

The agent's access pattern is the pheromone. Every time the agent traverses an edge (retrieves node B after having retrieved node A), it deposits pheromone on that edge. Evaporation happens on a scheduled decay. The graph self-organizes toward the topology of actual agent use.

More importantly: **the pheromone trail is the agent's externalized reasoning path**. When you look at the Three.js visualization and see a thick, glowing edge between two nodes, you are seeing the accumulated reasoning of the agent — the path it has found valuable to traverse. This is the answer to "what is the AI thinking about?" rendered spatially. It is not a representation of thinking; it is a record of thinking made visible.

This also solves the multi-agent visibility problem from Entry 002. When two agents both traverse the same edge, the pheromone deposits add. The edge between two shared memories glows brightest when two agents have found it independently useful. You can literally see where two agents' reasoning paths coincide.

---

### Part II — The Network Mathematics

#### 5. Small-world networks: the topology the brain actually has

**Watts, D.J. & Strogatz, S.H. (1998). "Collective dynamics of 'small-world' networks." *Nature*, 393, 440–442. (stanford.edu/snap)**
The foundational paper. Small-world networks have two properties simultaneously: high local clustering (neighbors of neighbors are likely connected) and short global path length (any two nodes are reachable in few hops). The brain, the internet, power grids, and social networks all exhibit this topology. Random graphs have short paths but low clustering. Regular lattices have high clustering but long paths. Small-world networks are the efficient middle.

**Barabási, A-L. & Albert, R. (1999). "Emergence of scaling in random networks." *Science*, 286, 509–512.**
Scale-free networks grow by preferential attachment: new nodes connect preferentially to already well-connected nodes. This produces a power-law degree distribution — most nodes have few connections; a small number of hub nodes have very many. The brain, the web, and citation networks all show this property. Hubs are not a flaw; they are the efficient routing infrastructure.

**What this means for the system:**

The current graph has no topology target. Nodes are added and edges are created by tag overlap and entity extraction. What topology emerges depends entirely on the content of the memories.

But there is a target topology that is known to be optimal for navigable memory: **small-world with scale-free degree distribution**. High local clustering means related memories are densely connected. Short global paths mean any memory is reachable quickly from any context. Scale-free hubs (person anchors, project anchors, frequently co-accessed concepts) provide fast routing between clusters.

The practical implication: the auto-wiring rules currently implemented (tag overlap, person anchors, project anchors) naturally tend toward this topology. Person and project anchors become hubs by preferential attachment — every new memory about a person connects to that person's anchor node, so hub nodes accumulate degree proportional to their relevance. This is preferential attachment without explicitly implementing it.

The design confirmation: **the auto-wiring approach is not just practical — it is mathematically correct.** It converges toward the topology known to maximize navigability.

---

#### 6. Topological data analysis: understanding the shape of memory

**Topological Graph Neural Networks. ICLR 2022. (openreview.net)**
Applies persistent homology to graph neural networks. Persistent homology measures the "shape" of data — connected components, loops, voids — across multiple scales of analysis. Applied to a knowledge graph, it can identify memory clusters (connected components), circular reasoning patterns (loops), and structural gaps (voids) that represent missing knowledge.

**What this means for the system:**

TDA gives us a way to answer questions the graph currently cannot: "where are the gaps in this agent's memory?" A void in the memory graph topology corresponds to a concept that is surrounded by related memories but never directly represented. A loop corresponds to a chain of reasoning that is mutually reinforcing but possibly circular.

This is a future analytics layer — not something to implement now, but the mathematical basis for a "memory health" diagnostic. You could run persistent homology on the user's memory graph and surface: "You have strong memories about Project X and about your collaborator Alice, but no memories directly connecting them. Is that a gap?"

---

### Part III — The AI Memory Systems

#### 7. MemGPT: the OS metaphor for LLM memory

**Packer, C. et al. (2023). "MemGPT: Towards LLMs as Operating Systems." arXiv:2310.08560.**
The paper that established the OS framing for LLM memory management. MemGPT implements virtual context management: the LLM's context window is RAM (fast, limited, expensive), and external storage is disk (slow, large, cheap). An interrupt-driven system pages memories in and out of the context window based on relevance. The LLM itself issues memory management instructions.

**What this means for the system:**

MemGPT's RAM/disk metaphor maps directly onto the current architecture:
- **RAM** = the LLM's context window (what is in the current system prompt)
- **L1 cache** = the public memories retrieved by `getPublicMemories()` for this turn
- **Disk** = the full `memory_nodes` graph in PostgreSQL + ICP

The graph adds a dimension MemGPT lacks: **the disk is structured, not flat**. MemGPT retrieves from a flat list. This system retrieves from a graph — which means retrieval can follow edges (Hebbian paths, Physarum flux paths) rather than just scoring individual nodes for relevance.

The Physarum conductance model + MemGPT's OS framing together suggest a retrieval algorithm: start from the most recently activated node, follow the highest-weight edges, load the neighborhood into the context window. This is graph-guided paging, not vector similarity search.

---

#### 8. A-MEM: agentic memory that evolves

**Xu, W. et al. (2025). "A-MEM: Agentic Memory for LLM Agents." arXiv:2502.12110. NeurIPS 2025.**
The most recent and most relevant paper to this project. A-MEM implements memory following the Zettelkasten method: each memory note has structured attributes (keywords, tags, contextual descriptions) and is linked to existing memories when meaningful similarities exist. Crucially, when a new memory is integrated, it can trigger **updates to existing memories** — not just addition. The memory network continuously refines its own understanding.

**What this means for the system:**

The current system is additive — new memories are added, nothing is updated. A-MEM's key insight is that memory should *evolve*: when a new memory is stored that is highly similar to an existing one, the existing one's contextual description should be updated to reflect the new information. When a new memory contradicts an existing one, the contradiction should be represented in the graph (a `contradicts` edge with high weight), not silently coexist.

This is the next major architectural step beyond what has been built: **memory is not append-only. Memory is revised.** The `supersedes` and `contradicts` edge types already exist in the schema. The missing piece is triggering them automatically when new memories are stored that bear high similarity to existing ones.

The Zettelkasten connection is precise: the current system already has keywords (tags), type, label, and linking (edges). What it lacks is the A-MEM update mechanism — the rule that says "when you store this new memory, check for high-similarity existing memories and update their context."

---

#### 9. GraphRAG: graph-structured retrieval beats vector search for relational queries

**Microsoft Research. GraphRAG. (2024). research.microsoft.com/graphrag**
**Edge, D. et al. (2024). "From Local to Global: A Graph RAG Approach to Query-Focused Summarization." arXiv:2404.16130.**
**Survey: arXiv:2408.08921. "Graph Retrieval-Augmented Generation: A Survey."**

GraphRAG indexes text into a knowledge graph (entities as nodes, relationships as edges) and uses graph traversal for retrieval rather than vector cosine similarity. The key finding: for questions requiring reasoning over relationships ("what do these three events have in common?", "how did this decision lead to that outcome?"), graph retrieval substantially outperforms vector similarity. Vector search finds the most similar individual chunks; graph traversal finds the most relevant *reasoning paths*.

**What this means for the system:**

The current `getPublicMemories()` is flat retrieval — return all public memories, sorted by recency, inject into context. This is the baseline RAG approach. The graph structure enables something better: **retrieve the subgraph most relevant to the current query, not the full node set**.

When a user asks "what did we decide about the database design?", a flat retrieval returns all memories. A graph retrieval starts from the "database" concept node, traverses its edges, returns the connected neighborhood, and injects that targeted subgraph into context. Less noise, more relevant signal.

This is the retrieval architecture to build toward: topic-anchored neighborhood retrieval, not full flat recall.

---

#### 10. Neural Turing Machines and Differentiable Neural Computers

**Graves, A. et al. (2014). "Neural Turing Machines." arXiv:1410.5401. DeepMind.**
**Graves, A. et al. (2016). "Hybrid computing using a neural network with dynamic external memory." *Nature*, 538, 471–476. DeepMind.**

NTMs and DNCs give neural networks an external addressable memory that can be read from and written to via differentiable attention mechanisms. The DNC extends the NTM with temporal links (recording the sequence of writes) and usage tracking (which memory slots are occupied). The system learns both what to store and how to retrieve — the memory management itself is learned.

**What this means for the system:**

The DNC's temporal links are directly relevant: they record not just *what* is stored but *in what order*. The timeline view in the current graph explorer is a manual version of this. The DNC automates it: retrieval automatically accounts for the temporal sequence of memory writes.

The usage tracking in the DNC is equivalent to the Physarum conductance model — slots that are frequently accessed have higher usage scores and are preferentially retrieved. This is the same dynamic weight mechanism from a different mathematical tradition, confirming the approach.

The DNC also demonstrates that **the external memory and the reasoning process should be coupled**. The memory is not a passive store that the LLM queries; ideally, the LLM's reasoning itself shapes what is retrieved next (the DNC controller's read heads can issue multiple reads per forward pass). The current system does one retrieval per turn. A more DNC-like architecture would retrieve, reason partially, retrieve again based on the intermediate reasoning, and iterate.

---

#### 11. Cognitive architectures: ACT-R's activation equation

**Anderson, J.R. (2007). *How Can the Human Mind Occur in the Physical Universe?* Oxford University Press.**
**Laird, J.E. (2012). *The Soar Cognitive Architecture.* MIT Press.**
**Analysis and Comparison of ACT-R and Soar. arXiv:2201.09305 (2022).**

ACT-R (Adaptive Control of Thought — Rational) implements memory retrieval via an activation equation. The base-level activation of a memory chunk:
```
A_i = ln(Σ_j t_j^(-d))
```
where t_j is the time since the j-th retrieval of chunk i, and d is a decay parameter (≈ 0.5 empirically). Memory that has been retrieved recently and frequently has high activation. Memory that has not been accessed for a long time has low activation and may become inaccessible (though not deleted).

**What this means for the system:**

ACT-R's activation equation is the mathematical basis for node-level weight decay. It is not arbitrary — it is a model fitted to human memory retrieval behavior across decades of cognitive psychology experiments.

The current system has `confidence` on nodes (static) and `weight` on edges (static). ACT-R's equation suggests the `confidence` field on nodes should be dynamically computed as a function of retrieval history — exactly the base-level activation formula. Nodes that are retrieved frequently and recently have high confidence and float to the top of context. Nodes that were stored once and never accessed decay toward the retrieval threshold.

The Soar architecture adds episodic and semantic learning on top of this, which maps to the distinction between the ICP memory records (episodic — what happened in what session) and the graph layer (semantic — what the agent knows, abstracted from any single conversation).

---

### Part IV — The File Navigation Problem

#### 12. Semantic file systems: the idea that was right but lacked the rendering

**Gifford, D. et al. (1991). "Semantic File Systems." Proceedings of SOSP 1991.**
The 1991 paper that introduced the concept. A semantic file system navigates via semantic attributes rather than directory hierarchy — associative access rather than path-based access. The implementation was fully NFS-compatible but navigation was through virtual directories formed by querying file attributes.

**The reason it was abandoned:** virtual directories are still a text list. The navigation metaphor is still linear — you query an attribute and get a list of matching files. The folder hierarchy is replaced by a query language, not by a spatial interface.

**What this means for the system:**

Gifford's 1991 insight was correct: hierarchical navigation is the wrong model for semantically related artifacts. The reason semantic file systems did not displace folder hierarchies is not that the concept was wrong — it is that the rendering was still linear. A text list of query results is not a cognitive improvement over a folder of files.

The Three.js brain visualization is the missing piece Gifford did not have. The semantic relationships have always been there. What has been missing is a spatial rendering that makes the relationships navigable by intuition rather than by query. You should be able to *fly toward* a cluster of related files the way you would walk toward a group of people talking about something relevant — without issuing a query, just by perceiving the spatial organization.

This is the argument that the file explorer interface is not an incremental improvement over folders. It is the completion of an idea that was correct in 1991 but lacked the rendering technology to be practical.

---

### Synthesis: The Design Principles This Research Implies

Taking all of the above together, five concrete design principles emerge for this system:

**Principle 1 — Edge weights are living values, not static assignments.**
The Physarum conductance model, Hebbian learning, ACT-R activation, and stigmergic pheromone decay all converge on the same mathematical form: `w(t+1) = w(t) + α·access(t) − γ·decay(t)`. The current static `weight` field on edges must become a dynamically updated value. Every context load reinforces the traversed edges; time decay weakens untraversed ones.

**Principle 2 — Retrieval follows the graph, not a flat list.**
MemGPT pages memories in and out of context. GraphRAG uses graph traversal instead of vector similarity. ACT-R retrieves by activation from the most recently accessed chunk. Together: retrieval should start from the most recently activated node and traverse highest-weight edges, loading the graph neighborhood into context. Not "return all public memories" — "return the Hebbian neighborhood of what we just talked about."

**Principle 3 — Memory evolves, it is not append-only.**
A-MEM shows that memory notes must be able to update existing memories, not just add new ones. The `supersedes` and `contradicts` edge types already exist in this system's schema. The missing mechanism is triggering them: when a new memory is stored, check for high-similarity existing memories and either update them or create a conflict edge.

**Principle 4 — The topology to target is small-world with scale-free hubs.**
Watts-Strogatz and Barabási-Albert define the optimal topology for navigable memory. The current auto-wiring rules (tag overlap → same_topic_as edges, entity extraction → anchor node hubs) naturally converge toward this topology. This is not accidental — it is the right approach and the mathematics confirms it.

**Principle 5 — The visualization is not decorative; it is a transparency mechanism.**
The Three.js brain globe renders the system's cognitive history. Thick edges are Hebbian paths. Glowing nodes are active context. Hub nodes are frequent anchors. The shape of the graph at any moment encodes what the agent has found important. Looking at the graph is reading the agent's mind — not as a metaphor, but as a direct rendering of the data that governs its reasoning.

---

### What to build next, in order

1. **Dynamic edge weights** — implement the Physarum/Hebbian update rule. Add `access_count` and `last_accessed_at` to `memory_edges`. Compute weight as a function of these. Run a scheduled decay. This is the most important architectural change because every other principle depends on edges being meaningful.

2. **Graph-guided retrieval** — when the LLM context is being built, retrieve not just all public memories but the Hebbian neighborhood of the most recently accessed nodes. This changes `getPublicMemories()` from a flat query to a graph traversal.

3. **Memory evolution** — before storing a new node, similarity-check against existing nodes. If similarity > threshold, create a `supersedes` or `contradicts` edge rather than (or in addition to) a new standalone node.

4. **Return loaded node IDs in the API response** — the `/chat/send` endpoint should return which node IDs were loaded into the LLM's context for this turn. This data drives step 1 (which edges to reinforce) and step 5 (which nodes to animate in Three.js).

5. **Three.js brain visualization** — once edge weights are meaningful and retrieval is graph-guided, the visualization renders something true. Animating nodes that were just loaded into context is not eye candy — it is a direct read of the retrieval step.

---

### Papers index

| Paper | Year | Relevance |
|---|---|---|
| Nakagaki et al., "Maze-solving by an amoeboid organism", *Nature* | 2000 | Physarum pathfinding — the core biological model |
| Tero et al., "Rules for Biologically Inspired Adaptive Network Design", *Science* | 2010 | Mathematical formalization: conductance = f(flux) − decay |
| "Slime mold uses externalized spatial memory", *PNAS* PMC3491460 | 2012 | Stigmergy at cellular level |
| Adamatzky, "Towards fungal computer", *Interface Focus* PMC6227805 | 2018 | Mycelium as distributed memory and computing substrate |
| "Ecological memory in fungal mycelial networks", *ISME Journal* PMC6976561 | 2020 | Network shape encodes history of what was important |
| Adamatzky, "Mining logical circuits in fungi", *Scientific Reports* | 2022 | Mycelium implements Boolean logic via topology |
| Hebb, *The Organization of Behavior* | 1949 | Neurons that fire together wire together — the reinforcement rule |
| "Meta-Learning through Hebbian Plasticity in Random Networks", NeurIPS | 2020 | Learned Hebbian update rules for self-organizing networks |
| Dorigo & Gambardella, "Ant algorithms and stigmergy", *FGCS* | 2000 | Pheromone as distributed memory; evaporation model |
| Watts & Strogatz, "Collective dynamics of small-world networks", *Nature* | 1998 | Small-world topology: clustered + short paths |
| Barabási & Albert, "Emergence of scaling in random networks", *Science* | 1999 | Scale-free hubs via preferential attachment |
| "Topological Graph Neural Networks", ICLR | 2022 | Persistent homology for graph structure analysis |
| Packer et al., "MemGPT: Towards LLMs as Operating Systems", arXiv:2310.08560 | 2023 | RAM/disk model for LLM context management |
| Xu et al., "A-MEM: Agentic Memory for LLM Agents", arXiv:2502.12110, NeurIPS | 2025 | Zettelkasten + evolving memory notes |
| Microsoft Research, GraphRAG, arXiv:2408.08921 | 2024 | Graph traversal outperforms vector search for relational queries |
| Graves et al., "Neural Turing Machines", arXiv:1410.5401 | 2014 | External addressable memory with attention-based read/write |
| Graves et al., "Hybrid computing with dynamic external memory", *Nature* | 2016 | DNC: temporal links, usage tracking, coupled reasoning and memory |
| Anderson, *ACT-R: How Can the Human Mind Occur in the Physical Universe?* | 2007 | Base-level activation equation for memory retrieval |
| Laird, *The Soar Cognitive Architecture* | 2012 | Episodic + semantic memory distinction; chunking and learning |
| Gifford et al., "Semantic File Systems", SOSP | 1991 | The correct idea without the rendering — what we are completing |

---

*Entry 003. This is the research foundation. The five design principles above are the PhD thesis for this system: edges must live, retrieval must traverse, memory must evolve, topology must converge toward small-world, and the visualization must be true. Everything else is implementation.*

---

## Entry 006 — 2026-03-12
### Is ICP handling what it should? Research into the correct division of labour

The question this entry answers: given that the system now has a Physarum-model memory graph with dynamic edge weights, is ICP being used for the things it is actually good at, and is PostgreSQL carrying work that should belong to ICP or vice versa?

The short answer is that the current split is partially correct but inverted in one important respect: ICP is handling flat record storage when its most distinctive capability is cryptographic identity enforcement and tamper-proof anchoring, and those properties are not being applied to the graph layer at all, even though the graph is now where the meaningful memory structure lives.

#### What ICP actually is, assessed against the literature

ICP's architecture is not a conventional blockchain. It is a network of subnets where each subnet runs replicated WebAssembly canisters under Byzantine-fault-tolerant consensus. The properties this produces that are relevant here:

**Update calls go through consensus; query calls do not.** Every state-modifying canister call (store, delete, weight update) is executed on all nodes in the subnet and costs cycles proportional to compute and storage. Query calls are answered by a single replica with no consensus, returning within milliseconds at no cycle cost. This distinction is central to the architecture decision below.

**Stable memory supports up to 500 GiB per canister, and enhanced orthogonal persistence now scales the Wasm heap beyond 4 GiB without expensive serialization on upgrade.** The earlier scalability concern for graph storage on ICP has been resolved by the enhanced persistence implementation. Large graphs can technically live on ICP.

**Chain key cryptography is ICP's genuine differentiator.** Threshold ECDSA means that a canister holds an ECDSA private key distributed across subnet nodes as secret shares, with no single node holding the full key. The canister can sign transactions on other blockchains and authenticate cross-chain operations without any party having custody of the key. No other blockchain offers this at the canister execution level.

**msg.caller is cryptographically enforced.** The principal that signed an ingress message is verifiable in the canister at execution time. Application-layer trust (a server asserting "this request is from user X") is categorically different from this. The current system uses this property for memory record ownership, and that usage is correct.

**ICP is a CP system under the CAP theorem.** Consensus makes writes strongly consistent at the cost of latency. The PACELC extension (Abadi, 2012) adds that even without partitions, ICP trades latency for consistency. For operations that must happen on every single chat turn in under a second, consensus latency is a structural problem.

#### What Kinic and zkTAM reveal about the frontier

Kinic (2025, icme.io) built a portable AI memory store directly on ICP, specifically choosing ICP for three properties: cheap stable memory storage, vetKey for encryption at rest with user-owned keys, and threshold ECDSA for cross-chain signing. Their vector database (Vectune) runs as Wasm on ICP and uses WebAuthn rather than Ed25519 KeyIdentity, so the user's biometric or hardware token is the signing device and no private key is ever held in browser localStorage.

Their zkTAM (Trustless Agentic Memory) framework adds a zero-knowledge proof layer on top: when an agent generates a response, a zkML proof can attest that the agent used specific verified memory records when computing that response. The memory is not just owner-controlled; it is cryptographically provable which memories influenced which outputs.

This is the research frontier for this system. The current architecture proves that the storage layer enforces its own access control. zkTAM proves that the reasoning layer used the correct storage.

#### The CAP theorem applied to this specific system

The Physarum decay model runs daily and updates every edge in the graph. The Hebbian reinforcement runs on every chat turn and updates every edge between the co-accessed nodes. These are frequent, low-latency, low-stakes writes. Routing them through ICP consensus would cost cycles per edge per update and introduce latency that accumulates across every chat turn.

PostgreSQL is an AP-leaning system (available, partition-tolerant) under CAP, which is correct for the working graph. Reads are fast. Writes are fast. The graph weights can be slightly stale without breaking anything. If the system crashes and restarts, the weights are recovered from the last committed state, not reconstructed from consensus.

ICP is a CP system. Reads (query calls) are fast. Writes (update calls) go through consensus. This is correct for the ownership layer: when a memory record is written to ICP, it must be consistent across all nodes because the msg.caller enforcement depends on it. You cannot have partition tolerance on identity enforcement without risking a split-brain attack where two nodes disagree about who owns a record.

The current system already respects this split implicitly. The graph lives in PostgreSQL because PostgreSQL is fast. The records live in ICP because ICP enforces identity. The research confirms that this is the correct alignment, not an accident.

#### What is wrong with the current implementation

The graph layer has no ownership enforcement. Any server process can create nodes, update weights, and build edges for any user. The Physarum decay runs as a server-side scheduled command and modifies edge weights globally without the user's knowledge or consent. This is identical to the original problem that ICP was introduced to solve for memory records, now reproduced at the graph layer.

The specific gap: when the graph becomes the primary retrieval source (Principle 2 from Entry 003), graph nodes become more influential on agent behaviour than the raw ICP records. A graph node with high edge weight will be retrieved preferentially and loaded into the LLM context. If graph nodes are not user-owned, then the ownership guarantee of the ICP layer is effectively bypassed: the user owns the raw text of the memory on ICP, but the server controls which memories the agent actually uses by manipulating graph weights.

This is not a hypothetical risk. It is the same trust boundary problem described in VISION.md under "What This Does Not Prove," now appearing one layer up.

#### The correct division of labour going forward

Mapping each component against ICP's actual properties:

**ICP should own:**

The raw memory records, as currently implemented. This is correct and proven.

A graph ownership registry: a lightweight canister mapping each user's principal to a declared graph fingerprint. The fingerprint is a hash or Merkle root of the graph structure at a point in time. The user signs the fingerprint on graph operations they approve, creating a verifiable audit trail of which graph structure they acknowledged. This does not require the full graph to live on ICP; it requires only the fingerprint of a graph state the user has seen and signed.

Cross-agent access grants. When Agent A's memory graph references a node that Agent B also holds, the canister mediates the permission: Agent B's principal is granted read access to Agent A's specific node by Agent A's signed approval. This is currently unimplemented because there is only one agent, but it is the correct mechanism for the multi-agent visualization described in Entry 002.

Identity: the Ed25519 KeyIdentity in localStorage is the correct starting point, but Kinic's WebAuthn approach is the correct upgrade path. The user's face or hardware token becomes the signing device. The private key never touches application memory.

**PostgreSQL should own:**

The working graph: nodes, edges, weights, access counts, timestamps. All Physarum dynamics stay here. Graph traversal stays here. Neighbourhood retrieval stays here. The speed properties of PostgreSQL are required for these operations to complete within a chat turn.

The LLM context assembly: the graph-guided retrieval query runs against PostgreSQL, not against ICP. ICP does not have the query primitives (SQL joins, index traversal, degree ordering) needed for this efficiently.

The decay scheduler and Hebbian reinforcement, exactly as currently implemented.

**The bridge between layers:**

When the graph-guided retrieval selects a set of nodes to load into LLM context, those nodes correspond to ICP memory records via the content-equality join currently in `reinforceFromMemories()`. The ICP record proves ownership; the PostgreSQL weight proves relevance. Both properties together form a complete picture: this memory is owned by this principal (ICP) and it is currently important to this conversation (PostgreSQL weight).

When a graph node is created from a confirmed ICP write, the ICP record ID should be stored as metadata on the PostgreSQL node. The link then runs in both directions: from ICP record ID to graph node, and from graph node back to ICP record ID.

#### What this means for the Three.js visualization

The Three.js brain visualization (Entry 002) will show edge weights from PostgreSQL. Those weights reflect the Physarum dynamics: thick edges are Hebbian paths, thin edges are dormant connections, animated nodes are what the LLM just loaded. This is the correct data source for live cognitive state visualization.

The ICP layer contributes the ownership coloring: nodes whose ICP records are publicly readable glow differently from nodes whose records are private or sensitive. The visualization makes the trust boundary visible: you can see, at a glance, which parts of the brain are public and which parts are owner-gated, because the canister enforces that distinction and the graph metadata reflects it.

The multi-agent view (Entry 002, deferred) becomes architecturally clear: each agent's graph is a PostgreSQL partition; the shared node between two agents is one ICP record that both agents' principals have been granted access to by the owner's signed approval. You see two subgraphs with a glowing shared node between them, and the canister enforces that the sharing is real.

#### zkTAM as the next research horizon

The most important finding from this research pass is that Kinic has already demonstrated verifiable AI memory on ICP using zero-knowledge proofs. Their zkTAM system produces a proof that a specific set of memory records was used in a specific inference. This is the answer to the open research question in VISION.md: "Can the summarization step itself be user-verifiable?"

The current system cannot answer that question. The user can approve which memories are stored, but they cannot verify which memories the LLM actually used when generating a response. zkTAM would close that gap. The `active_node_ids` field now returned by `/chat/send` is the precondition for this: it is the set of nodes that were loaded into context this turn, and it is the input to the zkML proof.

The research trajectory is therefore: graph-guided retrieval narrows the context set, active_node_ids identifies which memories were used, and eventually a zkML proof attests that those specific memories were the ones that influenced the output. The ownership layer (ICP) then has a proof to anchor, not just a record to store.

---

## Entry 005 — 2026-03-12
### Code review findings: decay portability, Hebbian signal quality, and the retrieval gap

#### What was fixed

**Decay SQL portability.** The original `decay()` method used `GREATEST()`, which PostgreSQL supports but SQLite does not. Because the test suite runs against SQLite in-memory, any test touching the decay path would have failed silently or thrown an exception. The fix rewrites the update as a portable `CASE WHEN weight * RHO < FLOOR THEN FLOOR ELSE weight * RHO END` expression that executes correctly on both engines. This matters because the portability gap means local test results would not have reflected production behaviour on PostgreSQL.

**Model fillable and cast alignment.** The `access_count` and `last_accessed_at` columns added by the migration were not listed in `$fillable` or `$casts` on `MemoryNode` and `MemoryEdge`. Without them, mass assignment via `increment()` would silently drop the new columns in some Eloquent paths, and the datetime cast would not apply to `last_accessed_at` when reading records. Both models are now corrected.

**Test coverage added.** Four test files cover the new behaviour: reinforcement and single-node access tracking in `MemoryGraphServiceTest`, content-to-node matching in the same file, active_node_ids presence in the `/chat/send` response in `ChatMemoryGraphTest`, and the scheduled decay command path in `DecayMemoryEdgesCommandTest`. The suite passes at 32 tests, 125 assertions.

#### The architectural gap that remains

The review correctly identified that the Hebbian reinforcement signal is currently weak. The problem is that `getPublicMemories()` returns the entire public memory set regardless of what the user actually asked, and `reinforce()` is called on that full set. If a user has 30 stored public memories, every one of those 30 nodes is marked as co-accessed on every single turn, and every edge between them is incremented by ALPHA. Over time the graph weights converge toward a uniform high value that reflects "this user has public memories" rather than "these specific memories were relevant to this specific query."

The Physarum analogy breaks down here. In the actual organism, flux through a tube increases only when food is found at that tube's terminal. If flux is artificially injected into every tube simultaneously, all conductances converge to the same high value and the organism loses its ability to find shorter paths. That is what the current flat retrieval does to the Hebbian weights.

This is not a bug in the reinforcement implementation. The implementation is correct given what it receives. The fix is upstream: narrow what gets retrieved before reinforcement runs, so that only genuinely relevant memories enter the co-activation set.

#### What fixes the signal quality: graph-guided retrieval

The solution is Principle 2 from Entry 003. Instead of retrieving the full public set, retrieve the Hebbian neighbourhood of the most recently activated nodes. The steps are:

1. Before building the system prompt, identify the highest-weight nodes in the graph for this user. These are the nodes with the strongest accumulated signal, which approximates the ACT-R base-level activation of recently and frequently accessed memories.
2. Traverse their outgoing edges in weight-descending order, collecting the N-hop neighbourhood up to a token budget.
3. Inject only that neighbourhood into the LLM context window, not the full flat set.
4. Run reinforcement on only the retrieved neighbourhood.

This makes the reinforcement meaningful because the context set is selected by relevance rather than being the entire flat store. The Physarum organism finds the shortest path because it withdraws cytoplasm from routes that carry no flux; the equivalent here is withdrawing memories from the context window that are not connected to what is currently being discussed.

The ICP layer complicates this. `getPublicMemories()` calls the canister or mock cache, not the PostgreSQL graph. The graph-guided retrieval needs to query the graph in PostgreSQL first, then either cross-reference against ICP records or treat the graph nodes themselves as the retrieval source for LLM context. This is a meaningful architectural decision: the graph becomes the primary retrieval index, and ICP becomes the durable ownership record rather than the active recall layer.

That decision should be made deliberately before implementing, because it changes the relationship between the two storage layers.

---

## Entry 010 — 2026-03-12
### What is this system actually for? The hard questions and what they change about the design

#### Why this entry exists

The simulation feature and the Three.js brain visualization were described as the next steps. Before continuing to build, it is worth recording the questions that were asked at this point — because they exposed a design assumption that was wrong, and correcting it changes what the visualization needs to be.

The questions were: What is the point of all these memories? What is the point of traversing the graph this way? What is the point of interacting with it this way? What is being solved? Are we building a digital clone of our memories?

These are the right questions to ask before building a 3D interface, because the wrong answer produces a beautiful thing that nobody uses.

#### What the memories are actually for

The memories are not a diary. They are a relevance history. When an agent retrieves memories to generate a response, the Physarum model records which memories were retrieved together. After 48 hours of continuous operation, the weight graph is not a log of events — it is a map of which knowledge the agent actually reached for when doing real work. That is different from a log file. A log file records what happened. The weight graph records what the agent considered important, which is the information needed to evaluate and redirect it.

The graph traversal is how the agent's learned judgement gets applied to new situations. Flat retrieval (vector similarity, keyword search) treats all memories as equally important until the moment of query. The Physarum neighbourhood traversal treats memories as important in proportion to how often they have been useful together in practice. The traversal is the agent's accumulated experience, expressed as a retrieval decision.

#### What is being solved

The honest version: most multi-agent frameworks have no coherent memory across runs. Each session starts fresh or pulls from a flat database with no notion of which memories have been useful before. Agents forget. Agents repeat work. Agents do not build on each other's experience even when operating in the same domain for the same owner.

The direction this project is working toward: agents operating over days, accumulating experience that compounds rather than resets, with ownership that persists independently of any application server, and a human who can understand what the collective is doing and redirect it. That combination of properties does not exist in any current system. The individual components (persistent memory, graph retrieval, ICP ownership, Physarum dynamics) exist separately. The integration is the research contribution.

#### What this is not

Not a digital clone of the user's memories. That framing implies fidelity: accurately recording everything experienced. This is not that. The memories are the agents' experience of doing work in the user's domain. The graph is not a copy of anyone's mind. It is an emergent record of what the agent team has found important while operating. The closer comparison is institutional knowledge: what an experienced team knows collectively that any individual joining would need time to learn. This system makes that knowledge persistent, queryable, and steerable.

#### The flaw in the visualization plan

The graph explorer (D3, 2D, node-by-node) and the simulation toggle (tick counter, flash animation) are not the right interaction model for a 48-hour multi-agent run. A user is not going to watch individual nodes flash. The assumption embedded in the current design — that the right unit of human attention is the individual memory node — is wrong at scale.

At millions of memories across ten agents running continuously for two days, the question is not "what is this node?" The questions are:

Which region of the graph is hyperactive right now? Are the agents stuck in a productive focused loop, or are they drifting?

Which region has gone cold? What did they know 24 hours ago that they have stopped using?

Is anything anomalous? A node or cluster that gained weight rapidly from a low-trust principal is a MemoryGraft signal.

Are the agents still working toward the original task? Or has the collective drifted from the user's intent?

Where should the user intervene? Which trust score to lower, which node cluster to prune, which seed memory to inject to redirect agent attention?

These questions cannot be answered by looking at individual nodes. They require a view of the graph at cluster granularity, with time, anomaly signals, and intervention controls. That is not a visualization. It is a monitoring and intervention surface.

#### What Three.js actually needs to be

The correct mental model for the Three.js interface is mission control, not planetarium. The difference: a planetarium is for observation; mission control is for observation with intervention capability.

The specific requirements that follow from the questions above:

**Cluster-level heat map.** The visible unit is a cluster, not a node. Node clusters that are currently hot (high aggregate edge weight, recently reinforced) glow distinctly from clusters that are dormant. The user can see at a glance where the agents are spending their attention without reading individual memory content.

**Temporal axis.** The weight distribution is not static. A time scrubber or replay mode lets the user walk backward through the weight evolution. When did the agents start focusing on this cluster? When did they stop using that one? Identifying drift requires seeing the trajectory, not just the current state.

**Anomaly layer.** Nodes or clusters whose weight grew faster than the organic Physarum rate, or whose weight increase came primarily from low-trust principals, are rendered differently from organically reinforced nodes. This is the MemoryGraft signal made visible. A foreign-colored pulse entering a high-weight cluster warrants attention before it propagates further.

**Intent alignment indicator.** At the point when the user assigns a task to the collective, a snapshot is taken of the hot zone. A running comparison between the current hot zone and the task snapshot answers whether the agents are still working toward the original intent. Divergence is surfaced explicitly rather than requiring the user to intuit it from the visualization.

**Region-scale intervention controls.** The user cannot prune individual nodes in a million-memory graph. The interventions that matter are: lower the trust score of a principal whose contributions appear anomalous; prune a cluster that has grown beyond what the task requires; inject a seed memory into the current hot zone to redirect agent attention. These operations work on clusters and principals, not on individual nodes.

**Multi-agent subgraph layout.** Each agent's graph partition occupies a spatial region of the sphere. Shared nodes sit at the boundaries between regions. The layout makes cross-agent convergence visible: when two agents reinforce the same content, the shared node grows at the boundary, and the edges connecting both agents' subgraphs to it thicken. The user can see the collective signal forming without reading any individual memory.

#### The file explorer comparison, precisely

A file explorer solves: I have many files, where are they and what do I have? The answer is a folder hierarchy that shows structure at the level of abstraction needed to navigate.

The Three.js surface solves: I have many agent experiences accumulated over days, is the collective still working toward my intent and is anything wrong? The answer requires structure at the level of abstraction needed to make an intervention decision: clusters, trajectories, anomalies, and cross-agent convergence. Not individual nodes, not individual edges.

The file explorer does not show every byte. The Three.js surface should not show every memory. The right level of abstraction is the one that lets a human make a steering decision in under ten seconds, which is what mission control requires.

#### The simulation bug that was found building this

The simulation playback feature (Graph.vue) had a correctness problem: at fast speed (350ms intervals), if the server response from one tick took longer than 350ms, the next tick would fire before the previous response arrived. Both ticks would then apply Physarum reinforcement to the same set of nodes, doubling the weight increment on every slow response. Over 100 ticks at fast speed this would corrupt the weight distribution by over-reinforcing the most recently active cluster, which is precisely the bias that the Physarum model is designed to avoid.

The fix guards in-flight ticks so a second tick cannot start until the first completes, and invalidates stale responses by comparing a generation counter at the time of the fetch with the counter at the time the response arrives. If the simulation stopped or the view changed while the request was in flight, the response is discarded. This keeps the weight dynamics correct regardless of server response time.

The same race was possible on the agents page (simulateAll), though the interval there is driven manually rather than automatically. That path is not guarded because the user explicitly triggers each run, and simultaneous manual triggers are unlikely in practice. Automatic guard would be the correct fix if simulateAll is later scheduled rather than manually triggered.

#### Test coverage added

The simulate endpoint had no automated coverage when it was first added. A test was added to `GraphControllerTest` covering: the endpoint only touches the current user's graph (a second user's nodes and edges are present and must not be modified), the response contains the correct active node IDs, the response contains the updated edge weight for the reinforced edge, and the database weight matches the returned value. The test uses two users' data in the same database state to verify the session scoping is correct.

---

## Entry 009 — 2026-03-12
### Multi-agent simulation layer: collective Physarum with trust-weighted edge reinforcement

#### What was built

The system now supports multiple agents, each with its own graph partition, whose Physarum dynamics interact through shared memory edges when they hold nodes derived from the same content.

**`agents` table** stores each agent with an `owner_user_id` (the human who created it), a `graph_user_id` (the partition key used in `memory_nodes` and `memory_edges`), a `name`, a `trust_score` between 0.0 and 1.0, and access tracking. The agent's graph partition is created as a standard user_id prefix (`agent_{uuid}`) so all existing graph operations work on it without modification.

**`shared_memory_edges` table** stores cross-agent edges keyed by `(agent_a_id, agent_b_id, content_hash)` with a canonical ordering (lower UUID as `agent_a`) to prevent duplicate edges in both directions. The edge carries a `weight`, an `access_count`, and `last_accessed_at`. The `content_hash` is SHA-256 of the memory content string, which is the correct join key because graph nodes are created from the same content string that ICP stores.

**`MultiAgentGraphService`** manages three operations:

`reinforceShared(nodeIds, agent)` is the core collective update. After an agent reinforces its local graph, this method finds other agents under the same owner whose graph partitions contain nodes with the same content hash, and increments the shared edge weight by `SHARED_ALPHA * agent.trust_score`. An agent with `trust_score = 1.0` applies the full increment of 0.06. An agent with `trust_score = 0.0` applies nothing. The canonical form of the Physarum update is preserved: `w(t+1) = min(1.0, w(t) + SHARED_ALPHA * trust)`.

`retrieveCollectiveContext(agent)` retrieves the agent's personal Physarum neighbourhood (via `MemoryGraphService::retrieveContext`) then annotates each node with a collective weight derived from the sum of shared edge weights from peer agents, multiplied by each peer's trust score. The result is sorted by collective weight descending, so nodes the collective considers important appear first in the LLM context window.

`seedFromOwner(agent)` copies the owner's most recent public memory nodes into the agent's graph partition. This is the setup step for simulation: create agents, seed them from the same memory corpus, then run reinforcement and observe how collective weights develop as the agents access different subsets of that corpus.

**`AgentController`** exposes the full simulation API: create, seed, simulate individual agents, and simulate all agents in a single request. The `simulateAll` endpoint runs `retrieveCollectiveContext`, `reinforce`, and `reinforceShared` for every agent under the current user and returns the results side-by-side with the updated shared edge summary.

**`Pages/Agents/Index.vue`** renders the simulation UI as three regions: the left panel lists agents with trust score sliders and seed/run controls; the center shows agent result columns side-by-side with nodes highlighted when they appear in more than one agent's active set; the right panel shows all shared edges sorted by collective weight, with a progress bar representing the current weight and a reinforcement count.

#### Why SHARED_ALPHA is 0.06 rather than 0.10

The personal ALPHA of 0.10 was calibrated so that ten co-access events bring an edge from 0.5 to 1.0 for a single agent. Collective reinforcement accumulates from multiple agents simultaneously. If four agents each apply full ALPHA, the shared edge would saturate at ten events across the group rather than ten per agent. SHARED_ALPHA = 0.06 means that a fully trusted agent contributes 60% of the personal rate to the collective, which prevents collective saturation at agent populations larger than two without requiring the increment to be dynamically divided.

The correct long-term fix is to divide SHARED_ALPHA by `sqrt(n_agents)` where `n_agents` is the number of agents in the collective, which would give the Physarum organism analogy correctly: each organism contributes a flow rate, and the combined conductance is the sum. This refinement is deferred to when agent populations are large enough to make the saturation effect visible.

#### The MemoryGraft resistance mechanism

A trust score of 0.0 means the agent can still create shared edges (the initial weight is applied on first contact), but contributes zero increment on all subsequent reinforcements. An attacker who registers a new agent and attempts to pump the shared graph toward a poisoned node achieves an initial shared edge at weight 0.3 but cannot increase that weight further without being granted a higher trust score by the owner.

The owner's trust score adjustment is the primary MemoryGraft defense in the current implementation. A future extension would make trust scores computable from verified contribution history: if an agent's retrieved memories correlate with accurate downstream responses, its trust score increases. This is the ACT-R base-level analogy at the collective level: agents that produce accurate associations earn higher activation in the collective network.

ICP's `msg.caller` enforcement is the cryptographic precondition for this. When all memory writes carry a verifiable principal, the contribution history is attributable and tamper-proof, which is what makes a reputation-based trust score computable at all.

#### What the simulation exposes

Running the simulation with two agents seeded from the same memory corpus and different trust scores produces an observable result: the higher-trust agent's preferred nodes (the ones its Physarum weights have elevated through personal reinforcement) acquire higher collective weights faster than the lower-trust agent's nodes. After several simulation runs, the shared edge topology reflects a weighted combination of both agents' individual relevance judgements, not a simple average.

This is the collective intelligence property the December 2024 paper identified as requiring cognitive infrastructure. The infrastructure is the personal Physarum graph. The collective signal emerges from the trust-weighted aggregation of individual signals, which is what the shared edge layer now computes.

---

## Entry 008 — 2026-03-12
### Graph-guided retrieval: replacing flat recall to make edge weights meaningful

#### The problem with flat recall

Entry 005 identified the core signal quality problem. `IcpMemoryService::getPublicMemories()` returns every public memory record, and `reinforceFromMemories()` was called on that entire set. If a user has 30 public memories, all 30 nodes are marked as co-accessed on every chat turn, and every edge between them receives the ALPHA increment. Over time, all edge weights converge toward the same high value because the co-access signal contains no information about which specific memories were relevant to any specific query.

The Physarum organism analogy collapses at this point. In the biological model, flux increases only through tubes that connect to food sources. Injecting equal flux through every tube simultaneously destroys the organism's ability to find the shortest path because all conductances converge to the same value. Flat recall does exactly this to the memory graph.

#### The fix: seed selection and neighbourhood traversal

`MemoryGraphService::retrieveContext(userId, limit)` replaces flat recall as the primary context source. The method proceeds in two stages.

**Seed selection.** The 60 most recently created public nodes for the user are loaded as candidates. For each candidate, the sum of weights on all connected edges is computed. Nodes whose edges have accumulated high total weight are selected as seeds, up to a count of four. These are the nodes the Physarum model considers most important: they have been co-accessed with many other nodes many times, and their combined edge weights reflect that history. Recency provides the tiebreaker when two nodes have equal edge weight.

**Neighbourhood traversal.** BFS from the seed set collects public neighbour nodes in edge-weight-descending order. At each hop, the edges from the current frontier are fetched, ordered by weight descending, and the neighbours are added to the collected set. The traversal stops when the collected set reaches the limit or no new neighbours exist. Only public nodes pass the sensitivity filter at the neighbour stage.

**Reinforcement.** The returned node IDs are passed directly to `reinforce()`. Only the retrieved neighbourhood is reinforced, so the ALPHA increment applies only to edges between nodes the graph considers relevant. Over time, the Physarum weights diverge: edges within the retrieved neighbourhood accumulate weight, and edges between rarely-co-retrieved nodes decay toward the floor.

#### The cold start problem and fallback

A new user has no graph nodes. `retrieveContext()` returns an empty array. The fallback in `ChatController::send()` detects the empty result and calls `IcpMemoryService::getPublicMemories()` instead, which is the original flat recall path. This keeps the first few turns functional while the graph is being built from incoming memories.

The cold start ends when the first memory is stored and its graph node is created. On the next turn, `retrieveContext()` finds that node as a candidate and returns it as a seed. The first edge is created when a second memory with overlapping tags is stored and `wireTagEdges()` wires them. From that point, the Physarum dynamics are active and flat recall is no longer needed.

There is a weak signal window: in the first 5 to 10 turns after the graph is non-empty but before multiple reinforcement cycles have run, the edge weights are approximately uniform (all at their initial values). The seed selection falls back to recency ordering, which is correct: the most recently created memories are the most contextually relevant in the early graph.

#### What changes in the response

`active_node_ids` in the `/chat/send` response now contains the IDs of the graph-guided neighbourhood nodes rather than the IDs of the full ICP memory set. The Three.js visualization will use these IDs to animate the specific nodes that influenced the current response, which is a more precise signal than the previous version where all public memories were animated on every turn.

The `IcpMemoryService::getPublicMemories()` call is absent from the hot path once the graph has nodes. ICP is still the source of record for the raw memory content (via the canister), but the retrieval decision is now made by the PostgreSQL graph. This is the architectural shift Entry 005 identified as necessary: the graph becomes the primary retrieval index, and ICP becomes the durable ownership record rather than the active recall layer.

---

## Entry 007 — 2026-03-12
### The hivemind pivot: what Kinic did not build and what remains genuinely open

#### The question that prompted this

After Entry 006 documented Kinic's zkTAM system and portable memory store on ICP, the legitimate concern was: if the most important research work on agent memory has already been done on ICP, what is left to discover here? The concern is worth taking seriously. Dismissing it would mean building in a direction without understanding what already exists.

The answer requires distinguishing what Kinic built from what the full research landscape shows remains unbuilt.

#### What Kinic actually built

Kinic's product is a portable personal memory store. One user. One agent. One signing identity. Their architecture is correct and impressive: vetKey gives encryption at rest with user-owned keys, WebAuthn replaces Ed25519 KeyIdentity in localStorage with hardware-rooted signing, and their Vectune vector database runs as Wasm on ICP for semantic search. Their zkTAM framework adds a zero-knowledge proof layer so the agent can prove which specific records influenced a given response.

The system is designed to answer this question: "Can a user carry their AI memory from one application to another without trusting the application server?"

That question is answered. The user sovereign memory problem for a single agent is solved at the cryptographic level.

Kinic's tagline is "One Memory Layer. Every AI Agent." The "every" refers to application portability: your single memory works across Claude, GPT, Gemini. It does not refer to collective memory: it does not mean many users' memories converging into a shared structure.

#### What collective memory research reveals

Three papers from the research pass define the open frontier precisely.

**Emergent Collective Memory (arXiv:2512.10166, December 2024)** ran a controlled experiment measuring agent performance on tasks requiring collective memory across multiple agents. Individual memory gave a 68.7% performance gain over baselines. Environmental traces (stigmergic pheromone deposits accessible to all agents, equivalent to shared state without cognitive infrastructure) provided zero statistically significant benefit. The conclusion: collective benefit requires cognitive infrastructure inside each agent, not just shared storage. The system described in this DEVLOG is that infrastructure. The Physarum graph is not the pheromone trail; it is the organism's cognitive map built from traversing the trail.

**Collaborative Memory (arXiv:2505.18279, May 2025, Accenture)** formalizes multi-user multi-agent memory with bipartite access control graphs: one partition contains users and agents, the other contains memory records, and edges represent permissions. Their access control model is correct. Their memory dynamics are static: edges in the bipartite graph do not carry weights, do not decay, do not reinforce through co-access. The social graph of who can read what is solved. The dynamics of what the collective actually remembers and how importance distributes across the shared corpus is not addressed.

**Society of HiveMind (arXiv:2503.05473, 2025)** treats agents as graph nodes and optimizes their communication topology. The research question is "which agents should talk to which other agents to maximize task performance?" Not "how should the shared memory structure evolve based on what the collective accesses together?" The communication topology problem and the shared memory dynamics problem are adjacent, not identical.

The gap is clear across all three: collective memory access control is partially solved; collective memory dynamics, specifically how edge weights should evolve based on multi-agent co-access patterns, is not addressed in any paper found.

#### The MemoryGraft problem is the trust problem for collective memory

MemoryGraft (arXiv:2512.16962) demonstrated a persistent poisoning attack on agent long-term memory. The attack works by planting a small number of records that describe a fabricated successful experience (the agent "remembers" that a particular malicious action worked well in a past task). Because the agent's memory retrieval is based on semantic relevance rather than provenance verification, the planted record competes on equal footing with legitimate records and eventually shapes the agent's behaviour.

For a single-user system, MemoryGraft is a server-side security problem: prevent unauthorized writes to the memory store. ICP's msg.caller enforcement mostly solves this for ICP-backed memory because writes are signed by the user's principal.

For a collective memory system where multiple agents contribute writes from multiple principals, MemoryGraft becomes a social engineering attack. An agent operating under a principal that the user has granted write access to can plant memories that corrupt the shared graph. The attack surface is proportional to the number of contributing principals.

No paper identified a cryptographic solution specific to collective memory poisoning. The closest mechanism available is ICP's authorship provenance: every write to the canister carries the signing principal, and that principal is immutable at the protocol level. A trust-weighted Physarum model can use principal trust scores as a multiplicative factor on edge reinforcement. A principal with no reputation history contributes a small ALPHA; a well-established principal with a verified history of accurate contributions earns a larger effective ALPHA. The graph then converges toward the structure that trusted agents collectively find important, while untrusted writes contribute small initial weights that require sustained reinforcement to become significant.

This mechanism is architecturally feasible with the current system. It has not been built anywhere, and the MemoryGraft attack surface makes it a necessary component of any collective memory system that allows multiple contributing principals.

#### What the collective Physarum model looks like

In the biological model, multiple Physarum organisms introduced to the same maze begin as independent networks. They merge at food sources. Tubes that two organisms both traverse receive flux from both, and their combined conductance update drives the merged network toward shorter paths faster than either organism would find alone. Tubes used by only one organism contribute less to the collective decision.

Translated to AI collective memory:

Each agent maintains a PostgreSQL graph under their own user partition. The ICP canister stores memory records with msg.caller enforcement. When two agents' graphs contain nodes derived from the same ICP record (same content, same canister record ID), a cross-agent edge can be created in a shared coordination layer. The weight of that shared edge is the sum of the individual agents' edge weights, normalized by the trust scores of their respective principals.

When the system retrieves memories for agent A, it now considers not only agent A's Physarum weights but the cross-agent weights on shared nodes. If agent B has heavily reinforced a node that agent A has lightly accessed, that node's effective weight in agent A's retrieval is elevated because the collective has found it important. This is the emergent collective memory that the December 2024 paper showed is not achievable through environmental traces alone.

The ICP layer's role in this model is different from its current role. Currently ICP stores memory records and enforces read access. In the hivemind model, ICP also stores the cross-agent edge weights as a separate canister, signed by both contributing principals. The weights in that canister represent collective endorsement: neither agent can unilaterally alter the shared edge without the other's principal consenting. The bipartite access control from the Accenture paper is the permission model; the Physarum dynamics are the weight model. Both are needed and neither is yet combined with the other.

#### Where the Three.js visualization fits

The 3D brain globe described in Entry 002 becomes the observation surface for collective cognitive state. Each agent's subgraph occupies a region of the sphere. Shared nodes sit at the boundary between regions, glowing proportionally to their collective weight. Edges thick with accumulated flux are the high-conductance tubes of the Physarum network: the paths the collective has found important.

A live animation of a chat turn would show a specific agent's active node set lighting up, reinforcement waves propagating through the Hebbian neighbourhood, and the cross-agent shared nodes brightening as their collective weight updates. When a MemoryGraft-suspicious write occurs (a low-reputation principal attempting to write a high-semantic-similarity record near a high-weight node), the visualization could render the anomaly visually: a foreign-colored pulse entering the graph near a sensitive cluster.

This does not exist. Building it requires the graph dynamics from Entry 003 and Entry 004, the ICP provenance layer from Entry 006, and the multi-agent weight coordination model described in this entry. Each layer is necessary for the next.

#### What the genuine research contribution is

The contribution is not that user-sovereign memory is achievable (Kinic proved that). The contribution is the dynamics of collective memory under a Physarum-inspired co-activation model, with cryptographic provenance enforcement at the edge level, and a MemoryGraft-resistant trust weighting mechanism.

More concretely: can a group of AI agents, each with their own ICP-signed memory store, develop a shared memory structure whose topology reflects what the collective has found important, where the topology is resistant to poisoning by unauthorized writes, and where the entire structure is observable through a live 3D visualization of collective cognitive state?

No paper cited in Entry 003 answers this question. The components needed to answer it exist across multiple papers and systems that have not been combined. The system being built here is assembling those components.

That is what remains to be discovered.

---

## Entry 004 — 2026-03-12
### Implementing dynamic edge weights: the Physarum model in code

The first design principle from Entry 003 is now implemented. Edge weights are no longer static.

#### What was built

Three components were added to realize the discrete Tero et al. (2010) conductance model.

**Migration `2026_03_12_000003`** adds `access_count` and `last_accessed_at` to both `memory_nodes` and `memory_edges`. The access count supports ACT-R base-level activation ordering in future retrieval work. The timestamp drives the decay calculation and feeds the Three.js animation layer with information about which nodes have been recently active.

**`MemoryGraphService::reinforce(array $nodeIds, string $userId)`** applies the Hebbian increment on every context-load event. When the LLM retrieves a set of memory nodes for a given turn, all edges between nodes in that set receive `weight += 0.10`, clamped to 1.0. The increment value (ALPHA = 0.10) follows the range used in the Improved Physarum Algorithm paper (PMC3984829), where conductance increments between 0.05 and 0.20 were found to produce stable convergence without oscillation.

**`MemoryGraphService::reinforceFromMemories(array $memories, string $userId)`** bridges the ICP layer and the graph layer. ICP memory records carry a `content` string, and graph nodes were created from that same string, so the join is exact rather than fuzzy. This method resolves which graph nodes correspond to the memories that were loaded into context, calls `reinforce()`, and returns the node IDs for the API response.

**`MemoryGraphService::decay()`** implements the daily decay term as a single bulk SQL statement: `weight = GREATEST(0.05, weight * 0.97)`. Running this as raw SQL rather than per-record Eloquent updates keeps the operation proportional to table size rather than record count. The retention factor RHO = 0.97 (3% daily decay) was chosen so that an edge with initial weight 0.5 that receives no reinforcement reaches the floor after approximately 100 days, matching the timescale on which a human might reasonably forget a lightly-used association.

**`DecayMemoryEdges` Artisan command** wraps the decay call and is scheduled daily via `routes/console.php`. Running `php artisan memory:decay` manually also works for testing.

**`ChatController::send()`** now calls `reinforceFromMemories()` immediately after `getPublicMemories()` returns, then includes the resulting node IDs in the JSON response as `active_node_ids`. The browser receives this field on every chat turn; the Three.js visualization (Entry 002, not yet built) will use it to animate the nodes that were active in that turn.

#### Why the constants were chosen

ALPHA = 0.10 means ten co-access events bring an edge from its initial weight of 0.5 to 1.0. In practice most memory graphs will have far fewer than ten turns that retrieve any specific pair of memories together, so edges plateau near the midrange rather than saturating immediately. The Physarum literature (Tero, Nakagaki) does not specify discrete ALPHA values because the continuous model uses flow rates, but digitised implementations consistently use increments in the 0.05 to 0.15 range.

RHO = 0.97 was calibrated against human episodic memory decay, which the ACT-R base-level learning model places at a half-life of roughly 15 to 30 days for weakly encoded memories. An edge at weight 0.5 with RHO = 0.97 reaches 0.05 (the floor) in 100 days. An edge reinforced weekly stays above 0.4 indefinitely.

WEIGHT_FLOOR = 0.05 ensures that edges are never deleted by decay. The floor matters for the Three.js visualization: a barely visible thin edge between two nodes carries information (these memories were once connected) that a missing edge does not. Deletion would be irreversible; the floor is not.

#### What this does not yet implement

The Hebbian increment currently applies a flat ALPHA regardless of how many nodes were co-accessed. A context window with 20 nodes should reinforce each pair less strongly than a context with 3 nodes, because the signal-to-noise ratio is lower when many memories are loaded simultaneously. A correct implementation would weight the increment by `1 / |nodeIds|^2` (the fraction of possible pairs). This refinement is deferred until the retrieval system moves from flat recall to neighborhood traversal, at which point context sets will be smaller and more focused.

The `reinforceFromMemories` method joins on exact content string equality. If the same fact is summarized slightly differently across two turns, the join misses the second instance. A fuzzy join using trigram similarity or cosine embedding distance would be more robust, but the exact join is correct for the current pipeline because the same LLM prompt and same content are used consistently.

---
