# OpenMemoryAgent

Built because working across Claude, Codex, Gemini CLI, and other AI tools means re-explaining project context from scratch every time you switch. Each AI starts fresh at every session boundary. This is the memory layer that lives outside any single AI so all of them stay in sync.

The MCP server at `icp/mcp-server/server.js` is the protocol endpoint through which any MCP-compatible AI reads and queries the memory graph. Configure it once, and every AI you connect to it already knows what you have been working on. The chat interface in this repository is the reference implementation demonstrating that the infrastructure works end-to-end.

The user's browser holds an Ed25519 signing key; writes to the ICP canister are authenticated with that key so no server can write memory records under a user's identity. A typed memory graph sits in PostgreSQL alongside the canister records, tracking relationships between memories and applying Physarum conductance dynamics that shift edge weights based on how the LLM actually uses each connection over time.

[VISION.md](./VISION.md) covers the design decisions and research questions in depth. [DEVLOG.md](./DEVLOG.md) is the running record of what was discovered building it: implementation findings, security fixes, architectural tensions, and what remains unresolved. [RESEARCH.md](./RESEARCH.md) is the active research agenda: the open scientific claims, what needs to be built to test each one, and how the tracks evolve as discoveries open new questions. [SCIENCE.md](./SCIENCE.md) explains the mathematics and biology behind the graph layer in plain terms, with source citations and references to the tests that verify each formula.

---

## How it works

The application is a standard Laravel and Vue web app. The interesting parts are the memory layer and the graph that grows on top of it.

**Memory records.** When a conversation produces something worth remembering, the server summarizes it, classifies it as public, private, or sensitive, and returns it to the browser. If the user approves (required for private and sensitive records), the browser signs the write with an Ed25519 key from localStorage and sends it to the ICP canister directly. The canister records `msg.caller` as the owner of that record. The server is not in that write path and cannot forge a write under the user's principal.

**The memory graph.** After every confirmed memory write, a second LLM call extracts a node type (memory, person, project, document, task, event, or concept), a label, semantic tags, named people, and named projects from the memory content. This data creates a typed graph node in PostgreSQL and auto-wires edges to related existing nodes: tag overlap produces `same_topic_as` edges; named people produce `about_person` edges to person anchor nodes; named projects produce `part_of` edges to project anchor nodes.

**Physarum dynamics.** Edge weights are not static. When the LLM retrieves a set of memory nodes to build a response, all edges between those co-accessed nodes receive a conductance increment of ALPHA = 0.10, clamped to 1.0. A daily scheduled command applies a decay factor of RHO = 0.97 to all edges, floored at 0.05. Edges that are traversed together regularly accumulate weight; edges between memories that the agent never retrieves together decay toward the floor. This implements the discrete form of the Tero et al. (2010) slime mold conductance model: paths the organism uses frequently develop higher conductance, and paths that carry no flux thin out.

**LLM recall.** Only public memories are loaded into the LLM context. Private and sensitive records are gated by `msg.caller` on the canister: anonymous callers (the server adapter, the MCP server, and external HTTP clients) receive only public records.

**Active node IDs.** The `/chat/send` response includes an `active_node_ids` field listing the graph nodes loaded into context that turn. The Three.js mission control surface at `/3d` reads this field to highlight which nodes were active on the most recent turn.

**Graph partition simulation.** Multiple named graph partitions can be created under the same owner at `/agents`. Each partition holds its own subgraph seeded from the owner's public nodes. When two partitions both access nodes derived from the same memory content, a shared edge accumulates weight at `SHARED_ALPHA * trust_score`. The trust score is adjustable per partition, making each one's contribution to the collective graph proportional to its assigned reputation. These partitions model different agent roles or conversation contexts sharing a common memory substrate; actual external AI agents connect via the MCP server rather than through this simulation panel.

**Cluster detection.** Weighted label propagation (Raghavan et al. 2007) partitions the personal or collective graph into communities on demand. Cluster membership and mean internal weight are written to `graph_snapshots` every 15 minutes and feed the temporal axis scrubber in the Three.js surface.


---

## Memory types

The three memory tiers are the core of the trust model:

| Type | LLM context | Owner panel | Requires approval |
|---|---|---|---|
| public | Yes | Yes | No |
| private | No | Yes | Yes |
| sensitive | No | Yes | Yes |

Public memories are the only records the LLM can recall. Private and sensitive records are owner-gated at the canister level, not just by application code. The graph layer inherits the sensitivity of the source memory record: anchor nodes created for a private memory are themselves private, so they do not appear in the public graph.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| Frontend | Vue 3, Inertia.js, Tailwind CSS |
| Database | PostgreSQL (Docker) or SQLite (local development) |
| LLM | OpenRouter, model configurable via `OPENROUTER_MODEL` |
| Memory records | ICP canister (Motoko), browser-signed writes, Node.js adapter for server reads |
| Memory graph | PostgreSQL tables (`memory_nodes`, `memory_edges`), Physarum dynamics, D3 force-directed explorer at `/graph` |
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

## Edge weight decay

A daily Artisan command applies the Physarum decay factor to all edges in the graph:

```bash
php artisan memory:decay
```

This is scheduled to run automatically via `routes/console.php`. The decay constant RHO = 0.97 means edges lose approximately 3% of their weight per day when not reinforced. An edge at weight 0.5 that receives no reinforcement reaches the floor (0.05) after approximately 100 days.

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

## Running tests

```bash
cd app
php artisan test
```

The test suite runs against SQLite in-memory and mock mode throughout. No API key or canister is required. Coverage includes graph reinforcement, edge decay, neighborhood traversal, cluster detection determinism, graph snapshot storage and pruning, agent alignment Jaccard calculation, the memory approval flow, the `active_node_ids` response field, and the ThreeD page load with agent scoping.

---

## Project structure

```
OpenMemoryAgent/
├── app/                          # Laravel application
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   ├── DecayMemoryEdges.php         # php artisan memory:decay
│   │   │   ├── TakeGraphSnapshot.php        # php artisan graph:snapshot (runs every 15 min)
│   │   │   └── SimulateDay.php              # php artisan simulate:day (demo seeder)
│   │   ├── Http/Controllers/
│   │   │   ├── ChatController.php           # chat, memory store, graph sync endpoints
│   │   │   ├── GraphController.php          # graph data, neighborhood, clusters, snapshots, /3d
│   │   │   ├── AgentController.php          # agent CRUD, seed, simulate, alignment, shared edges
│   │   │   └── MemoryController.php
│   │   ├── Services/
│   │   │   ├── IcpMemoryService.php         # fetches public records for LLM context
│   │   │   ├── MemorySummarizationService.php
│   │   │   ├── GraphExtractionService.php   # LLM extracts node type, label, tags per memory
│   │   │   ├── MemoryGraphService.php       # stores nodes, wires edges, Physarum dynamics
│   │   │   ├── ClusterDetectionService.php  # weighted label propagation community detection
│   │   │   ├── MultiAgentGraphService.php   # collective Physarum, shared edges, agent seeding
│   │   │   └── LLM/
│   │   │       ├── LlmProviderInterface.php
│   │   │       ├── LlmService.php
│   │   │       └── OpenRouterProvider.php
│   │   └── Models/
│   │       ├── Message.php
│   │       ├── MemoryNode.php               # typed graph node with access tracking
│   │       ├── MemoryEdge.php               # directed edge with Physarum weight
│   │       ├── Agent.php                    # agent record with trust_score and graph_user_id
│   │       ├── SharedMemoryEdge.php         # cross-agent edge keyed by content hash
│   │       └── GraphSnapshot.php            # cluster payload for one 15-minute interval
│   ├── database/migrations/
│   │   ├── ..._create_memory_nodes_table.php
│   │   ├── ..._create_memory_edges_table.php
│   │   ├── ..._add_access_tracking_to_memory_graph.php
│   │   ├── ..._create_agents_table.php
│   │   ├── ..._create_shared_memory_edges_table.php
│   │   └── ..._create_graph_snapshots_table.php
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── Chat/Index.vue               # chat interface and My Memories panel
│   │   │   ├── Memory/Index.vue             # flat memory inspector
│   │   │   ├── Memory/Graph.vue             # D3 force-directed graph explorer
│   │   │   ├── Memory/ThreeD.vue            # Three.js mission control surface
│   │   │   └── Agents/Index.vue             # graph partition simulation panel
│   │   └── composables/
│   │       ├── useIcpIdentity.js            # Ed25519 key generation and localStorage persistence
│   │       └── useIcpMemory.js              # browser-signed writes and owner-authenticated reads
│   └── tests/Feature/
├── icp/
│   ├── src/memory/
│   │   ├── main.mo                          # Motoko canister source
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js                        # read-only adapter in live mode; mock store in mock mode
│   ├── mcp-server/
│   │   └── server.js                        # MCP protocol endpoint; any MCP-compatible AI connects here
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

---

## What each layer does

| Layer | Role |
|---|---|
| Laravel | Request handling, LLM orchestration, memory summarization, graph extraction, public-only context retrieval |
| Vue + Inertia | Chat interface, identity management, browser-signed writes, approval dialogs, graph explorer |
| useIcpIdentity.js | Generates an Ed25519 key pair in browser localStorage and derives the ICP principal |
| useIcpMemory.js | Browser actor for signing store_memory calls and retrieving the owner's full record set |
| PostgreSQL | Chat transcript, session data, memory graph (nodes, edges, Physarum weights) |
| GraphExtractionService | Second LLM pass after each confirmed memory write; extracts node type, label, tags, people, projects |
| MemoryGraphService | Creates nodes, auto-wires edges, applies Hebbian reinforcement, runs Physarum decay |
| ClusterDetectionService | Weighted label propagation producing community membership and mean weight per cluster |
| MultiAgentGraphService | Creates and seeds graph partitions, updates shared edges with trust-weighted ALPHA, retrieves collective context |
| IcpMemoryService | Fetches public memories from the adapter for injection into the LLM system prompt |
| ICP adapter | Translates HTTP JSON from Laravel into Candid query calls; read-only in live mode |
| ICP canister | Enforces msg.caller as record owner and serves JSON records over the HTTP gateway |
| MCP server | Exposes public memories as MCP tools so any MCP-compatible AI can read the user's memory graph |
| OpenRouter | Routes LLM calls to whichever model is set in OPENROUTER_MODEL |

---

## Documentation writing standard

All markdown in this repository follows a plain research writing style. The full rules are in [CONTRIBUTING.md](./CONTRIBUTING.md). The short version: no em-dashes, no corporate language, no sentence fragments, no repetition. Write as if explaining the system to another researcher who will read critically.

---

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md) for setup instructions, scope guidance, the memory type preservation rules, and the documentation writing standard.
