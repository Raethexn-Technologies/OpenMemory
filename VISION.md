# OpenMemoryAgent: Research Vision

*What does user-sovereign AI memory actually look like when you try to build it?*

This document is the research record for OpenMemoryAgent. It is not a setup guide (see README.md) and not a feature list. It captures the design questions driving the project, what was actually learned building it, what the implementation honestly proves, and where the hard problems remain.

For the running implementation log — what was discovered building specific features, security findings, and what remains unresolved — see [DEVLOG.md](./DEVLOG.md). VISION.md is the stable research position; DEVLOG.md is the honest record of how it got there.

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

5. **The user has to be involved.** If the system silently classifies and stores everything, the user has approved nothing — they've just trusted the server with a different label on it. Real agency requires the user to see and decide on at least the sensitive cases.

These constraints define the design space this project is working in.

---

## What Was Built

OpenMemoryAgent is a working prototype of one concrete answer to the above questions. The stack is intentionally conventional (Laravel, Vue, Tailwind) so the novel parts are clearly isolated.

### What's different

**Browser-generated identity.** An Ed25519 key pair is generated in the user's browser on first load and persisted in `localStorage`. The server never sees the private key. The ICP principal derived from this key becomes the user's identity in the memory layer, not a server-assigned user ID.

**Browser-signed canister writes (live mode).** When the server extracts a memory summary, it returns it to the browser instead of writing it directly. The browser signs the write using the Ed25519 identity and sends it to the ICP canister. The canister uses `msg.caller` as the record owner, which is the browser's signed principal. The server cannot forge writes under the user's principal.

**Three-tier memory classification.** Each extracted memory is classified as Public, Private, or Sensitive by an LLM call. This classification determines what the agent can recall (only Public reaches the LLM), what the user must approve before signing (both Private and Sensitive), and what is accessible outside the app (only Public is served at the HTTP endpoint).

**Canister-level read enforcement.** The Motoko canister enforces access by `msg.caller`. The server adapter, the HTTP gateway, and the MCP server are all anonymous callers, so they receive only public records. Private and Sensitive records are returned only to a caller whose principal matches the record's owner. This is cryptographic enforcement, not application-level trust.

**Public HTTP endpoint.** Any public memory record is readable at `https://<canister-id>.ic0.app/memory/<principal>` with no authentication, no API key, and no dependency on the Laravel server. The record is accessible from a terminal, another application, or any MCP-compatible AI agent.

**MCP server.** A Model Context Protocol server wraps the public HTTP endpoint. Any MCP-compatible agent (Claude Desktop, other LLMs) can read a user's public memories by principal without touching the host application.

**Memory graph with Physarum dynamics.** A PostgreSQL graph layer stores each confirmed memory as a typed node (memory, person, project, document, task, event, concept) with directed semantic edges (same_topic_as, about_person, part_of, caused_by, related_to). Edge weights evolve according to the Tero et al. (2010) discrete Physarum polycephalum model: weights increment by ALPHA=0.10 on Hebbian co-activation (two nodes loaded into the same LLM context window count as one co-activation event) and decay by RHO=0.97 daily. The weight floor is 0.05; edges never fully disappear. This turns the graph into a relevance index: nodes and edges that the LLM has found useful together accumulate weight; unused paths decay toward the floor and become traversal dead-ends.

**Graph-guided retrieval replacing flat recall.** LLM context assembly uses the Physarum neighbourhood of the highest-weight nodes rather than the full public memory set. Seeds are the public nodes with the highest total connected edge weight. BFS from those seeds collects neighbours in weight-descending order up to a limit. Only the retrieved neighbourhood is reinforced on each turn, so frequently traversed paths grow stronger and unused paths decay toward the floor. Cold-start fallback to flat ICP recall handles the case where no graph exists yet. Each turn's response now includes `active_node_ids`, the exact node IDs that entered the context window.

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

At a few hundred nodes the brain globe works well enough. At one hundred thousand nodes — after two or three days of continuous multi-agent operation — it is an unnavigable point cloud. No individual node is legible at that scale. Watching specific nodes light up tells you nothing useful about what the system is doing at the macro level, which is the level that matters when you are deciding whether to intervene. A visualization that cannot be used when the system is actually running at scale is not a visualization; it is a demo prop.

The problem this interface needs to solve is different. When two or three agents have been running for 48 hours, generating thousands of memories, reinforcing edges across a shared graph, the operator needs answers to questions like: which knowledge clusters are actively hot right now and which have gone stale, are the agents converging on aligned memory paths or drifting into disconnected subgraphs, has a low-trust agent been reinforcing a cluster that the other agents depend on, and what was the graph's state twelve hours ago when the anomaly first appeared. Those are operational monitoring questions, not browsing questions.

The correct interface is a mission control surface, not a brain globe. The distinction is the same as the distinction between a file browser and a server monitoring dashboard. A file browser is for navigation. A monitoring dashboard is for situational awareness, anomaly detection, and targeted intervention. The memory graph at scale needs the second thing.

#### What the mission control surface requires

**Cluster heat map.** The top-level view shows clusters, not nodes. Each cluster is a region of strongly connected nodes, rendered as a heat zone whose color encodes mean edge weight within the cluster. A cluster that multiple agents have been reinforcing is bright. A cluster that has been decaying for hours is cool. The operator can see at a glance which knowledge regions are active and which are fading. Drilling into a cluster drops to the node level for that region only.

**Temporal axis.** A time scrubber lets the operator rewind the graph state to any point in the past N hours. Graph weight is a time series: each Physarum tick produces a new weight snapshot. Scrubbing backward shows what the graph looked like when a specific conversation happened, which edges existed before a new agent was seeded, or what the collective state was before a suspicious reinforcement event. Forward playback at accelerated speed shows how the collective dynamics evolved.

**Anomaly layer.** A low-trust agent contributing high flux to a well-established cluster is a MemoryGraft pattern. The anomaly layer flags these events as they occur: the cluster region pulses with a distinct color keyed to the contributing agent's trust score. The operator can see which principal is trying to shift the collective graph and decide whether to raise or revoke trust. Without this layer, MemoryGraft attacks are invisible in aggregate statistics.

**Intent alignment indicator.** When multiple agents are running, their individual retrieval patterns produce subgraph activity regions. If agent A and agent B are frequently activating overlapping clusters, they are working in cognitive alignment. If their active regions are diverging, they are accumulating private knowledge that does not flow into the shared graph. The alignment indicator shows this as a simple convergence metric per agent pair, updated on each simulation tick. A sudden divergence after several hours of alignment is an early signal worth investigating.

**Region-scale intervention controls.** The operator can act at the cluster level without touching individual nodes. Boosting a cluster's base weight tells the collective Physarum to treat that knowledge region as more traversable. Isolating an agent's subgraph suspends its contribution to shared edge weights without deleting its private graph. Pausing decay on a specific region keeps that knowledge available while the rest of the graph continues its natural attenuation. These controls act on the dynamics, not the data, which is the right abstraction for an operator who cannot read millions of individual memory records.

**Multi-agent subgraph layout.** Each agent occupies a distinct spatial region in the Three.js scene. Shared nodes — those appearing in more than one agent's graph partition via the content hash join — are positioned at the boundaries between regions, with edge thickness proportional to the shared weight accumulated by collective reinforcement. The spatial layout makes the topology of collective memory legible: you can see at a glance how much knowledge is shared across agents versus siloed within individual partitions.

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

**Mission control surface for collective cognitive state.** Each agent's subgraph occupies a distinct region of the Three.js scene. Nodes shared between agents — identified by content hash join — sit at the boundaries between regions, with edge thickness proportional to accumulated shared weight. The top-level view shows cluster heat maps, not individual nodes: a cluster that multiple agents have reinforced is visually hot; one in decay is cool. MemoryGraft anomalies, where a low-trust principal is driving high flux into an established cluster, appear as distinct-colored pulses tied to the contributing principal's trust score. The operator can inspect the graph's state at any past point via time scrubber, isolate an agent's subgraph contribution, or boost a cluster's base weight without touching individual nodes. See "The Larger Direction" section for the full cockpit specification.

#### What this does not yet implement

Nothing in this section is built. The single-user system is the prerequisite: the graph dynamics, the Physarum model, the active_node_ids feedback loop, and the ICP ownership registry must all work correctly for a single user before the multi-agent layer can be added on top.

The research confirms that when those prerequisites exist, the multi-agent extension is both technically feasible and scientifically uncharted. No paper identified in Entry 003 or Entry 007 addresses collective Physarum dynamics applied to AI agent shared memory with cryptographic provenance enforcement at the edge level.

That is the research contribution this project is building toward.

See DEVLOG Entry 007 for the full research landscape analysis and the specific papers that define the open frontier.

---

*This document was written to preserve the research thinking behind OpenMemoryAgent. The implementation will change; the questions it's asking are the part worth keeping.*
