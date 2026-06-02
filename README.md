# OpenMemory

Built because working across Claude, Codex, Gemini CLI, and other AI tools means re-explaining project context from scratch every time you switch. Each AI starts fresh at every session boundary. This is the memory layer that lives outside any single AI so all of them stay in sync.

The MCP server at `icp/mcp-server/server.js` is the protocol endpoint through which any MCP-compatible AI reads and writes memory records. Configure it once, and every AI you connect to it already knows what you have been working on. The chat interface in this repository is the reference implementation demonstrating that the infrastructure works end-to-end.

Identity works differently depending on the tool. The browser chat UI authenticates the user through Internet Identity (`@dfinity/auth-client`) and signs writes to the ICP canister with the delegation that II returns. Until the user has signed in, the browser holds an `AnonymousIdentity` and the canister rejects writes from anonymous callers, so no memories are stored. CLI tools running in terminals (Claude Code, Gemini, Codex) share a portable Ed25519 identity file at `~/.config/openmemory/identity.json`, generated once with `node setup-identity.js`, and write through the MCP server rather than through a browser. A typed memory graph sits in PostgreSQL alongside the canister records, tracking relationships between memories and applying Physarum conductance dynamics that shift edge weights based on how the LLM actually uses each connection over time.

[VISION.md](./VISION.md) covers the design decisions and research questions in depth. [DEVLOG.md](./DEVLOG.md) is the running record of what was discovered building it: implementation findings, security fixes, architectural tensions, and what remains unresolved. [RESEARCH.md](./RESEARCH.md) is the active research agenda: the open scientific claims, what needs to be built to test each one, and how the tracks evolve as discoveries open new questions. [SCIENCE.md](./SCIENCE.md) explains the mathematics and biology behind the graph layer in plain terms, with source citations and references to the tests that verify each formula.

---

## How it works

The application is a standard Laravel and Vue web app. The interesting parts are the memory layer and the graph that grows on top of it.

**Redaction policy.** Before a chat turn, MCP write, document ingest, retrieved memory, or graph-sync payload crosses an LLM or storage boundary, `RedactionService` runs deterministic local checks. Non-negotiable floor categories include payment cards, CVV, bank routing and account numbers, IBANs, SSNs, SINs, credentials, JWTs, private keys, and identifiable minor-age details. These are redacted or tokenized even if a user policy tries to allow them. User-tunable categories include email, phone, street address, date of birth, compensation, and health-condition text. Policies are loaded from `redaction_policies` when present, otherwise the deployment preset in `config/redaction.php` is used.

**Storage trigger.** Before summarizing a conversation turn, the server passes the exchange to `MemorabilityService`, which evaluates novelty, significance, durability, and connection richness against the 20 most recently created nodes. The evaluation returns one of three decisions: store a new node, update an existing node with a specific ID, or skip the turn entirely. This filter prevents ephemeral exchanges (greetings, clarifying questions, transient status updates) from creating nodes, keeping the graph focused on durable knowledge.

**Memory records.** When a redacted turn passes the storage trigger, the server summarizes it, classifies it as public, private, or sensitive, applies a second deterministic redaction pass to the summary, and proceeds down the write path. Redaction findings can only raise sensitivity, never lower it. In the browser chat UI, private and sensitive records require user approval before the browser signs the write with the Internet Identity delegation and sends it directly to the ICP canister. The canister records `msg.caller` as the owner of that record and rejects writes from anonymous principals, so a signed-out browser cannot store memories. CLI tools writing through the MCP server POST to the Laravel `/mcp/store` endpoint in mock mode, which handles redaction, graph extraction, and node storage server-side without a browser session.

**Document ingestion.** `POST /api/documents/ingest` accepts pasted text or Markdown files, redacts the source text, creates a document anchor node, chunks the redacted source with `DocumentChunkerService`, and runs each chunk through the same `GraphExtractionService` used for chat memories. Chunk nodes are stored with `source = 'document'` and connected back to the anchor with `part_of` edges, so uploaded knowledge enters the same graph primitives as chat-derived memory. `EvidenceFactExtractionService` also extracts smaller source-backed facts from each successfully stored chunk and writes them to `evidence_facts` with `source_node_id`, `source_document_id`, quote-derived character spans, confidence, and chunk metadata. `GET /api/documents` lists the document anchors for the current user. The HTTP API defaults ingested documents to `public` because graph-guided retrieval and grounded document QA only load public nodes into the LLM context window; redaction findings can escalate the effective sensitivity to `private` or `sensitive`.

**The memory graph.** After every confirmed memory write or document ingest, an LLM call extracts a node type (memory, person, project, document, task, event, concept, or goal), a label, semantic tags, named people, and named projects from the redacted memory content. Extracted labels and entity fields are sanitized again before storage so redaction placeholders do not become person or project anchors. This data creates a typed graph node in PostgreSQL and auto-wires edges to related existing nodes: tag overlap produces `same_topic_as` edges; named people produce `about_person` edges to person anchor nodes; named projects produce `part_of` edges to project anchor nodes.

**Physarum dynamics.** Edge weights are not static. When the LLM retrieves a set of memory nodes to build a response, all edges between those co-accessed nodes receive a conductance increment of ALPHA = 0.10, clamped to 1.0. A daily scheduled command applies a decay factor of RHO = 0.97 to all edges, floored at 0.05. Edges that are traversed together regularly accumulate weight; edges between memories that the agent never retrieves together decay toward the floor. This implements the discrete form of the Tero et al. (2010) slime mold conductance model: paths the organism uses frequently develop higher conductance, and paths that carry no flux thin out.

**LLM recall.** Only public memories are loaded into the LLM context. Within that public set, `findContextSeeds()` seeds public `goal` nodes first and fills the remaining seed slots by total connected edge weight from the same bounded candidate pool. Retrieved records are redacted again before prompt injection, which protects legacy records or external writes that predate the redaction layer. Retrieval is therefore goal-biased, but the normal memory prompt still injects retrieved nodes as natural-language context. Private and sensitive records are gated by `msg.caller` on the canister: anonymous callers (the server adapter, the MCP server, and external HTTP clients) receive only public records.

**Grounded document QA.** Setting `GROUNDED_RETRIEVAL=true` switches chat response generation into a corpus-grounded document mode. The graph still selects candidate public document chunks, then `EvidenceRetrievalService` performs query-aware lexical scoring over `evidence_facts` from those chunks. `LlmService::buildGroundedSystemPrompt()` then gives the model only the current redacted user question and the selected evidence facts. The prompt requires every factual sentence to cite `[EVID:<id>]` and instructs refusal when no evidence supports the question. This is not an attention-bypass architecture and does not prove zero hallucination; it is a constrained prompt path that makes unsupported claims easier to detect and evaluate.

**Active node IDs.** The `/chat/send` response includes an `active_node_ids` field listing the graph nodes loaded into context that turn. The Three.js mission control surface at `/3d` reads this field to highlight which nodes were active on the most recent turn.

**Graph partition simulation.** Multiple named graph partitions can be created under the same owner at `/agents`. Each partition holds its own subgraph seeded from the owner's public nodes. When two partitions both access nodes derived from the same memory content, a shared edge accumulates weight at `SHARED_ALPHA * trust_score`. The trust score is adjustable per partition, making each one's contribution to the collective graph proportional to its assigned reputation. These partitions model different agent roles or conversation contexts sharing a common memory substrate; actual external AI agents connect via the MCP server rather than through this simulation panel.

**Cluster detection.** Weighted label propagation (Raghavan et al. 2007) partitions the personal or collective graph into communities on demand. Cluster membership and mean internal weight are written to `graph_snapshots` every 15 minutes and feed the temporal axis scrubber in the Three.js surface.

**Consolidation.** A weekly scheduled command runs `ConsolidationService`, which inspects every cluster with a mean internal edge weight above 0.30 and at least five unconsolidated nodes. Qualifying clusters are compressed: the LLM produces a one-sentence summary of the cluster's common theme, a new `concept` node is created from that summary, and all absorbed episodic nodes are stamped with a `consolidated_at` timestamp and excluded from future retrieval and consolidation passes. Goal nodes are excluded from consolidation because they are user-declared intentions, not episodic traces to compress away. The concept node is wired to the absorbed nodes via `supersedes` edges, and any edges from outside the cluster that formerly targeted absorbed nodes are re-wired to the concept node. This mirrors hippocampal-to-cortical transfer: many episodic traces consolidate into a single navigable semantic node. The consolidation trigger is also exposed as `POST /api/graph/consolidate` for in-app use.

**Pruning.** A monthly scheduled command runs `PruneMemoryNodes`, which deletes nodes that meet two conditions simultaneously: all of their edges have decayed to floor weight (0.06 or below), and the node has not been accessed in the past 90 days. Nodes with no edges at all that are older than 90 days are also deleted. Pruning removes edges first, then nodes, to avoid foreign-key violations. The pruning trigger is also exposed as `POST /api/graph/prune` for in-app use.


---

## Memory types

The three memory tiers are the core of the trust model:

| Type | LLM context | Owner panel | Requires approval |
|---|---|---|---|
| public | Yes | Yes | No |
| private | No | Yes | Yes |
| sensitive | No | Yes | Yes |

Public memories are the only records the LLM can recall. Private and sensitive records are owner-gated at the canister level, not just by application code. The graph layer inherits the sensitivity of the source memory record: anchor nodes created for a private memory are themselves private, so they do not appear in the public graph.

The redaction layer sits beside this access-control model. Access control decides who can read a record. Redaction decides whether raw high-risk content may be stored or sent to an LLM at all. Floor categories are always redacted or tokenized before storage, and a redaction finding can raise a record from public to private or sensitive.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| Frontend | Vue 3, Inertia.js, Tailwind CSS |
| Database | PostgreSQL (Docker) or SQLite (local development) |
| LLM | OpenRouter, model configurable via `OPENROUTER_MODEL` |
| Redaction | `RedactionService`, `redaction_policies`, `config/redaction.php`, deterministic local checks before LLM and storage boundaries |
| Memory records | ICP canister (Motoko), browser-signed writes (chat UI), MCP server writes (CLI tools), Node.js adapter for server reads |
| Memory graph | PostgreSQL tables (`memory_nodes`, `memory_edges`), Physarum dynamics, D3 force-directed explorer at `/graph` |
| Evidence facts | PostgreSQL table (`evidence_facts`), source-backed document facts with quote-derived spans for grounded document QA |
| Graph partition layer | PostgreSQL tables (`agents`, `shared_memory_edges`, `graph_snapshots`), collective Physarum dynamics across named partitions, Three.js mission control surface at `/3d` |

---

## Quickstart without Docker

```bash
cd app
cp .env.example .env
# Set OPENROUTER_API_KEY in .env (get a key at https://openrouter.ai/keys)
php artisan key:generate
composer install
npm install
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000. Memory runs in mock mode by default, so no canister or adapter is needed to get started.

---

## Quickstart with Docker and PostgreSQL

```bash
cp app/.env.example app/.env
# Set OPENROUTER_API_KEY in app/.env

docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://localhost:8080.

---

## Mock mode

Setting `ICP_MOCK_MODE=true` (the default) replaces the ICP canister with Laravel's file cache. This is the right way to run the application locally or in CI when you don't have a running dfx replica or deployed canister. The LLM still calls OpenRouter for chat responses, but no ICP tooling is required.

Private and sensitive memories show the same approval dialogs in mock mode that they do in live mode. The graph layer runs identically in both modes because it operates entirely within PostgreSQL.

---

## Swapping models

Change `OPENROUTER_MODEL` in `.env` and restart the server. The full model list is at https://openrouter.ai/models.

```env
OPENROUTER_MODEL=anthropic/claude-sonnet-4.5    # default
OPENROUTER_MODEL=google/gemini-2.5-flash         # faster, lower cost
OPENROUTER_MODEL=google/gemini-2.5-flash:free    # free tier, rate-limited
OPENROUTER_MODEL=meta-llama/llama-4-scout:free   # free tier, rate-limited
```

The memory layer and graph layer store identical records regardless of which model is in use.

---

## Grounded document QA

Grounded document QA is disabled by default. Enable it when chat answers should be constrained to public ingested document evidence rather than the usual memory prompt.

```env
GROUNDED_RETRIEVAL=true
GROUNDED_EVIDENCE_LIMIT=8
```

When enabled, the chat path works in four stages. First, `MemoryGraphService::retrieveContext()` selects public graph nodes as usual. Second, `EvidenceRetrievalService` loads matching `evidence_facts` from the retrieved public document chunks and ranks them against the current user question. Third, `LlmService::buildGroundedSystemPrompt()` builds a strict evidence prompt. Fourth, the model receives only that prompt and the current redacted user question, not the full prior chat history.

The response JSON includes `grounded_retrieval` and `evidence_fact_ids` so tests and future audit tooling can see whether grounded mode ran and which facts were supplied. The current retrieval scorer is lexical, not embedding-based, and the current verifier is prompt-level only. Post-generation claim verification and `grounded_answer_traces` remain future work.

---

## Redaction policy

Redaction is enabled by default through `REDACTION_ENABLED=true`. The deployment preset is controlled by `REDACTION_PRESET`, with `personal`, `professional`, and `regulated` presets defined in `app/config/redaction.php`. Deterministic redaction tokens use an HMAC key from `REDACTION_HASH_KEY`; when that is unset, the application key is used.

The floor categories cannot be disabled by user policy: payment cards, CVV, bank routing and account numbers, IBANs, SSNs, SINs, credentials, JWTs, private keys, and minor-age details. These values are replaced before the LLM sees the turn, before the transcript is stored, before memory summaries are written, before document chunks are extracted, and before MCP writes enter the graph.

User-specific policy rows live in `redaction_policies`. A row can select a preset and override configurable categories such as email, phone, street address, date of birth, compensation, and health-condition text. Compensation defaults to abstraction rather than deletion, so a precise salary can become a bracket such as `[COMPENSATION:100K_200K]`.

---

## The memory graph explorer

The graph explorer is at `/graph`. It renders the full memory graph as a D3 force-directed visualization with a radial gradient background and an SVG glow filter applied to every node so that each node type glows in its assigned color. Three views are available: the graph (nodes and weighted edges in 2D space), a timeline (nodes in chronological order), and a list (filterable grid).

The left panel controls node type filters, sensitivity filters, and content search, with live counts for nodes, edges, and clusters. The right panel shows node detail on click: type, sensitivity, label, content, tags, and connected nodes with relationship labels. Clicking "Expand neighborhood" fetches the two-hop neighborhood from the server and merges it into the current view.

Node radius scales with degree count. Edge width and edge color both reflect the current Physarum weight and source node type: thick, brightly tinted edges are Hebbian paths the agent has traversed frequently; thin edges are dormant connections decaying toward the floor. Running the Physarum simulation from the bottom control bar animates edge width transitions and flashes a ring on each active node.

The Three.js mission control surface is at `/3d`. It renders the multi-agent graph in three-dimensional space against a starfield of 2800 background stars. Every node has a glow aura scaled to match its degree: hub nodes with many connections appear as large bright orbs; leaf nodes appear small. Node radius scales continuously with degree count so the scale-free topology is visible at a glance without reading a number. Agent partitions occupy distinct spatial regions arranged in a ring. Shared nodes float at partition boundaries in violet.

When the simulation runs, traversal particles travel along edge geometry from active nodes toward their neighbors. Each particle eases in and out along the edge path so the motion reads as a propagating signal rather than a linear slide. High-weight edges emit a return particle as well, giving a bidirectional signal appearance on heavily reinforced paths. Each active node simultaneously emits an expanding wireframe pulse ring that grows from radius 1 to radius 12 and fades to transparent over one second. The combined effect makes the Physarum traversal visible as live signal propagation rather than as a flashing indicator. A temporal axis scrubber replays cluster snapshots from the past 24 hours. An intent alignment panel shows pairwise Jaccard similarity of agent active content sets. The camera performs a slow auto-orbit that pauses while the simulation is running.

The multi-agent simulation is at `/agents`. Create agents, adjust trust scores with a color-coded trust bar that fills green for high-trust agents and red for low-trust ones, seed each agent's partition from the owner's public nodes, and run per-agent or collective simulation ticks to observe how shared edge weights evolve. Nodes that appear in more than one agent's active retrieval set are highlighted with a violet border and a full-width weight bar. The "Seed demo day" button in the left sidebar runs `simulate:day` from the browser without requiring terminal access, with an option to wipe existing data first.

---

## Seeding demo data

The `simulate:day` command generates a realistic 8-hour workday of memory activity without requiring an API key or a live ICP canister. It creates memory nodes across four topic clusters (technical decisions, project planning, research concepts, and personal workflow), wires edges, runs six Physarum reinforcement turns, creates three agents with different trust scores, and takes a graph snapshot. All five surfaces have data to render after it completes.

```bash
php artisan simulate:day                 # 40 memories (default)
php artisan simulate:day --fresh         # wipe existing demo data first
php artisan simulate:day --memories=60   # denser graph
```

After the command completes, navigate to `/graph` to see the memory graph, `/agents` to see Nexus, Beacon, and Ghost, and `/3d` for the mission control surface.

---

## Scheduled maintenance commands

**Edge weight decay** runs daily and applies the Physarum decay factor to all edges:

```bash
php artisan memory:decay
```

The decay constant RHO = 0.97 means edges lose approximately 3% of their weight per day when not reinforced. An edge at weight 0.5 that receives no reinforcement reaches the floor (0.05) after approximately 100 days.

**Cluster consolidation** runs weekly and compresses high-density episodic clusters into semantic concept nodes:

```bash
php artisan memory:consolidate           # all users
php artisan memory:consolidate --user=X  # specific user
```

**Node pruning** runs monthly and hard-deletes dormant nodes whose edges have all decayed to floor weight:

```bash
php artisan memory:prune                 # all users
php artisan memory:prune --user=X        # specific user
php artisan memory:prune --dry-run       # report without deleting
php artisan memory:prune --days=60       # shorter idle threshold
```

All three commands are scheduled automatically via `routes/console.php`. Both consolidation and pruning are also triggerable from the graph explorer UI at `/graph` without terminal access.

---

## Research diagnostics

**Retrieval benchmark** compares three context selection strategies against synthetic user-memory corpora: flat recency, weight-only graph retrieval, and goal-biased graph retrieval. Each corpus is seeded into an isolated benchmark user partition, judged with an LLM-as-judge rubric, then deleted unless `--keep` is passed. Reports are written to `storage/benchmarks/`. `--ablate-goals` runs a second pass with goal nodes excluded and adds a with-goals vs without-goals comparison table to the report.

```bash
php artisan benchmark:retrieval
php artisan benchmark:retrieval --strategies=recency,goal_graph
php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_04_longhorizon_engineer.json
php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_04_longhorizon_engineer.json --ablate-goals
php artisan benchmark:retrieval --keep
```

As of the 2026-04-22 benchmark runs, recency still leads on composite score. On the harder long-horizon corpus, the gap narrows to 2.2%, and goal ablation shows that explicit goal nodes add +0.40 goal alignment while costing -0.20 composite for `goal_graph`. The current claim is goal-coherent retrieval, not universal retrieval superiority.

The benchmark measures retrieval quality only. It does not answer whether the final assistant response improves, because the judged artifact is the retrieved context set rather than the generated answer. If any judge call fails, the command still writes the partial report but exits non-zero and suppresses headline comparison claims.

**Cross-source coherence check** is an on-demand command that injects a synthetic document chunk with tags copied from existing chat nodes and reports whether `same_topic_as` edges form across sources. The command deletes the synthetic nodes unless `--keep` is passed, so it tests graph wiring without leaving permanent artifacts behind.

```bash
php artisan graph:coherence-check
php artisan graph:coherence-check --user=X
php artisan graph:coherence-check --keep
```

This command validates the graph wiring layer only. It does not measure whether `GraphExtractionService` produces overlapping tags for real document content and real chat turns on the same topic.

---

## Connecting a real ICP canister

```bash
# Install dfx
sh -ci "$(curl -fsSL https://internetcomputer.org/install.sh)"

# Start a local replica and deploy the canister
cd icp
dfx start --background
dfx deploy
dfx canister id memory   # note this ID for the next steps

# Start the Node adapter in a separate terminal
cd icp/adapter
npm install
ICP_MOCK=false ICP_CANISTER_ID=<canister-id> node server.js

# Update app/.env
ICP_MOCK_MODE=false
ICP_CANISTER_ENDPOINT=http://localhost:3100
ICP_CANISTER_ID=<canister-id>
ICP_BROWSER_HOST=http://localhost:4943    # use https://ic0.app for mainnet
```

---

## Connecting CLI tools via MCP

Claude Code, Gemini CLI, Codex, and any other MCP-compatible tool can read and write memories through the MCP server. All tools share a single portable Ed25519 identity file, so writes from any tool accumulate under the same principal and appear in the same graph.

```bash
# 1. Install the MCP server dependencies
cd icp/mcp-server
npm install

# 2. Generate the shared identity file (run once)
node setup-identity.js
# Prints your principal and the path of the created file.
# Back the file up. Losing it means losing the ability to reclaim canister-signed records.

# 3. Generate a shared secret for the Laravel endpoint
openssl rand -hex 32
# Add this value to app/.env as MCP_API_KEY=<value>

# 4. Add the MCP server to your tool config
# Example for Claude Code (~/.claude/claude_desktop_config.json):
```

```json
{
  "mcpServers": {
    "openMemory": {
      "command": "node",
      "args": ["/absolute/path/to/icp/mcp-server/server.js"],
      "env": {
        "OMA_MOCK_URL": "http://localhost:8080",
        "OMA_API_KEY": "<value from step 3>",
        "OMA_USER_ID": "<any stable string identifying you>",
        "WRITE_SCOPE": "public,private"
      }
    }
  }
}
```

The `WRITE_SCOPE` env var controls which sensitivity levels the MCP server will accept. The default is `public` only. Set it to `public,private` to allow private writes. Sensitive writes are always blocked at the MCP layer regardless of scope. Set `WRITE_SCOPE=none` to make the server read-only.

In mock mode (`ICP_MOCK_MODE=true`), the MCP server POSTs to `OMA_MOCK_URL/mcp/store` and memory nodes are created in the local PostgreSQL graph. The Laravel endpoint runs the redaction floor before storing content. If a public MCP write contains a floor category such as a bank routing number, the raw value is tokenized and the write is stored as private. In live ICP mode (no `OMA_MOCK_URL` set), the server signs canister calls directly with the loaded identity.

---

## Running tests

```bash
cd app
php artisan test
npm run test:front
```

The backend test suite runs against SQLite in-memory and mock mode throughout. No API key or canister is required. Coverage includes deterministic redaction policy and floor behavior, per-user redaction policy loading, redaction in chat storage, redaction in MCP writes, redaction in document ingestion, the storage trigger (MemorabilityService decisions, hallucinated node ID rejection, consolidated node exclusion), document chunking and ingestion, evidence fact extraction and quote span derivation, query-aware evidence retrieval, grounded prompt construction, the grounded chat switch, document controller routes, goal-biased retrieval seed selection, graph and recency retrieval strategy selection, benchmark cleanup behavior, goal ablation and `goals_excluded` metadata, graph reinforcement, edge decay, neighborhood traversal, cluster detection determinism, graph snapshot storage and pruning, agent alignment Jaccard calculation, the memory approval flow, the `active_node_ids` response field, consolidation pipeline (concept node creation, supersedes edges, sensitivity inheritance, re-consolidation prevention), node pruning (floor-weight detection, idle window, edge cascade delete, user scoping, dry-run), the MCP store endpoint (API key auth, graph node creation), and the ThreeD page load with agent scoping. The frontend Vitest coverage checks redacted chat rendering, sensitive-memory approval, and live browser graph sync typing.

---

## Project structure

```
OpenMemory/
├── app/                          # Laravel application
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   ├── DecayMemoryEdges.php         # php artisan memory:decay (daily)
│   │   │   ├── ConsolidateMemory.php        # php artisan memory:consolidate (weekly)
│   │   │   ├── PruneMemoryNodes.php         # php artisan memory:prune (monthly)
│   │   │   ├── TakeGraphSnapshot.php        # php artisan graph:snapshot (runs every 15 min)
│   │   │   ├── GraphCoherenceCheck.php      # php artisan graph:coherence-check
│   │   │   ├── BenchmarkRetrieval.php       # php artisan benchmark:retrieval
│   │   │   └── SimulateDay.php              # php artisan simulate:day (demo seeder)
│   │   ├── Http/Controllers/
│   │   │   ├── ChatController.php           # chat, memory store, graph sync endpoints
│   │   │   ├── DocumentController.php       # document ingest and document anchor listing
│   │   │   ├── GraphController.php          # graph data, neighborhood, clusters, snapshots, /3d
│   │   │   ├── AgentController.php          # agent CRUD, seed, simulate, alignment, shared edges
│   │   │   ├── McpController.php            # POST /mcp/store — MCP server write endpoint (mock mode)
│   │   │   └── MemoryController.php
│   │   ├── Services/
│   │   │   ├── IcpMemoryService.php         # fetches public records for LLM context
│   │   │   ├── DocumentChunkerService.php   # paragraph-aware document chunking for graph extraction
│   │   │   ├── DocumentIngestionService.php # document anchor creation, chunk ingest, part_of wiring
│   │   │   ├── EvidenceFactExtractionService.php # source-backed fact extraction from document chunks
│   │   │   ├── EvidenceRetrievalService.php # query-aware fact selection for grounded document QA
│   │   │   ├── MemorabilityService.php      # storage trigger: evaluates novelty/significance before writing
│   │   │   ├── MemorySummarizationService.php
│   │   │   ├── RedactionService.php          # deterministic PII, financial, and credential redaction
│   │   │   ├── RedactionResult.php           # redacted text plus category metadata
│   │   │   ├── ConsolidationService.php     # compresses episodic clusters into concept nodes (weekly)
│   │   │   ├── GraphExtractionService.php   # LLM extracts node type, label, tags per memory or document chunk
│   │   │   ├── MemoryGraphService.php       # stores nodes, wires edges, Physarum dynamics
│   │   │   ├── ClusterDetectionService.php  # weighted label propagation community detection
│   │   │   ├── MultiAgentGraphService.php   # collective Physarum, shared edges, agent seeding
│   │   │   ├── BenchmarkService.php         # retrieval strategy benchmark harness
│   │   │   └── LLM/
│   │   │       ├── LlmProviderInterface.php
│   │   │       ├── LlmService.php
│   │   │       └── OpenRouterProvider.php
│   │   └── Models/
│   │       ├── Message.php
│   │       ├── EvidenceFact.php             # source-backed fact with quote span metadata
│   │       ├── MemoryNode.php               # typed graph node with access tracking
│   │       ├── MemoryEdge.php               # directed edge with Physarum weight
│   │       ├── RedactionPolicy.php          # per-user redaction preset and overrides
│   │       ├── Agent.php                    # agent record with trust_score and graph_user_id
│   │       ├── SharedMemoryEdge.php         # cross-agent edge keyed by content hash
│   │       └── GraphSnapshot.php            # cluster payload for one 15-minute interval
│   ├── database/migrations/
│   │   ├── ..._create_memory_nodes_table.php
│   │   ├── ..._create_memory_edges_table.php
│   │   ├── ..._add_access_tracking_to_memory_graph.php
│   │   ├── ..._add_consolidated_at_to_memory_nodes.php
│   │   ├── ..._add_goal_type_to_memory_nodes.php
│   │   ├── ..._create_redaction_policies_table.php
│   │   ├── ..._create_agents_table.php
│   │   ├── ..._create_shared_memory_edges_table.php
│   │   ├── ..._create_graph_snapshots_table.php
│   │   └── ..._create_evidence_facts_table.php
│   ├── database/benchmarks/
│   │   ├── corpus_01_software_developer.json
│   │   ├── corpus_02_researcher.json
│   │   └── corpus_03_business_owner.json
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── Chat/Index.vue               # chat interface and My Memories panel
│   │   │   ├── Memory/Index.vue             # flat memory inspector
│   │   │   ├── Memory/Graph.vue             # D3 force-directed graph explorer
│   │   │   ├── Memory/ThreeD.vue            # Three.js mission control surface
│   │   │   └── Agents/Index.vue             # graph partition simulation panel
│   │   └── composables/
│   │       ├── useIcpIdentity.js            # Internet Identity AuthClient adapter; exposes identity, principal, isAuthenticated, isReady
│   │       └── useIcpMemory.js              # browser-signed writes and owner-authenticated reads
│   └── tests/Feature/
├── icp/
│   ├── src/memory/
│   │   ├── main.mo                          # Motoko canister source
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js                        # read-only adapter in live mode; mock store in mock mode
│   ├── mcp-server/
│   │   ├── server.js                        # MCP protocol endpoint; any MCP-compatible AI connects here
│   │   ├── identity.js                      # loads portable Ed25519 identity from ~/.config/openmemory/
│   │   └── setup-identity.js                # one-time identity generation script
│   └── dfx.json
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
├── docker-compose.yml
├── LICENSE
├── CONTRIBUTING.md                          # contribution rules, including writing standard
├── VISION.md                                # research position: design decisions, what this proves, open questions
├── DEVLOG.md                                # captain's log: what was discovered building it, entry by entry
├── RESEARCH.md                              # active research agenda: open scientific claims and what needs to be built to test them
└── SCIENCE.md                               # plain-language explanations of the mathematics and biology behind the graph layer
```

The benchmark corpora live in `app/database/benchmarks/`. Corpora 01-03 are the compact persona sets used in the first complete run. `corpus_04_longhorizon_engineer.json` is the harder 12-month retrieval challenge added for the long-horizon pass. `BenchmarkService` and `benchmark:retrieval` also support goal ablation, so the same corpus can be rerun with goal nodes excluded when measuring Claim 3.

---

## What each layer does

| Layer | Role |
|---|---|
| Laravel | Request handling, LLM orchestration, memory summarization, graph extraction, public-only context retrieval |
| Vue + Inertia | Chat interface, identity management, browser-signed writes, approval dialogs, graph explorer |
| useIcpIdentity.js | Internet Identity AuthClient adapter; manages login/logout lifecycle and exposes the authenticated principal |
| useIcpMemory.js | Browser actor for signing store_memory calls and retrieving the owner's full record set |
| PostgreSQL | Chat transcript, session data, memory graph (nodes, edges, Physarum weights) |
| RedactionService | Deterministic local redaction and tokenization before LLM, transcript, graph, document, MCP, and storage boundaries |
| RedactionPolicy | Per-user preset and category overrides for configurable redaction behavior |
| MemorabilityService | Pre-write filter evaluating novelty, significance, durability, and connection richness; returns store/update/skip |
| DocumentChunkerService | Splits pasted text or Markdown into paragraph-aware chunks sized for graph extraction |
| DocumentIngestionService | Redacts document text, creates document anchor nodes, stores chunk nodes, and wires `part_of` edges back to the source document |
| EvidenceFactExtractionService | Extracts source-backed facts from document chunks and stores quote-derived span metadata |
| EvidenceRetrievalService | Selects evidence facts from graph-retrieved document chunks for grounded document QA |
| GraphExtractionService | LLM pass after each confirmed memory write or document chunk; extracts node type, label, tags, people, projects |
| MemoryGraphService | Creates nodes, auto-wires edges, applies Hebbian reinforcement, runs Physarum decay |
| BenchmarkService | Seeds isolated corpora and scores retrieval strategies with an LLM-as-judge rubric |
| ConsolidationService | Weekly: compresses high-density episodic clusters into semantic concept nodes via LLM summarization |
| ClusterDetectionService | Weighted label propagation producing community membership and mean weight per cluster |
| MultiAgentGraphService | Creates and seeds graph partitions, updates shared edges with trust-weighted ALPHA, retrieves collective context |
| IcpMemoryService | Fetches public memories from the adapter for injection into the LLM system prompt |
| McpController | Receives write requests from the MCP server (mock mode); authenticates via X-OMA-API-Key |
| ICP adapter | Translates HTTP JSON from Laravel into Candid query calls; read-only in live mode |
| ICP canister | Enforces msg.caller as record owner and serves JSON records over the HTTP gateway |
| MCP server | Exposes memory as MCP tools for any MCP-compatible AI; reads public records and writes new memories via store_memory |
| OpenRouter | Routes LLM calls to whichever model is set in OPENROUTER_MODEL |

---

## Documentation writing standard

All markdown in this repository follows a plain research writing style. The full rules are in [CONTRIBUTING.md](./CONTRIBUTING.md). The short version: no em-dashes, no corporate language, no sentence fragments, no repetition. Write as if explaining the system to another researcher who will read critically.

---

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md) for setup instructions, scope guidance, the memory type preservation rules, and the documentation writing standard.
