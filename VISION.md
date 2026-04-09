# OpenMemory: Research Vision

*What does user-sovereign AI memory actually look like when you try to build it?*

This document is the research record for OpenMemory. It is not a setup guide (see README.md) and not a feature list. It captures the design questions driving the project, what was actually learned building it, what the implementation honestly proves, and where the hard problems remain.

For the running implementation log (what was discovered building specific features, security findings, and what remains unresolved) see [DEVLOG.md](./DEVLOG.md). For the active research agenda (open scientific claims, what needs to be built to test each one, and how tracks evolve) see [RESEARCH.md](./RESEARCH.md). VISION.md is the stable research position; DEVLOG.md is the honest record of how it got there; RESEARCH.md is the living frontier.

---

## Where This Came From

The project started as a personal workflow problem. Working across Claude, Codex, Gemini CLI, and other AI tools throughout the day means re-explaining project context from scratch every time you switch. Each AI starts fresh at every session boundary. The context you built with one tool does not carry to the next. The accumulated understanding of your project, your preferences, your current constraints, all of it resets at the tool boundary.

The obvious fix is to store memory somewhere central and let all the tools read from it. The problem is that "somewhere central" in the current ecosystem means a vendor's database. Putting your memory in a central vendor store swaps one dependency for another. The goal is memory infrastructure that is central without being vendor-controlled: a storage layer you own cryptographically, accessible to any tool that speaks a standard protocol.

---

## The Core Question

Most AI products that remember you store that memory in the operator's infrastructure. Your conversation history, personality profile, preferences, and extracted facts live in Redis, Pinecone, or a managed PostgreSQL instance belonging to the company running the app. When you stop using the product, the memory stays with them. When they get acquired, your memory gets acquired. When they change their privacy policy, your memory is subject to those new terms.

The question this project is working through is simple to state but hard to implement well:

**What does it look like when the memory layer belongs to the user instead of the host application?**

That question is worth answering concretely, with a working chat interface, a real AI, and an actual storage layer that enforces identity at the protocol level.

---

## Why This Is Harder Than It Sounds

The obvious first move is "just let the user own the database." That doesn't work cleanly because of several interconnected problems.

1. **The AI needs to read the memory to generate responses.** If memory is fully private, the AI can't help you. Useful memory requires a read path the agent can use.

2. **Writes must be authenticated.** If anyone can write to your memory under your identity, the privacy claim is hollow. The write path needs cryptographic identity enforcement, not just application-level trust.

3. **The app server sits in the middle.** The server generates the LLM response, extracts the memory summary, and orchestrates everything. In a naive implementation the server can write whatever it wants under any identity. Removing the server from the write path requires the client (browser) to hold and use the signing key.

4. **Sensitivity is contextual.** Not all memory is equal. Your name is public. Your relationship status is personal. Your salary is sensitive. A binary public/private split loses nuance; treating everything as private makes the AI useless.

5. **The user has to be involved.** If the system silently classifies and stores everything, the user has approved nothing; they have just trusted the server with a different label on it. Real agency requires the user to see and decide on at least the sensitive cases.

These constraints define the design space this project is working in.

---

## What Was Built

OpenMemory is a working prototype of one concrete answer to the above questions. The stack is intentionally conventional (Laravel, Vue, Tailwind) so the novel parts are clearly isolated.

### What's different

**Browser-generated identity.** An Ed25519 key pair is generated in the user's browser on first load and persisted in `localStorage`. The server never sees the private key. The ICP principal derived from this key becomes the user's identity in the memory layer, not a server-assigned user ID.

**Browser-signed canister writes (live mode).** When the server extracts a memory summary, it returns it to the browser instead of writing it directly. The browser signs the write using the Ed25519 identity and sends it to the ICP canister. The canister uses `msg.caller` as the record owner, which is the browser's signed principal. The server cannot forge writes under the user's principal.

**Three-tier memory classification.** Each extracted memory is classified as Public, Private, or Sensitive by an LLM call. This classification determines what the agent can recall (only Public reaches the LLM), what the user must approve before signing (both Private and Sensitive), and what is accessible outside the app (only Public is served at the HTTP endpoint).

**Canister-level read enforcement.** The Motoko canister enforces access by `msg.caller`. The server adapter, the HTTP gateway, and the MCP server are all anonymous callers, so they receive only public records. Private and Sensitive records are returned only to a caller whose principal matches the record's owner. This is cryptographic enforcement, not application-level trust.

**Public HTTP endpoint.** Any public memory record is readable at `https://<canister-id>.ic0.app/memory/<principal>` with no authentication, no API key, and no dependency on the Laravel server. The record is accessible from a terminal, another application, or any MCP-compatible AI agent.

**MCP server.** A Model Context Protocol server wraps the public HTTP endpoint. Any MCP-compatible agent (Claude Desktop, other LLMs) can read a user's public memories by principal without touching the host application.

**Memory graph with Physarum dynamics.** A PostgreSQL graph layer stores each confirmed memory as a typed node (memory, person, project, document, task, event, concept, goal) with directed semantic edges (same_topic_as, about_person, part_of, caused_by, related_to). Edge weights evolve according to the Tero et al. (2010) discrete Physarum polycephalum model: weights increment by ALPHA=0.10 on Hebbian co-activation (two nodes loaded into the same LLM context window count as one co-activation event) and decay by RHO=0.97 daily. The weight floor is 0.05; edges never fully disappear. This turns the graph into a relevance index: nodes and edges that the LLM has found useful together accumulate weight; unused paths decay toward the floor and become traversal dead-ends.

**Graph-guided retrieval replacing flat recall.** LLM context assembly uses the Physarum neighbourhood of the highest-weight nodes rather than the full public memory set. Seeds are the public nodes with the highest total connected edge weight. BFS from those seeds collects neighbours in weight-descending order up to a limit. Only the retrieved neighbourhood is reinforced on each turn, so frequently traversed paths grow stronger and unused paths decay toward the floor. Cold-start fallback to flat ICP recall handles the case where no graph exists yet. Each turn's response now includes `active_node_ids`, the exact node IDs that entered the context window.

**Document ingestion path.** Text and Markdown documents can now be ingested through `POST /api/documents/ingest`. The pipeline chunks the source on paragraph boundaries, creates a document anchor node, extracts typed chunk nodes with the same LLM graph extractor used for chat memory, and wires each chunk back to the anchor through `part_of` edges. This is the first perception path in the architecture: non-chat knowledge now enters the same graph primitives as chat-derived memory. In mock mode, public document ingests also mirror a short anchor record into the mock ICP store so the MCP read path can see that a document was ingested. Live browser-signed canister writes for uploaded documents are not wired yet.

**Multi-agent collective Physarum.** Multiple agents each hold their own graph partition, keyed by a derived `graph_user_id`. When two agents both access nodes from the same memory content, identified by SHA-256 content hash, a shared edge accumulates weight at `SHARED_ALPHA * trust_score`. Trust-weighted reinforcement is the MemoryGraft resistance mechanism: zero-trust agents cannot shift collective edge weights. Their access patterns are recorded but their contribution to the collective graph is effectively zero until trust is explicitly granted.

**Three.js mission control surface.** A real-time 3D observation surface renders the multi-agent graph at the cluster level, not the node level. Agent partitions occupy distinct spatial regions in the scene. Shared nodes, meaning memory content appearing in more than one agent's partition, float at partition boundaries, sized larger and colored violet. Intra-partition edges are grey with weight-proportional opacity. Cross-partition shared edges are violet, drawn between the specific nodes in each agent's subgraph that hold the same content. Cluster heat spheres show mean internal edge weight, interpolated from cool blue (low, inactive) to hot amber (high, frequently reinforced). A temporal axis scrubber lets operators inspect any cluster state from the past 24 hours of 15-minute snapshots. An intent alignment panel shows pairwise Jaccard similarity of agent active content sets.

**Cluster detection.** Weighted label propagation (Raghavan et al. 2007) partitions the graph into communities on demand. Each node starts as its own community; in each iteration it adopts the label of its strongest weighted neighbour. Convergence typically happens within 5-20 iterations. Cluster membership and mean internal weight are stored in graph snapshots every 15 minutes for the temporal axis.

### What's standard

The chat interface, LLM integration, session management, and transcript storage are all unremarkable. The memory sensitivity classification is an LLM call with a structured prompt. The mock mode uses Laravel's file cache. None of this is unusual; it exists to make the novel parts demonstrable.

---

## What This Actually Proves

**The canister enforces identity at the protocol level.** In live ICP mode, `msg.caller` on `store_memory` is cryptographically the browser's Ed25519 principal. The server cannot write under a user's principal. This is a real property, not application-level trust, and it is verifiable by reading the Motoko source on-chain.

**Anonymous reads are limited to public records.** The LLM, the server adapter, and the MCP server all call the canister anonymously. The canister's `get_memories` returns private and sensitive records only to an authenticated caller whose principal matches the owner. The LLM cannot recall private or sensitive memories because the canister will not return them to an anonymous caller, not because of application logic.

**Memory outlives the application session.** The browser key survives chat resets. A user who clears their chat history still has the same principal and the same canister records. The memory is not session-scoped.

**Memory lives outside the app's database.** Records are stored in the canister, not in PostgreSQL. The HTTP endpoint works independently of the Laravel server. A user can read their public memories from any context using only their principal: another application, a terminal, or a different AI assistant.

**The MCP connection is real.** The MCP server reads from the canister's public endpoint. Any MCP-compatible agent can be given a principal and retrieve that user's public memories, with no integration work beyond adding the MCP server to their configuration.

**Physarum dynamics produce a genuine relevance index.** Before graph-guided retrieval, all public memories were injected into every context window equally. Edge weights had no bearing on what the LLM received. After graph-guided retrieval, the weights determine which memories enter context: frequently co-activated nodes accumulate weight and are retrieved; rarely traversed nodes decay and drop out. This is measurable by tracking retrieval entropy across turns: a flat recall baseline returns the same N nodes every turn; a weight-driven baseline shifts its selection as high-weight nodes pull in their neighbours and low-weight nodes fall out.

**MemoryGraft-resistant collective reinforcement is implementable at the graph layer.** The trust-weighted SHARED_ALPHA mechanism is a working implementation of collective weight dynamics that is resistant to low-trust agent manipulation. A zero-trust agent that reinforces a cluster contributes zero weight increment to shared edges. An agent with trust_score=0.5 contributes half the individual ALPHA per co-access event. The resistance is a continuous function of trust, not a binary block. Each agent's contribution to shared edge weight scales linearly with its trust score, so the operator adjusts influence by adjusting trust rather than by granting or revoking access entirely.

---

## What This Does Not Prove

**User-controlled memory content.** The server still decides what text gets extracted and stored. The browser signs the write, but the user sees only the finished summary, not the extraction logic or any alternative phrasings that were considered. Approving a memory is consent to store that specific string, not consent to the summarization decision.

**Strong key custody.** `localStorage` is accessible to any same-origin JavaScript. An operator-controlled frontend could read the private key. A script injection attack could exfiltrate it. True user key custody requires a hardware key, WebAuthn, or Internet Identity. The server never holds the key, which prevents server-side forgery. However, localStorage remains vulnerable to script injection where a hardware key or WebAuthn would not be.

**User-chosen classification.** The LLM classifies each memory. The user cannot say "mark this private." Classification accuracy depends on model quality and prompt design. There is no correction mechanism; a misclassified memory stays misclassified until it is deleted.

**Multi-device portability.** The Ed25519 key lives in one browser's `localStorage`. Clearing it generates a new identity. Cross-device access requires manual key export and import. Internet Identity would solve this, and it requires only swapping the identity source.

**Decentralized application layer.** The application itself (Laravel, Vue) runs on conventional infrastructure. Only the memory storage layer is decentralized. "Decentralized AI memory" is accurate; "decentralized AI" is not.

**Whether the LLM actually used the injected memory.** `active_node_ids` identifies which memory nodes entered the context window on each turn. It does not prove that the model attended to those records when generating the response. The model's attention distribution over the context window is not observable. A memory record present at token position 40,000 in a long context may receive near-zero attention. The response might be identical with or without the memory present. This is the observation gap this project is most directly working toward closing. The zkTAM framework (Kinic, 2025) applies zero-knowledge proofs to prove that a response was actually conditioned on specific verified memory records; `active_node_ids` is the precondition that makes zkTAM applicable here.

**The causal direction of cluster reinforcement.** When a cluster is reinforced during a turn, the edge weight change records that those nodes were co-accessed. It does not record whether those nodes were causally relevant to the response. The LLM may have responded from its own parametric knowledge while the injected memory sat in context unused. Physarum weights track access frequency; they do not track influence. An agent with a full, well-structured memory graph might produce identical outputs to an agent with an empty graph if the LLM's attention consistently bypasses the memory context.

**Iteration depth and internal reasoning visibility.** There is no observability into how many internal steps an agent took, how many tool calls, or how many retries occurred before producing an output. Whether the agent arrived at a correct answer on step 5 or step 500 is not recorded anywhere in the graph. The cluster heat map shows which memory regions were accessed. It cannot show the path from question to answer. This is the grain-of-sand problem: you can inspect any individual grain precisely, but you cannot reconstruct why the sandstorm happened from examining grains. The cluster level is the right abstraction for aggregate behavior, but iteration depth requires a different instrument entirely, structured execution tracing rather than memory graph observation.

**Cross-canister memory coherence.** Memories written to different ICP canisters at different times have no global index and no built-in consistency enforcement. A fact recorded in canister A three months ago may contradict a fact recorded in canister B last week. The graph layer captures `supersedes` and `contradicts` edges for memories within a single user's graph partition, but cross-canister semantic consistency is not currently tracked. This is the distributed memory coherence problem at the longest time scale.

---

## The Honest Security Analysis

### What's real

- The server cannot write under the user's principal in live mode
- The canister enforces read access by `msg.caller`, which is cryptographic rather than application logic
- Private and Sensitive memories never reach the LLM recall path, enforced at both the canister and application layers
- Sensitive and Private memories require explicit user approval before any write happens, in both live mode and mock mode
- LLM classification failures discard the memory rather than defaulting to Public (fail-closed behavior)
- The adapter's live write path hard-rejects rather than silently dropping `memory_type`

### What's not real yet

- The user previously had no first-party path to read their own private or sensitive memories back within the app. This has been partially addressed with an authenticated owner-read panel in the chat UI, but the read flow deserves more attention.
- Classification is LLM-generated, non-deterministic, and uncorrectable by the user
- localStorage key custody is weaker than hardware-backed identity
- Mock mode is not a security simulation; it is a functional approximation for development

### The trust boundary

The honest version of the trust claim is this:

> In live ICP mode, the memory storage layer enforces its own access control independently of the host application. Private and Sensitive records are inaccessible to unauthenticated callers at the protocol level. The host application cannot forge writes under a user's identity. The user must approve both Private and Sensitive writes before they are signed.
>
> The host application still controls what text gets presented for signing. The user cannot fully verify that the LLM extraction is faithful to the conversation. The key is as secure as the browser environment it lives in.

---

## The Design Decisions That Defined This Project

### Decision 1: Keep the app conventional

The memory layer is the experiment; Laravel and Vue are not. This decision means the novel parts stand out clearly, and the project is approachable by anyone who has built a web app before.

### Decision 2: Browser-signed writes, not server-signed

If the server signed writes, it could write anything under any identity. Making the browser sign writes means the server must return the summary to the browser, which means the user sees it before it is committed. This is a real improvement in user agency, even if the summary itself was server-generated.

### Decision 3: Three tiers instead of two

Binary public/private is too coarse. "My name" and "my medical history" should not carry the same classification. Three tiers with user approval at the Private/Sensitive boundary give the user meaningful control in the cases that matter most, without requiring approval for every memory.

### Decision 4: Fail-closed on LLM classification errors

When the LLM returns something that cannot be parsed, the memory is discarded rather than defaulting to Public. Losing a memory fact is recoverable in the next conversation. Accidentally publishing a Sensitive memory as Public is not.

### Decision 5: LLM recall is explicitly public-only

The `getPublicMemories()` method exists as explicit application-layer policy, separate from the canister's enforcement. Even if the adapter were given an authenticated identity, the application layer would still filter to Public. This is defense in depth rather than relying on a single implicit property.

### Decision 6: Private memories require user review before storing

Initially, Private memories were auto-signed, with only Sensitive requiring approval. On reflection, relationships, health preferences, location, and habits are all Private by classification. Auto-signing these without the user seeing them is not meaningfully different from the server storing them. The approval boundary was moved to `!== public`.

---

## What Was Learned

**The central unsolved problem is not the canister; it's the server in the middle.** The canister enforcement is clean and provably correct. The server that sits between the user and the canister, generating summaries and deciding what to surface for approval, is still a trusted intermediary even in live mode. Reducing that trust requires either moving classification into the browser or making the classification verifiable.

**Mock mode creates a misleading development environment.** The default local development experience is mock mode, where there is no canister, no identity enforcement, and no meaningful privacy guarantee. Developers building against mock mode develop a different intuition about the system than users running in live mode. This gap is dangerous for a project where the security properties are the point.

**The write-only problem is real and visible.** A user who approves a private memory and then cannot see it again within the application has experienced a broken product, not a privacy feature. The first-party owner-read path is not optional; it is how the user verifies that the privacy guarantee is real.

**LLM classification is probabilistic infrastructure.** Treating LLM output as reliable classification for security-relevant decisions is dangerous without validation. The system currently assumes the LLM output is correct. In practice, classification accuracy will vary by model, by content type, and by language. Any production version of this needs human review or deterministic validation for the classification step.

**The demo story and the implementation must match exactly.** A claim that cannot be demonstrated live is worse than no claim. "Private memories are access-controlled" cannot be demonstrated if there is no first-party way to show the owner reading a private memory. The story must be constrained to what can actually be shown.

---

## Prior Work and Positioning

Four systems define the landscape that this project sits within. The distinctions below are architectural, not evaluative.

**MemGPT** (Packer et al., 2023, arXiv:2310.08560) treats the LLM as an operating system managing a context window (RAM) and a flat external memory store (disk). A learned paging policy decides what to evict from context and what to retrieve. MemGPT's memory is flat: records are scored by similarity, not by a usage-weighted graph. Two records that have been retrieved together on a hundred occasions have the same retrieval weight as two records that have never been retrieved together. There is no mechanism by which retrieval patterns shape future retrieval. The ownership of the memory store is also conventional: MemGPT does not address where memory lives or who controls write access.

**A-MEM** (Xu et al., 2025) implements the Zettelkasten method for AI agent memory: each memory note carries structured attributes and is linked to similar existing notes at write time. When new content is highly similar to an existing note, A-MEM updates the existing note rather than writing a duplicate. This is the correct architectural direction for memory evolution. The difference from this project is that A-MEM's links are static after creation. They reflect write-time similarity, not retrieval history. An edge that was never traversed has the same weight as one traversed a thousand times. A-MEM is closer to a well-organised database than to a conductance network.

**GraphRAG** (Edge et al., 2024, arXiv:2404.16130) extracts entities and relationships from a document corpus and builds a knowledge graph for retrieval. Retrieval traverses the graph rather than scoring flat chunks. This is a meaningful improvement over flat RAG for document-dense tasks. GraphRAG's graph is static after construction: it reflects document semantic structure at build time and does not evolve through retrieval events. It is also built over a document corpus the operator provides, not over the agent's own conversation and memory history.

**Kinic and zkTAM** (2025) demonstrated a zero-knowledge proof system that attests which specific memory records were used when generating a response, closing the observability gap that `active_node_ids` identifies but cannot prove. The zkTAM approach is referenced in the "What This Does Not Prove" section above as the intended next step after retrieval observability is established. OpenMemory's `active_node_ids` field is the precondition that zkTAM requires.

**The combination this project builds.** Graph-structured memory where edge weights evolve through the agent's retrieval behavior; user-cryptographic ownership of source records enforced at the protocol level by `msg.caller` on an ICP canister; a standard MCP interface through which any compliant external agent connects; and trust-weighted collective Physarum dynamics across multiple agents with MemoryGraft resistance. No single paper in the literature identified during this project's research phase combines all four of these properties. The individual components each have precedents. Their combination is the contribution.

---

## The Research Questions This Opens

This project is one concrete implementation. The questions it surfaces are more interesting than the implementation itself.

1. **Can users meaningfully consent to memory storage if the server controls the summary?** The current model gives users veto power (reject the write) but not authorship (choose the summary). Is that sufficient?

2. **What is the minimum viable key custody story for AI memory?** Hardware keys are too heavy for most users. Internet Identity is more practical; what is the real cost of making that upgrade?

3. **Should the LLM read private memories at all?** Currently, private memories are inaccessible to the LLM by design. But a user might want their AI to remember private context. How do you build an opt-in path for agent access while maintaining the owner-only guarantee for other callers?

4. **What happens when memory migrates between AI providers?** If memory is in a canister and any agent can read public records via MCP, what does it mean to switch from one AI assistant to another? Can your memory follow you?

5. **What is the right granularity for user approval?** Per-memory approval (current model) is high-friction for frequent users. Bulk policy ("always store relationships as private") would be lower friction. How do you give users real control without requiring them to approve every extracted fact?

6. **Can the summarization step itself be user-verifiable?** Right now the user sees the summary but not the extraction process. Could a commitment scheme or verifiable computation make the relationship between conversation and stored summary auditable?

7. **Does injected memory context actually change the LLM's response?** The `active_node_ids` field identifies what was in context. There is currently no way to verify that those records influenced the output rather than sitting unused in the context window. A controlled experiment (same conversation, same model, memory present versus absent) is the test that determines whether the retrieval system is doing useful work. Without that evidence, the memory graph might be adding latency and complexity without improving response quality. This is the most important open question for the retrieval layer.

8. **At what cluster granularity does collective Physarum dynamics become meaningfully different from individual Physarum?** The multi-agent simulation runs individual Physarum dynamics in separate partitions and connects them via trust-weighted shared edges. The claim is that the collective weight distribution reflects group knowledge in a way that individual weights do not. This claim is currently untested. An experiment with agents that have genuinely different knowledge domains is the test that determines whether the collective graph topology differs measurably from the sum of individual topologies.

---

## The Strongest Truthful Pitch

> "We're working on two connected problems: what AI memory looks like when the storage layer belongs to the user, and what it looks like when you can actually observe what the memory system is doing across multiple agents running for days.
>
> On the ownership side: in live ICP mode, the canister verifies the caller's cryptographic identity before returning private records. Writes are signed by the browser key, not the server. The memory lives on open infrastructure and is readable by any tool that knows the user's principal.
>
> On the observability side: each conversation turn identifies exactly which memory nodes entered the context window. Those nodes and the edges between them evolve according to a Physarum conductance model. Frequently co-accessed paths grow stronger and unused paths decay. A Three.js mission control surface renders this at the cluster level across multiple agent partitions simultaneously, showing which knowledge regions are hot, where agents are converging, and where cross-agent shared edges have formed.
>
> What we have not proven: whether the LLM actually attends to the injected memory, whether the weight distribution reflects causal influence rather than access frequency, and whether the collective graph topology differs meaningfully from independent individual graphs. Those are the open questions this implementation is positioned to investigate.
>
> This is an experiment, not a product. The key is in localStorage. The classification is LLM-generated. The server still writes the summary. The observation surface shows what entered context, not what influenced the response. We know exactly where the trust boundary and the visibility boundary are. What exists is a working system where AI memory lives on open infrastructure, browser-signed writes prevent server-side forgery, and aggregate agent behavior is observable from a mission control surface across multiple partitions simultaneously."

---

## The Correct Division of Labour Between ICP and PostgreSQL

Research into ICP's actual architecture (Entry 006 in DEVLOG) produced a more precise picture of what each layer should own and why.

ICP is a CP system under the CAP theorem. Update calls go through consensus, which makes writes strongly consistent but introduces latency. Query calls are answered by a single replica with no consensus, returning in milliseconds. Chain key cryptography (Threshold ECDSA) is ICP's genuine differentiator: the canister holds a distributed private key where no single node has custody, enabling cryptographic identity enforcement that no application layer can replicate.

PostgreSQL is an AP-leaning system. Reads and writes are fast. Graph traversal, index queries, degree calculations, and weight updates all run within a chat turn. Physarum decay (daily bulk update) and Hebbian reinforcement (per-turn edge increment) require the speed of PostgreSQL and would be prohibitively expensive as ICP update calls.

The correct split is therefore:

**ICP owns:** raw memory records with msg.caller enforcement, a graph ownership registry (fingerprints of graph states the user has signed), cross-agent access grants, and the user's principal identity. These properties require consensus-grade consistency. They change infrequently. They must be tamper-proof.

**PostgreSQL owns:** the working memory graph (nodes, edges, weights), all Physarum dynamics, graph traversal for LLM context assembly, and Hebbian reinforcement on every chat turn. These operations must complete within a single request cycle. Slightly stale weights are acceptable; consensus latency is not.

**The bridge:** each PostgreSQL graph node stores the ICP record ID of its source memory as metadata, so ownership and relevance are both accessible from a single node record. When graph-guided retrieval selects nodes for LLM context, the ICP record proves ownership and the PostgreSQL weight proves current relevance.

The graph layer currently has no ownership enforcement, which reproduces at the graph level the same trust boundary problem ICP was introduced to solve at the record level. A graph ownership registry on ICP (signing graph fingerprints) is the correct fix, not moving the full graph to ICP.

## The Society Direction: A Cortex Emerging from Collective Memory

A note on terminology: the `/agents` page in this application models collective Physarum dynamics across named graph partitions. These partitions are not autonomous AI agents. They do not call an LLM, pursue goals, or take any action without a human triggering a simulation tick. The word "agent" here follows the complexity-science usage, meaning an actor in a collective system, rather than the AI usage, meaning an autonomous reasoning entity with tool use. Actual external AI agents, such as Claude Desktop, a local Llama instance, or any custom MCP client, connect to the memory graph through the MCP server at `icp/mcp-server/server.js`, not through the simulation panel.

The multi-agent Physarum layer built in this project is already the substrate for a more specific claim: that a society of agents sharing edge weights over time produces emergent group cognition, not just shared bookkeeping. The distinction matters for the research direction because it changes what you are building toward.

### Why the current architecture is already a society

The distinction between a multi-agent system and a society is not scale. It is the mechanism of coordination. Most multi-agent systems coordinate explicitly: agents exchange messages, read shared state, or are orchestrated by a central process. A society does not coordinate. It produces emergent structure through individual behaviors accumulating into shared artifacts that then shape future individual behavior.

The Physarum model produces exactly this. No agent in this system communicates directly with another. Shared edges form because two agents independently traversed the same memory content, not because they exchanged signals. The collective graph topology is a social artifact: a structure that belongs to no individual, that no individual designed, but that reflects what the group collectively found important weighted by trust. This is stigmergy, the same mechanism ant colonies use to build trail networks. Individual ants do not negotiate routes; they deposit and follow pheromone gradients. The efficient path emerges from independent agents responding to a shared medium. Physarum weights are the pheromone gradient.

### Specialization emerges without assignment

If agent A's conversation history is concentrated on technical topics and agent B's is concentrated on creative work, their individual Physarum weights diverge after enough turns. Agent A develops high-weight paths through technical memory clusters and near-floor weights on creative clusters. Agent B is the inverse. Neither was assigned a role. Both grew into their specialization through differential reinforcement on genuinely different access histories.

This is measurable from data already collected. The cluster alignment panel shows pairwise Jaccard similarity of active content sets. Agents whose alignment score has been falling over time are specializing away from each other. The specialization signal is latent in the existing snapshot history; it requires a trend view over time rather than a point-in-time reading.

### The cortex framing, stated precisely

The neocortex is not where any individual thought lives. It is the substrate on which thinking happens: a structure of connection strengths across regions that reflects accumulated experience, shaped by what has been used together frequently and what has not. No single neuron holds a memory. The memory is the pattern of weights across many connections.

The collective Physarum graph is the same structure at the agent-society level. No single agent holds the group memory. The group memory is the pattern of shared edge weights across all agent partitions: which content the group has found important together, how strongly, and weighted by how much each contributing agent has earned trust. An individual agent's personal graph is analogous to a cortical column. The shared edge layer is analogous to long-range connections between cortical regions. The cluster structure is analogous to functional areas. The Three.js mission control surface is therefore not a visualization of individual agents doing things; it is a visualization of a cognitive substrate evolving in real time.

### Where the analogy breaks and becomes its own contribution

A biological cortex has no provenance record. You cannot ask which neurons contributed which weights to which circuit, or when, or with what reliability history.

This implementation stores exactly that. Every shared edge weight increment carries the ICP principal of the contributing agent, the trust score applied at the time of the increment, and the timestamp. The collective cognitive structure is fully auditable: not just what the group knows, but who taught it to them, with what established reputation, and at what point in time. A society of agents on this substrate is the first formulation where the group's cognitive history is verifiable at the edge level.

This is not a minor extension of the cortex analogy. It changes the research question from "what did the group learn?" to "who shaped what the group learned, and with what verifiable trust?" The MemoryGraft resistance mechanism answers the second question cryptographically, not through application logic.

### The key research question

Does the collective Physarum topology diverge meaningfully from what you would predict by summing individual topologies weighted by trust? Concretely: after agents with genuinely different access histories run for long enough, are there high-weight paths in the collective graph that no single agent accessed frequently, but that emerge from the aggregate of many agents each accessing occasionally?

If yes, the collective graph encodes knowledge that exists only at the group level. This is emergent collective memory in the strict sense: information that no individual holds but that the group has established as structurally important. If no, collective Physarum is individual Physarum with shared bookkeeping, which is still useful for alignment monitoring and operational oversight but makes a weaker claim about group cognition.

### The experiment design

The architecture to run this experiment already exists. Three components are needed: a controlled knowledge domain with knowable structure, agents seeded with genuinely different subsets of that domain's memory content, and enough simulation ticks for Physarum weights to mature.

After the run, compare the collective cluster topology against the union of individual cluster topologies weighted by each agent's trust score. If clusters appear in the collective graph that are absent from every individual graph, emergent collective memory has been demonstrated. The temporal axis scrubber provides the time dimension: the appearance of those clusters should be traceable to a specific point in the snapshot history where cross-agent reinforcement first created paths that no individual had established alone.

The infrastructure is not the bottleneck. The Three.js surface renders the collective topology. The snapshot history stores the time series. What the experiment requires is a controlled starting condition and a comparison metric. DEVLOG Entry 013 covers the experimental setup in detail.

---

## Next Steps if This Were Productionized

These are not near-term goals; they are the research trajectory the design points toward.

- Internet Identity or WebAuthn for key custody: Kinic has already demonstrated WebAuthn as the signing device on ICP, meaning the user's biometric or hardware token replaces Ed25519 KeyIdentity in localStorage. Swapping the identity source requires no other architectural change.
- Graph ownership registry on ICP: a lightweight canister that maps each principal to a signed fingerprint of their acknowledged graph state, so the graph layer gains the same tamper-proof ownership property that the record layer already has.
- User-correctable classification: let users re-classify or delete memories they disagree with, and propagate the correction through the graph (update node sensitivity, remove or reclassify edges).
- Opt-in private recall: a user-gated path for the LLM to access private memories for a session, with the canister returning private records only after the user's signed approval for that session.
- Memory portability: export principal and records, import into another application that uses the same canister interface. The graph is reconstructible from the ICP records by re-running graph extraction.
- zkTAM (Trustless Agentic Memory): Kinic's framework applies zero-knowledge proofs to prove that an agent used specific verified memories when generating a response. The active_node_ids field now returned by /chat/send is the precondition for this: it identifies exactly which memories were loaded into context for each turn. A zkML proof over that set closes the open research question about verifiable summarization.
---

## The Larger Direction: AI Memory as a Mission Control Surface

The graph memory layer built in this project points toward a larger interface paradigm that is worth naming precisely, because the obvious framing is wrong.

The obvious framing is the brain globe: a Three.js sphere where individual memory nodes float in space, lighting up as the AI reads them, edges wiring in as new memories form. That is a compelling demo. It is also the wrong tool for the actual problem.

At a few hundred nodes the brain globe works well enough. At one hundred thousand nodes, which two or three days of continuous multi-agent operation can produce, it is an unnavigable point cloud. No individual node is legible at that scale. Watching specific nodes light up tells you nothing useful about what the system is doing at the macro level, which is the level that matters when you are deciding whether to intervene. A visualization that cannot be used when the system is actually running at scale is not a visualization; it is a demo prop.

The problem this interface needs to solve is different. When two or three agents have been running for 48 hours, generating thousands of memories, reinforcing edges across a shared graph, the operator needs answers to questions like: which knowledge clusters are actively hot right now and which have gone stale, are the agents converging on aligned memory paths or drifting into disconnected subgraphs, has a low-trust agent been reinforcing a cluster that the other agents depend on, and what was the graph's state twelve hours ago when the anomaly first appeared. Those are operational monitoring questions, not browsing questions.

The correct interface is a mission control surface, not a brain globe. The distinction is the same as the distinction between a file browser and a server monitoring dashboard. A file browser is for navigation. A monitoring dashboard is for situational awareness, anomaly detection, and targeted intervention. The memory graph at scale needs the second thing.

#### What the mission control surface requires

**Cluster heat map.** The top-level view shows clusters, not nodes. Each cluster is a region of strongly connected nodes, rendered as a heat zone whose color encodes mean edge weight within the cluster. A cluster that multiple agents have been reinforcing is bright. A cluster that has been decaying for hours is cool. The operator can see at a glance which knowledge regions are active and which are fading. Drilling into a cluster drops to the node level for that region only.

**Temporal axis.** A time scrubber lets the operator rewind the graph state to any point in the past N hours. Graph weight is a time series: each Physarum tick produces a new weight snapshot. Scrubbing backward shows what the graph looked like when a specific conversation happened, which edges existed before a new agent was seeded, or what the collective state was before a suspicious reinforcement event. Forward playback at accelerated speed shows how the collective dynamics evolved.

**Anomaly layer.** A low-trust agent contributing high flux to a well-established cluster is a MemoryGraft pattern. The anomaly layer flags these events as they occur: the cluster region pulses with a distinct color keyed to the contributing agent's trust score. The operator can see which principal is trying to shift the collective graph and decide whether to raise or revoke trust. Without this layer, MemoryGraft attacks are invisible in aggregate statistics.

**Intent alignment indicator.** When multiple agents are running, their individual retrieval patterns produce subgraph activity regions. If agent A and agent B are frequently activating overlapping clusters, they are working in cognitive alignment. If their active regions are diverging, they are accumulating private knowledge that does not flow into the shared graph. The alignment indicator shows this as a simple convergence metric per agent pair, updated on each simulation tick. A sudden divergence after several hours of alignment is an early signal worth investigating.

**Region-scale intervention controls.** The operator can act at the cluster level without touching individual nodes. Boosting a cluster's base weight tells the collective Physarum to treat that knowledge region as more traversable. Isolating an agent's subgraph suspends its contribution to shared edge weights without deleting its private graph. Pausing decay on a specific region keeps that knowledge available while the rest of the graph continues its natural attenuation. These controls act on the dynamics, not the data, which is the right abstraction for an operator who cannot read millions of individual memory records.

**Multi-agent subgraph layout.** Each agent occupies a distinct spatial region in the Three.js scene. Shared nodes, meaning those appearing in more than one agent's graph partition via the content hash join, are positioned at the boundaries between regions, with edge thickness proportional to the shared weight accumulated by collective reinforcement. The spatial layout makes the topology of collective memory legible: you can see at a glance how much knowledge is shared across agents versus siloed within individual partitions.

#### The file explorer comparison stated precisely

The claim is not that a 3D memory graph replaces a folder hierarchy as a way to browse files. That claim is technically true but practically irrelevant. The claim is that when AI agents operate continuously over days, the artifact they produce is not a set of files but a weighted knowledge graph. The graph has no meaningful folder structure. Navigating it by hierarchy loses the relational structure entirely.

The right interface for that artifact is one that shows the whole graph's state at a glance, flags anomalies automatically, supports temporal inspection, and allows targeted intervention at the cluster level. That is closer to how an SRE monitors a distributed system than how a developer browses a repository. The Three.js surface is the monitoring dashboard for the collective memory system, not a prettier file tree.

The memory graph already built is the data layer for this. The mission control surface is the experience layer. They are the same system at different levels of rendering.

See DEVLOG Entry 010 for the full design reasoning behind this framing, including why the brain globe metaphor fails at scale and what operational questions the cockpit must answer.

---

## The Hivemind Direction: Collective Physarum Across Multiple Agents

The single-user sovereign memory problem is now solved at the cryptographic level. Kinic's zkTAM system proved that in 2025: one user, one agent, one signing identity, with zero-knowledge proofs attesting which specific memories influenced which responses. Building another single-user portable memory store would not contribute new findings.

The open problem is one layer above that. It is the problem the December 2024 paper on Emergent Collective Memory (arXiv:2512.10166) identified precisely: individual memory gives a 68.7% performance gain over baselines; environmental traces accessible to all agents provide zero statistically significant benefit. The benefit requires cognitive infrastructure inside each agent, not just shared storage.

The cognitive infrastructure is the graph. The question this project is now positioned to answer is:

**What happens when multiple agents with their own ICP-signed memory graphs share edge weights on nodes they have both accessed, and the resulting collective Physarum dynamics determine what the group remembers?**

This is not an incremental extension of the single-user system. It changes the semantics of edge weight from "this agent found these memories relevant together" to "the collective found these memories relevant together." The topology that emerges from collective Physarum reflects the group's accumulated intelligence, not any individual's.

#### The four components this requires

**Collective edge weights.** When agent A and agent B both traverse a node, the shared edge receives flux from both agents' individual Physarum networks. The combined conductance drives the collective network toward paths that multiple agents have independently found important. This is the multi-organism Physarum model applied to AI agent memory: individual organisms merge their networks at shared food sources.

**Trust-weighted reinforcement.** MemoryGraft (arXiv:2512.16962) demonstrated that long-term memory is a poisoning attack surface. A single bad-actor agent can corrupt the shared graph by contributing flux to paths it wants the collective to prefer. The fix is trust-weighted ALPHA: each agent's contribution to shared edge weight is multiplied by the trust score of their signing principal. Principals with no reputation history contribute a small initial weight. Trust is earned through demonstrated accuracy over time. ICP's msg.caller makes this verifiable: every write and every reinforcement event carries the immutable principal of the contributing agent.

**Cross-agent access grants on ICP.** The canister mediates which agents can read which nodes from another agent's graph. Agent A can grant Agent B read access to a specific node by signing a permission record on the canister. The bipartite access control model from Collaborative Memory (arXiv:2505.18279) provides the formal structure; ICP's msg.caller enforcement makes it cryptographic rather than application-level.

**Mission control surface for collective cognitive state.** Each agent's subgraph occupies a distinct region of the Three.js scene. Nodes shared between agents, identified by content hash join, sit at the boundaries between regions, with edge thickness proportional to accumulated shared weight. The top-level view shows cluster heat maps, not individual nodes: a cluster that multiple agents have reinforced is visually hot; one in decay is cool. MemoryGraft anomalies, where a low-trust principal is driving high flux into an established cluster, appear as distinct-colored pulses tied to the contributing principal's trust score. The operator can inspect the graph's state at any past point via time scrubber, isolate an agent's subgraph contribution, or boost a cluster's base weight without touching individual nodes. See "The Larger Direction" section for the full cockpit specification.

#### What this does not yet implement

Nothing in this section is built. The single-user system is the prerequisite: the graph dynamics, the Physarum model, the active_node_ids feedback loop, and the ICP ownership registry must all work correctly for a single user before the multi-agent layer can be added on top.

The research confirms that when those prerequisites exist, the multi-agent extension is both technically feasible and scientifically uncharted. No paper identified in Entry 003 or Entry 007 addresses collective Physarum dynamics applied to AI agent shared memory with cryptographic provenance enforcement at the edge level.

That is the research contribution this project is building toward.

See DEVLOG Entry 007 for the full research landscape analysis and the specific papers that define the open frontier.

---

---

## Portable Sovereign Memory as Infrastructure

The framing that clarifies everything else: OpenMemory is not an AI feature. It is a memory layer you own and carry, and any AI that speaks the protocol can plug into it.

The current AI memory landscape produces a specific kind of dependency. Your conversation history, preferences, inferred personality, and accumulated context live in the vendor's infrastructure. When you open a new chat with a different model, you start over. When you cancel a subscription, your memory stays behind. When the company changes its data retention policy, the policy applies to your memories whether you agreed or not.

The alternative this project builds is a memory layer with a different ownership model. Your memory graph lives in an ICP canister your keys control. You expose it through an MCP endpoint. Any AI system that speaks MCP can read what is relevant to the current conversation and write new memories back when the conversation ends. When you stop using one AI and switch to another, the memory follows you because you hold the keys.

This changes the product question from "how do we make this AI remember better?" to "how do we make memory infrastructure that any AI can plug into?" The graph, the Physarum dynamics, the decay schedule, the trust weights, and the ICP ownership layer are all infrastructure components, not product features. The chat interface in this codebase is the reference implementation demonstrating that the infrastructure works. The MCP server at `icp/mcp-server/server.js` is the actual deliverable: the protocol endpoint through which any compliant AI gains access to your memory.

What changes when you have this: the next AI you open already knows who you are, what you are working on, what you care about, and what you have learned. Not because you told it, but because it read your graph. And when it learns something new in conversation with you, it writes that back into the same graph, where every future AI you work with can read it. The memory compounds across AI systems rather than resetting at the boundary of each vendor's product.

---

## What Sovereignty Does and Does Not Guarantee

The ICP canister model provides strong guarantees at one specific layer: the write authority problem and the private record access problem. No server, no agent, and no external AI can write a memory record under your identity without your private key. Private and sensitive records are gated by `msg.caller` at the canister level, not by application code that can be changed later. Those guarantees are cryptographic and hold regardless of what the application layer above the canister does.

A reasonable question follows: would moving the entire server infrastructure onto ICP extend those guarantees to cover the graph layer and eliminate the remaining gaps? The answer is that it would close one specific gap but leave the deeper problem unchanged.

**What moving the graph layer to ICP would fix.**

The Physarum edge weights, node relationships, and cluster snapshots currently live in PostgreSQL on a server the user does not control. This creates a concrete gap: the raw memory records are cryptographically owned, but the relationship structure derived from those records is a server-side artifact. DEVLOG Entry 001 identified this as a known architectural tension before the multi-agent layer existed. Moving the graph into ICP stable memory would close it. The relationship structure would carry the same ownership guarantee as the raw records, the graph could persist beyond any particular server's continued operation, and the derived topology would be as auditable as the source content. That is worth building, and it is the most tractable infrastructure improvement on the sovereignty dimension.

**What moving to ICP would not fix.**

The deeper problem is not where the data is stored. It is what authorized readers do with data after they have been granted access. The canister enforces `msg.caller` for writes and for private reads. It cannot enforce what a compliant reader does with what it reads after access is granted. Whether the graph lives in PostgreSQL or ICP stable memory, a connected AI that has read access to your public nodes has that content and can forward it to a third party. Moving the storage layer does not constrain what authorized parties take out of it.

This is the structural tension between portability and sovereignty. Making memory portable requires letting external AI systems read it. Letting them read it means they hold the content, and what they do with it afterward is outside the canister's enforcement boundary. The architecture solves storage sovereignty and write authority. It does not solve downstream use.

**Three approaches that would actually address downstream use.**

The first is capability tokens with scope and expiry. Rather than granting an AI permanent read access to the full public graph, the user issues a time-limited token scoped to a specific task or session. When the session ends, the token expires. This does not prevent exfiltration within the window, but it limits exposure to discrete authorized interactions rather than continuous open access.

The second is selective disclosure at retrieval time. The MCP server already performs relevance retrieval rather than returning the full public graph on every request. Treating this as a deliberate privacy control rather than a performance optimization means the AI receives only the fragment of the graph assembled for the current context. The AI never sees the full corpus, only the slice that was relevant to this conversation. This reduces the surface area of possible exfiltration without eliminating it entirely.

The third is verifiable computation via the zkTAM approach referenced elsewhere in this document. Zero-knowledge proofs that an AI used a specific set of memories when generating a specific response would allow auditing whether a connected AI confined its use of your memories to the stated purpose. The `active_node_ids` field already provides the precondition. The proof infrastructure does not yet exist at production scale, but the path is architecturally clear.

**The honest boundary of what this system guarantees.**

The ICP canister guarantees that no unauthorized party wrote your memory records and that no unauthorized party can read your private records. It does not guarantee that authorized readers confine their use of public records to the purposes you intended. The graph layer and the MCP server operate within that boundary: they access public content that was already classified as shareable at the time it was stored. Moving all infrastructure to ICP would strengthen the storage sovereignty claim and close the graph structure gap. It would not move the boundary itself. The design question for anyone building on this architecture is not whether to accept that boundary but how to narrow it over time through capability tokens, selective disclosure, and eventually verifiable computation.

---

## OpenMemory as a Cognitive Subsystem

The project started as a question about memory ownership. The question it is now positioned to answer is larger: what does a modular, open cognitive architecture look like when the memory layer is infrastructure rather than a feature?

Every sufficiently capable cognitive system requires at minimum three functional components. It needs a reasoning layer that processes current information and generates responses. It needs a perception layer that receives input from the environment. And it needs a memory layer that stores what the system has learned and makes it retrievable when relevant. Biological brains implement these as distinct anatomical structures with defined interfaces between them. The hippocampus consolidates episodic memory and packages it for long-term storage in the cortex. The prefrontal cortex coordinates reasoning and goal maintenance. Sensory cortices handle perception. These systems communicate through synaptic connections whose strength encodes learned relevance, which is exactly what Physarum edge weights do.

The current AI landscape implements all three components inside a single vendor's product. The reasoning layer (the model), the perception layer (the context window and retrieval system), and the memory layer (the conversation history and any fine-tuning) are bundled together and owned by the same company. This bundling produces capable products, but it makes the memory layer non-portable and non-interoperable.

OpenMemory is the memory subsystem extracted and made sovereign. The MCP protocol is the synaptic interface through which any external reasoning layer (any LLM) connects to the memory layer. The Physarum dynamics are the mechanism by which connection weights adjust based on use, performing the same function that synaptic plasticity serves in biological neural circuits. The trust scoring system is the authorization model that controls which reasoning systems can read from and write to the memory substrate.

A system built on this model would assign each major cognitive function to a specialized component with a well-defined interface. A perception agent reads documents, images, or other inputs and writes structured observations to the memory graph. A reasoning agent runs inference against retrieved memory context and produces responses. A planning agent maintains goal state and monitors whether the reasoning outputs are moving toward it. A consolidation agent runs periodically to compress episodic memory into semantic nodes, implementing the hippocampal-to-cortical transfer that biological memory systems perform during sleep.

None of those agents need to be the same model or run on the same infrastructure. They share a memory graph via the MCP interface, and the Physarum dynamics ensure that the shared graph reflects what the collective system has found important across all their interactions. The cognitive architecture is modular, interoperable, and user-sovereign because the memory layer is.

This is a different research direction from the current frontier in AI, which is scaling individual models. It is closer to the cognitive architecture research of the 1990s (Soar, ACT-R, LIDA) but grounded in modern infrastructure: cryptographic identity, decentralized storage, and protocol-level interoperability rather than monolithic process architectures. Whether it produces more capable systems than scaling alone is an open question. Whether it produces more auditable and user-sovereign systems is not: the architecture makes the memory inspectable, the provenance verifiable, and the ownership unambiguous by construction.

Track 9 in the research agenda describes the experiment that would test whether a modular cognitive architecture built on this memory layer outperforms a single-model baseline on tasks requiring long-horizon context, multi-domain synthesis, or explicit knowledge handoff between specialized subsystems.

---

## The Thirty-Year Question

The question that only makes sense when memory is infrastructure rather than a product feature: could someone access their memory graph thirty years from now?

The answer is yes in principle, and building toward yes in practice shapes several architectural decisions that would otherwise seem over-engineered.

ICP canisters persist as long as their cycle balance is maintained. There is no server to shut down, no acquisition to survive, no policy change to endure. The canister runs until you stop funding it. A memory graph seeded today and maintained over decades would accumulate a genuine intellectual autobiography: the projects you worked on, the problems you solved, the concepts that proved durable, the knowledge that decayed because you stopped using it.

At thirty years, the raw graph is not the thing you access directly. A decade of active memory use would produce tens of thousands of nodes and hundreds of thousands of edges, the vast majority of which have decayed to the floor weight. What survives is the structure that has been reinforced consistently across time: the concepts you return to across many contexts, the topics that proved relevant to new problems years after they first appeared. Those are the hub nodes in a mature memory graph. They represent what you actually know, not just what you once encountered.

This changes the design requirement for memory consolidation. If the graph needs to be legible in thirty years, the consolidation process cannot just prune dead edges. It must produce semantic nodes: higher-order abstractions that replace dense clusters of episodic memories with a single consolidated node that carries the essential meaning of the cluster. This is how biological memory works. Episodic memory (the specific conversation, the exact code, the precise problem) decays relatively quickly. Semantic memory (the principle learned from many such conversations) persists. The consolidation process is the mechanism that converts episodic into semantic, and it needs to run regularly on any memory graph intended to remain useful over years rather than weeks.

The thirty-year framing also clarifies what "memory ownership" actually means. Ownership of a database record is a legal category. Ownership of a signed ICP canister is a cryptographic one: you can prove, to anyone, that no one modified your memory history without your private key. If you access your graph in 2056, you can verify that the entries were written by your key and have not been altered since. That provenance guarantee is not available from any vendor-controlled memory system, regardless of their privacy policy language. It is a property of the cryptographic architecture.

---

## Known Limitations and Open Measurements

The following are not future work items. They are properties of the current implementation that an evaluator should know before treating any claim in this document as proven.

**The discrete Physarum approximation is not formally verified against the continuous model.** SCIENCE.md section 10 explains the mapping from the Tero et al. (2010) ODE to the discrete ALPHA/RHO/FLOOR constants. The continuous model produces a coupled pressure-equilibration system where all edge conductances evolve simultaneously. The discrete model updates each edge independently. Whether the two systems converge to the same topological class under equivalent conditions is an open question. Track 1 in RESEARCH.md defines the experiment that would test this.

**ALPHA and RHO were chosen by inspection, not empirically calibrated.** The constants were selected based on the intended behavioral properties described in SCIENCE.md section 10: a ten-activation reinforcement horizon and a 99-day decay span. They have not been tuned against a retrieval quality benchmark. A different choice of constants might produce a better or worse relevance index, and there is currently no measurement that would detect the difference.

**The scale-free topological claim is unmeasured.** SCIENCE.md sections 7 and 10, and Track 1 in RESEARCH.md, all reference the claim that Physarum dynamics on a memory graph produce a scale-free degree distribution with gamma in [2,3]. This has not been measured on a graph produced by this system. The `GET /api/graph/topology` endpoint implements the degree distribution computation and power-law fit. The result of running that endpoint on a real memory graph is the finding; the claim is a hypothesis until that result exists.

**No A/B experiment confirms that injected memory improves response quality.** The system retrieves a Physarum neighbourhood and injects it into the LLM context window on each turn. The `active_node_ids` field in the response identifies exactly what was injected. What has not been measured is whether responses with memory present are meaningfully better than responses with an empty context. Track 5 Layer 2 in RESEARCH.md defines this experiment. Without that measurement, the retrieval pipeline might be adding latency and complexity with no detectable benefit.

**Physarum weights track access frequency, not causal influence.** When the LLM generates a response with memory records in context, those records' edges receive reinforcement regardless of whether the model attended to them. A record present at token position 40,000 in a long context receives the same weight increment as one the model demonstrably used. The attention distribution over the context window is not observable through any current LLM provider API. The edge weights are therefore a signal of retrieval co-occurrence, not a signal of reasoning relevance. The distinction matters for anyone interpreting high-weight edges as evidence of conceptual importance.

**The graph layer carries no cryptographic ownership.** Memory records in the ICP canister are owned by the user's Ed25519 principal. The graph nodes and edge weights derived from those records live in PostgreSQL on a server the user does not control. The relationship structure could be altered without the user's knowledge. The ICP ownership guarantee covers the source records; it does not extend to the derived topology. A graph ownership registry on ICP (signed fingerprints per graph state) is the correct fix, described under the ICP-PostgreSQL division of labour section.

**Document ingest is only partially portable today.** Public document ingests mirror a short anchor record into the mock ICP store, which keeps the mock-mode MCP path aware that a document exists. In live ICP mode, uploaded documents still enter only the PostgreSQL graph because the browser-signed canister write path is not wired for file uploads yet. The result is that document ingestion currently improves in-app graph retrieval before it fully improves cross-tool portability.

**localStorage key custody is vulnerable to script injection.** The Ed25519 private key is generated in the browser and persisted to localStorage. Any same-origin JavaScript can read localStorage. A script injection attack could exfiltrate the key. The server never holds the key, which prevents server-side forgery. Replacing localStorage with WebAuthn or a hardware key would close the client-side vulnerability. Internet Identity on ICP provides a ready implementation of this upgrade.

**LLM classification is non-deterministic and uncorrectable.** The Public/Private/Sensitive classification is produced by an LLM call on each extracted memory. The same content submitted twice may receive different classifications. A misclassified memory has no correction path in the current implementation; it remains at its original classification until deleted. Any deployment treating this classification as a security boundary rather than a best-effort filter should validate classification accuracy on a held-out sample before relying on it.

---

## The Storage Trigger Problem

The most practical unsolved problem in the system: when should an AI decide that something is worth remembering?

The naive answer is "store everything." Every turn summary, every topic mentioned, every preference stated. This produces a large graph quickly, but the graph becomes noise. A memory of "the user asked what time it is in Tokyo" is not useful six months later. A memory of "the user is building a distributed memory system and cares deeply about cryptographic provenance" is. Storing both treats them as equivalent, which they are not.

The opposite error is equally common: store only what the user explicitly flags as important. This misses the most valuable memories, which are the ones the user does not know they need until they need them. A pattern of interest that shows up repeatedly across many conversations, never explicitly flagged, is often more important than a single explicitly-requested memory note.

The correct approach is a memorability judgment made before storage, not after. The LLM needs to evaluate each potential memory against four criteria before committing it to the graph.

**Novelty.** Is this content already represented in the graph? A memory that duplicates existing node content with high cosine similarity adds noise rather than information. The storage trigger should check graph coverage before writing.

**Significance.** Does this content reflect something the user stated as important, spent extended time on, or expressed strong preference about? Passing references to common topics do not meet this bar. Deep engagement with a specific problem does.

**Durability.** Is this the kind of content the user is likely to need again? Technical decisions, design constraints, long-term goals, and personal working preferences are durable. Transient questions, one-off lookups, and conversational pleasantries are not.

**Connection richness.** Does this content connect meaningfully to existing nodes? A memory that links to five existing high-weight nodes strengthens the graph structure. A memory that connects to nothing is an isolated leaf that will decay to the floor without reinforcement.

The storage trigger is a classification step that runs before the summarization and graph-write pipeline. The LLM evaluates the conversation turn against these four criteria and decides whether to store, to update an existing node, or to skip. This is the difference between a memory system that knows you and a memory system that has accumulated a transcript of everything you ever said.

The second part of this problem is when in the conversation the trigger fires. Firing at the end of every turn is too frequent and too granular. Firing only at the end of a conversation misses important decisions made early in a long session. The right trigger is event-driven: the LLM fires a storage decision when something genuinely new appears in the conversation, not on a fixed schedule.

This is Track 6 in the research agenda, and it is the problem that separates a useful memory system from a large disorganized database.

---

*This document was written to preserve the research thinking behind OpenMemory. The implementation will change; the questions it's asking are the part worth keeping.*
