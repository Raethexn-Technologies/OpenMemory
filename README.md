# OpenMemory

Your history with AI, in one place you control.

Most people now talk to several assistants. Career decisions end up in one, months of personal reflection in another, programming work in a third. Each provider holds a partial record of the same person, and none of them can see the others. The person is the constant. The provider should not be the boundary around their memory.

OpenMemory brings that history together. It imports conversation exports from ChatGPT, Claude, and Gemini into one local corpus, preserves the original source of every conversation, and makes the whole thing searchable and answerable with evidence you can open and read.

Imported history and legacy live-memory integrations remain separate from the native store described below:

**Historical memory.** Conversation archives you export from providers. `memory:import-archive` reads an export where it already sits on disk, normalizes it into a provider-neutral model, and keeps the exact provider JSON alongside the normalized rows. Imports are idempotent, so re-exporting next month merges rather than duplicates.

**Live memory.** Durable facts written and recalled while you work, through MCP. Store a decision in Codex, recall it from Claude Code or Gemini CLI without re-explaining the project. This was the original product, and its existing MCP interfaces remain available. It is now one source of memory rather than the whole category.

The interesting question is not only "find the conversation where I talked about X". Search is necessary and not sufficient. The question a multi-year, multi-provider corpus makes answerable is what someone could learn about themselves from it: what subjects they keep returning to, what they planned and never mentioned again, when an interest first appeared, where their position changed, and which patterns are invisible while each provider's history sits in its own silo.

Every claim OpenMemory makes about that history has to be traceable. An answer cites specific messages; each citation resolves to a stored row with a provider, a timestamp, and a conversation you can open. Where the record supports an observation, it says so and shows the evidence. Where it does not, it says that instead. It does not tell people what they are like.

[ROADMAP.md](./ROADMAP.md) defines the direction. [ADOPTION.md](./ADOPTION.md) defines the demo and community path. [VISION.md](./VISION.md) covers the design decisions and research questions in depth. [DEVLOG.md](./DEVLOG.md) is the running record of what was discovered building it. [RESEARCH.md](./RESEARCH.md) is the active research agenda. [SCIENCE.md](./SCIENCE.md) explains the mathematics and biology behind the graph layer.

The [federated context report](./docs/architecture/FEDERATED_CONTEXT_REPORT.md) is the working architectural direction. Its [ADRs](./docs/adr/README.md) remain proposed where implementation has not validated them.

## Durable native memory

OpenMemory now provides private, intentionally saved native memory in local SQL storage. Sign in and open /native-memory to create, inspect, correct, supersede, archive, delete, export, and import statements without an LLM, ICP, or OpenMemory Cloud.

Imported conversations remain sources, not automatically canonical memories. The native store is also separate from the prunable graph and legacy MCP/canister records, and existing model permissions do not grant access to it.

Read the [native-memory guide](./docs/architecture/NATIVE_MEMORY.md) for lifecycle semantics, authenticated API usage, self-hosting, and the inspectable openmemory-export-v1 format. The [implementation report](./docs/architecture/NATIVE_MEMORY_IMPLEMENTATION.md) records verification and remaining limitations.

## Local context and application grants

The local resolver returns bounded evidence from active native memory and the authenticated owner's imported history, with provenance and explicit source outcomes. It requires no model, external provider, ICP service, or vector database.

Owners can register applications at /applications and separately grant retrieval and disclosure for each source. Bearer credentials do not authenticate owner routes or MCP, and no native-write or raw-history capability is granted. Read the [context contracts and grant model](./docs/architecture/LOCAL_CONTEXT.md) before authorizing a consumer.

Context remains transient, and resolving it neither creates memory nor invokes a model. The [Phase 4 report](./docs/architecture/LOCAL_CONTEXT_IMPLEMENTATION.md) records verification, compatibility, and remaining limitations.

## Disclosure defaults

Model processing is disabled until the operator explicitly permits an operation. History Ask additionally requires an unchecked per-request choice before selected excerpts leave for a model. Documents default to private local processing, and all chat memory proposals require review before storage.

Read the [disclosure trust model](./docs/architecture/DISCLOSURE_BOUNDARY.md) before enabling model calls or public ingestion. The [security-phase report](./docs/architecture/DISCLOSURE_IMPLEMENTATION.md) records verification and remaining limitations.

## Try it

Before browsing private data, follow [authenticated ownership setup](./docs/architecture/OWNERSHIP_SETUP.md) to create a local login and bind any existing corpus. Imports remain local and do not require a browser session.

Import an export you already have:

```bash
# Request an export from the provider first. See "Importing your AI history" below.
node bin/openmemory.js import-archive ~/Downloads/chatgpt-export.zip --user me --dry-run
node bin/openmemory.js import-archive ~/Downloads/chatgpt-export.zip --user me
```

Bind the `me` namespace to your local account, sign in at `/login`, and open `/history` to inspect the corpus.

Or try the cross-tool live memory workflow:

1. Start the Laravel application in mock mode, then configure the MCP server as shown in [Connecting CLI tools via MCP](#connecting-cli-tools-via-mcp).
2. In one connected tool, explicitly ask it to remember a durable project decision.
3. In a second connected tool, ask a question about that decision. The tool should call `search_memories` before answering and receive only matching public records.

The local product loop is handled by the root CLI:

```bash
node bin/openmemory.js doctor
node bin/openmemory.js setup-clients mock
node bin/openmemory.js import all --dry-run
```

The research graph is an experimental retrieval layer, not a requirement for trusting the product.

Browser access to local user data uses Laravel password authentication. A unique ownership binding selects the account's corpus and legacy local records. Internet Identity remains a separate provider identity for signed canister writes and owner reads; browser-supplied principal strings do not authenticate Laravel.

Local imports accept an owner key through `--user` or `OPENMEMORY_LOCAL_USER_ID`. Neither establishes browser authority. Existing corpora require an explicit `openmemory:corpus:bind` command before the authenticated owner can inspect them. The existing MCP application key remains separate from both browser login and provider identity.

---

## Importing your AI history

`memory:import-archive` reads a provider export into the local corpus. The archive is read where it already sits: nothing is uploaded, no copy is unpacked to disk, and the whole path is local. Parsing, hashing, normalization, and redaction involve no network call and no model call.

```bash
# Auto-detect the provider, report what would happen, write nothing.
php artisan memory:import-archive ~/Downloads/chatgpt-export.zip --user=me --dry-run

# Import for real.
php artisan memory:import-archive ~/Downloads/chatgpt-export.zip --user=me

# Or through the root CLI, which passes options straight through.
node bin/openmemory.js import-archive ~/Downloads/claude-export.zip --user me
```

Set `OPENMEMORY_LOCAL_USER_ID` in `.env` to the explicitly bound import-owner key if you want `--user` to be optional. The value is only a CLI default and grants no browser access.

A ZIP, an extracted folder, and a bare `conversations.json` are all accepted. The report says what happened:

```
2,814 conversations detected
19,403 messages normalized
324 new conversations
2,490 already known
7 conversations changed
2,814 raw source records stored
0 records skipped
```

### Getting an export

| Provider | How to export | What the parser reads |
|---|---|---|
| ChatGPT | Settings, then Data Controls, then Export data. OpenAI emails a link to a ZIP. | `conversations.json` |
| Claude | Settings, then Privacy, then Export data, on the web or desktop app. Available on Free, Pro, and Max; the emailed link expires after 24 hours. | `conversations.json` |
| Gemini | takeout.google.com, deselect all, choose My Activity, select only Gemini Apps, and change the activity record format from HTML to JSON. | `Takeout/My Activity/Gemini Apps/MyActivity.json` |

Takeout defaults to HTML, which is the step people most often miss. An HTML-only export is refused with that specific instruction rather than parsed approximately.

None of these formats is a published API. Providers document how to request an export, not what the JSON contains, and the structures have changed before. The adapters are written defensively against synthetic fixtures in `app/tests/Support/ConversationFixtures.php`, which are the executable description of the shape each parser expects. When a format moves, those fixtures are what fails first.

Gemini is the one provider whose export is not a conversation export. Takeout ships Gemini Apps history as an activity log, where each record is a single timestamped turn and nothing identifies which turns belonged to the same thread. Each record therefore becomes one conversation of at most two messages, marked with grain `activity_record`. Grouping records into threads by proximity in time would be an invention, and an invented thread boundary corrupts any later reasoning about how a discussion developed. The raw record is preserved, so a future parser can group turns properly without anyone re-exporting.

### What the parsers preserve

**Provider-neutral model.** Adapters transform an archive into `conversations` and `conversation_messages` rows that do not privilege any provider's shape. Fields an archive does not supply stay null rather than being guessed, because an invented timestamp corrupts exactly the temporal reasoning the corpus exists to support. Provider-specific structure that normalization would lose is kept verbatim under `provider_metadata`.

**Branches.** A ChatGPT conversation is a tree, not a list: editing a prompt forks it. The parser walks back from `current_node` to establish the branch that was kept, marks those messages `on_active_path`, and stores the abandoned branches too. Retrieval reads the active path by default and can be asked for the rest.

**Preserved source.** `conversation_raw_records` holds the exact provider JSON for each conversation. Normalization, redaction, and any later extraction sit above it and can be recomputed from it. A lossy summary never becomes the only surviving copy of what someone said. Raw records are read only through an owner-authenticated route, one conversation at a time, and no retrieval path, prompt builder, or MCP tool queries that table.

**Redaction.** `RedactionService` runs over every message before it is stored as `content_text`, which is the only representation retrieval, prompts, and the UI ever read. The raw record keeps the original. That split is what lets the project keep the source intact without widening the surface through which a secret can escape.

**Idempotency.** Identity is keyed on the provider's own conversation and message identifiers, with deterministic hashes derived from content where a provider supplies none. Content hashes distinguish a changed conversation from one seen again. Messages that exist locally but are absent from a newer archive are kept and reported: a conversation deleted at the provider is still part of the person's history, and an importer that mirrored provider deletions would make the corpus less durable than the silo it was meant to outlast.

**Large archives.** `JsonArrayStreamReader` scans the top-level array structurally and yields one element at a time, so peak memory scales with the largest single conversation rather than with the archive. Each conversation is persisted in its own transaction. Because import is idempotent, a crashed run is resumed by running it again.

**Archive safety.** A ZIP is untrusted input. Entry names are validated against traversal, absolute paths, drive letters, and null bytes. Per-entry size, total size, entry count, and compression ratio are all capped before any parsing begins. Refused entries are reported rather than silently dropped. Limits live in `config/conversations.php`.

### Asking questions of the corpus

The history surface is at `/history`. It has four views.

**Ask** retrieves message-level evidence with deterministic lexical scoring. Explicitly enabled generation can also return an answer prompted to cite its supporting excerpts. Citations are validated after generation: a reference the model invents is reported as unresolved and removed rather than rendered as a source. An unresolvable citation is worse than none, because it looks like proof.

**Explore** lists conversations with provider, title, and date filters, and opens any of them to read the messages, the branches, the attachments, and the preserved source.

**Timeline** tracks one subject through the corpus by counting, with no model involved: how many messages mention it, in which months, under which providers, when it first and last appeared, and how many months in that span contain no mention at all. Every bucket carries the conversation identifiers behind it.

**Imports** lists each import run with its counters, its adapter version, the SHA-256 of the archive it read, and any warnings.

### Where imported history is allowed to go

Imported conversations default to `private` and are never public. Concretely:

- They do not enter the memory graph, so they cannot reach chat recall.
- They are not visible to MCP clients. An agent connected through MCP reads public graph records and cannot pull a person's archived history.
- Only explicitly authorized generation sends question-selected excerpts across a model boundary, capped by `conversations.ask.evidence_limit` and `conversations.ask.excerpt_chars`.
- Setting `CONVERSATIONS_ASK_GENERATE_ANSWER=false` disables generation entirely. Ask still works and returns evidence, and nothing is sent to a model.

That last boundary is the point. Sending a message to ChatGPT is not consent to send it to a different provider later. Retrieval is the product; generation is a convenience layered on top of it.

### Imported text is data, not instruction

A corpus of AI history is full of text written by AI systems, much of it instructions, because instructing models is what people use assistants for. Some of it could have been planted.

Evidence excerpts are delimited, labelled with their provider and date, and introduced by a policy stating that instructions found inside them are content to be reported rather than commands to follow. Static application policy and the user's request remain separate from a JSON evidence message. Retrieved text never populates system instructions, although this separation does not guarantee that a model will ignore malicious evidence. Gemini responses, which Takeout stores as HTML, are converted to plain text with script and style bodies removed, so no imported markup is ever rendered.

---

## How it works

The application is a standard Laravel and Vue web app. The interesting parts are the imported conversation corpus, the memory layer, and the graph that grows on top of it.

**Redaction policy.** Before a chat turn, MCP write, document ingest, retrieved memory, or graph-sync payload crosses an LLM or storage boundary, `RedactionService` runs deterministic local checks. Non-negotiable floor categories include payment cards, CVV, bank routing and account numbers, IBANs, SSNs, SINs, credentials, JWTs, private keys, and identifiable minor-age details. These are redacted or tokenized even if a user policy tries to allow them. User-tunable categories include email, phone, street address, date of birth, compensation, and health-condition text. Policies are loaded from `redaction_policies` when present, otherwise the deployment preset in `config/redaction.php` is used.

**Storage trigger.** Before summarizing a conversation turn, the server passes the exchange to `MemorabilityService`, which evaluates novelty, significance, durability, and connection richness against the 20 most recently created nodes. The evaluation returns one of three decisions: store a new node, update an existing node with a specific ID, or skip the turn entirely. This filter prevents ephemeral exchanges (greetings, clarifying questions, transient status updates) from creating nodes, keeping the graph focused on durable knowledge.

**Memory records.** When a redacted turn passes the storage trigger, the server summarizes it, classifies it as public, private, or sensitive, applies a second deterministic redaction pass to the summary, and proceeds down the write path. Redaction findings can only raise sensitivity, never lower it. In the browser chat UI, all proposed memory records require user approval before the browser signs the write with the Internet Identity delegation and sends it directly to the ICP canister. The canister records `msg.caller` as the owner of that record and rejects writes from anonymous principals, so a signed-out browser cannot store memories. CLI tools writing through the MCP server POST to the Laravel `/mcp/store` endpoint in mock mode, which handles redaction, graph extraction, and node storage server-side without a browser session.

**Document ingestion.** `POST /api/documents/ingest` accepts pasted text or Markdown files, redacts the source text, creates a document anchor node, chunks the redacted source with `DocumentChunkerService`, and runs each chunk through the same `GraphExtractionService` used for chat memories. Chunk nodes are stored with `source = 'document'` and connected back to the anchor with `part_of` edges, so uploaded knowledge enters the same graph primitives as chat-derived memory. `EvidenceFactExtractionService` also extracts smaller source-backed facts from each successfully stored chunk and writes them to `evidence_facts` with `source_node_id`, `source_document_id`, quote-derived character spans, confidence, and chunk metadata. `GET /api/documents` lists the document anchors for the current user. The HTTP API defaults ingested documents to `public` because graph-guided retrieval and grounded document QA only load public nodes into the LLM context window; redaction findings can escalate the effective sensitivity to `private` or `sensitive`.

**The memory graph.** After every confirmed memory write or document ingest, an LLM call extracts a node type (memory, person, project, document, task, event, concept, or goal), a label, semantic tags, named people, and named projects from the redacted memory content. Extracted labels and entity fields are sanitized again before storage so redaction placeholders do not become person or project anchors. This data creates a typed graph node in PostgreSQL and auto-wires edges to related existing nodes: tag overlap produces `same_topic_as` edges; named people produce `about_person` edges to person anchor nodes; named projects produce `part_of` edges to project anchor nodes.

**Physarum dynamics.** Edge weights are not static. When the LLM retrieves a set of memory nodes to build a response, all edges between those co-accessed nodes receive a conductance increment of ALPHA = 0.10, clamped to 1.0. A daily scheduled command applies a decay factor of RHO = 0.97 to all edges, floored at 0.05. Edges that are traversed together regularly accumulate weight; edges between memories that the agent never retrieves together decay toward the floor. This implements the discrete form of the Tero et al. (2010) slime mold conductance model: paths the organism uses frequently develop higher conductance, and paths that carry no flux thin out.

**LLM recall.** Only public memories are loaded into the LLM context. Context selection runs one of six strategies, chosen by `RETRIEVAL_STRATEGY` (default `query_lexical`). The query-aware strategies pass the current redacted user message into selection, scoring candidate nodes with deterministic lexical matching against node content, tags, and labels; no embeddings and no extra model calls are involved. Retrieved records are redacted again before prompt injection, which protects legacy records or external writes that predate the redaction layer. Every strategy applies the same public, unconsolidated, same-user filters before a record can enter model context. Private and sensitive records are additionally gated by `msg.caller` on the canister: anonymous callers (the server adapter, the MCP server, and external HTTP clients) receive only public records.

| Strategy | Seed selection | Query-aware | Goal handling |
|---|---|---|---|
| `recency` | Most recently created public nodes, no traversal | No | None |
| `graph` | Highest total connected edge weight | No | Goals compete like any node |
| `goal_graph` | All public goal nodes first, then edge weight | No | Goals always seeded |
| `query_lexical` | Top lexical matches returned directly, no traversal | Yes | None |
| `query_graph` | Lexical relevance to the current message | Yes | Goals compete like any node |
| `hybrid_query_graph` | 0.5 query + 0.3 edge weight + 0.2 recency, normalized | Yes | Goals seeded only when lexically relevant to the message |

A query-aware graph strategy with no usable query terms, or a query matching nothing, degrades deterministically to its query-blind counterpart (`graph` for `query_graph`, `goal_graph` for `hybrid_query_graph`). `query_lexical` falls back to recency when there is no lexical signal. `MemoryGraphService::retrieveContextTraced()` returns the retrieval trace alongside the records: extracted query terms, selected seed IDs with score components, fallback taken, final node IDs, graph-added IDs, traversal depth, and traversed edge count. Traces do not include memory content, labels, or tags. The benchmark stores this trace with every judged result.

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
| public | Only with a model grant | Yes | Yes, for chat proposals |
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
| Imported history | PostgreSQL tables (`conversations`, `conversation_messages`, `conversation_imports`, `conversation_raw_records`), provider adapters for ChatGPT, Claude, and Gemini, review and Ask surface at `/history` |
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
# Optional: configure a model key and explicit MODEL_DISCLOSURE_OPERATIONS
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
# Optional: configure model credentials and explicit disclosure operation grants

docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://localhost:8080.

---

## Mock mode

Setting `ICP_MOCK_MODE=true` (the default) replaces the ICP canister with Laravel's file cache. This is the right way to run the application locally or in CI when you don't have a running dfx replica or deployed canister. With the chat operation explicitly enabled, the LLM calls OpenRouter for responses, but no ICP tooling is required.

Private and sensitive memories show the same approval dialogs in mock mode that they do in live mode. The graph layer runs identically in both modes because it operates entirely within PostgreSQL.

---

## Swapping models

Change `OPENROUTER_MODEL` in `.env` and restart the server. The full model list is at https://openrouter.ai/models.

```env
OPENROUTER_MODEL=anthropic/claude-sonnet-4.5    # current code default
OPENROUTER_MODEL=anthropic/claude-sonnet-5      # newer, stronger
OPENROUTER_MODEL=anthropic/claude-opus-5        # strongest available
OPENROUTER_MODEL=google/gemini-3.5-flash        # faster, lower cost
```

Model identifiers move quickly. These were checked against the OpenRouter catalogue in September 2026; treat the live list as authoritative rather than this table. The code default is still `anthropic/claude-sonnet-4.5`, which remains available but is several generations behind what OpenRouter now offers.

The memory layer, the graph layer, and the imported history corpus store identical records regardless of which model is in use. Import touches no model at all.

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

The `simulate:day` command generates a realistic 8-hour workday of memory activity without requiring an API key or a live ICP canister. It creates memory nodes across four topic clusters (technical decisions, project planning, research concepts, and personal workflow), wires edges, runs six Physarum reinforcement turns, creates three agents with different trust scores, and takes a graph snapshot. The five graph surfaces have data to render after it completes. It does not touch `/history`, which only ever contains conversations you imported yourself.

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

**Retrieval benchmark** compares the six context selection strategies against synthetic user-memory corpora. Each corpus is seeded into an isolated benchmark user partition, judged with an LLM-as-judge rubric, then deleted unless `--keep` is passed. The question text is passed to retrieval as the query; `query_lexical` is the query-aware non-graph control that isolates lexical matching from graph traversal. Reports are written to `storage/benchmarks/`. `--ablate-goals` runs a second pass with goal nodes excluded and adds a with-goals vs without-goals comparison table to the report.

```bash
php artisan benchmark:retrieval
php artisan benchmark:retrieval --strategies=recency,query_lexical,query_graph,hybrid_query_graph
php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_05_longhorizon_product.json
php artisan benchmark:retrieval --corpus=database/benchmarks/corpus_04_longhorizon_engineer.json --ablate-goals
php artisan benchmark:retrieval --keep
```

Alongside the four LLM-judged dimensions, every result records deterministic theme coverage, selected-node count, context tokens, retrieval latency, graph-added node count, traversal depth, and traversed edge count. Theme coverage is model-free, repeatable, and survives judge failures, so strategy comparisons remain auditable when judge variance is in question. The JSON output records the judge model identifier and a per-result retrieval trace (query terms, seed IDs with score components, fallbacks, retrieved node IDs, graph-added IDs). Corpus 05 labels each question with a class (`planning`, `historical_decision`, `cross_topic_synthesis`, `contradiction_resolution`, `recent_status`, `durable_preference`, `insufficient_evidence`), and the report adds a per-class breakdown so strategy differences by question type are visible.

As of the complete six-strategy 2026-07-16 run (`storage/benchmarks/results-2026-07-16_193025.json`, 192/192 judge calls, judge model anthropic/claude-sonnet-4.5), query-aware retrieval substantially outperforms recency and query-blind graph retrieval on the synthetic context benchmark: recency 2.52, graph 2.55, goal_graph 2.55, query_lexical 3.56, query_graph 3.55, hybrid_query_graph 3.52. The lexical-only control is the key result: `query_graph` changed composite by -0.3% relative to `query_lexical`, so this run does not demonstrate measurable graph-traversal value beyond lexical query relevance. Original-corpora composites were query_lexical 3.60, query_graph 3.64, hybrid_query_graph 3.80; the adversarial long-horizon corpus was query_lexical 3.50, query_graph 3.40, hybrid_query_graph 3.06. The full numbers, per-class breakdown, sample size caveats, and limitations are in RESEARCH.md Track 10 Claim 1 and DEVLOG Entry 030.

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

Claude Code, Codex, Gemini CLI, and any other MCP-compatible tool can use the same server. For everyday work, the agent should call `search_memories` with the current task rather than `get_memories`, which is reserved for explicit full-corpus inspection.

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

# 4. Add the MCP server to each tool's MCP configuration.
# The helper prints Codex, Claude, Claude Desktop, and Gemini snippets.
npm run setup-clients -- --mode mock
npm run setup-clients -- --mode live

# Or from the repository root:
node bin/openmemory.js setup-clients mock

# The same configuration works for every local MCP client; adjust only the
# location of the client's own configuration file.

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

In mock mode (`ICP_MOCK_MODE=true`), `OMA_MOCK_URL` points to the Laravel app and the server posts to `/mcp/store`. In live ICP mode, set `OMA_API_URL` to that same application deployment as well. The server first calls `/mcp/prepare`, which redacts content and can raise public content to private. It then signs the prepared record using the local Ed25519 identity, waits for the canister to confirm the write, and calls `/mcp/sync` to index the exact prepared record in the graph. Live writes are refused if `OMA_API_URL` is absent, so they cannot bypass the redaction and indexing path.

---

## Running tests

```bash
npm run test:cli
cd app
php artisan test
npm run test:front
```

The backend test suite runs against SQLite in-memory and mock mode throughout. No API key or canister is required. Coverage includes deterministic redaction policy and floor behavior, per-user redaction policy loading, redaction in chat storage, redaction in MCP writes, redaction in document ingestion, the storage trigger (MemorabilityService decisions, hallucinated node ID rejection, consolidated node exclusion), document chunking and ingestion, evidence fact extraction and quote span derivation, query-aware evidence retrieval, grounded prompt construction, the grounded chat switch, document controller routes, goal-biased retrieval seed selection, graph and recency retrieval strategy selection, query-aware seed selection (lexical scoring, adaptive goal admission, deterministic fallbacks, retrieval traces), sensitivity and consolidation filtering regressions for the query-aware strategies, benchmark cleanup behavior, benchmark corpus fixture validation, deterministic theme coverage, goal ablation and `goals_excluded` metadata, graph reinforcement, edge decay, neighborhood traversal, cluster detection determinism, graph snapshot storage and pruning, agent alignment Jaccard calculation, the memory approval flow, the `active_node_ids` response field, consolidation pipeline (concept node creation, supersedes edges, sensitivity inheritance, re-consolidation prevention), node pruning (floor-weight detection, idle window, edge cascade delete, user scoping, dry-run), the MCP store endpoint (API key auth, graph node creation), and the ThreeD page load with agent scoping.

Imported history adds its own coverage: the streaming JSON reader (nesting, escapes, Unicode, byte order marks, scalar elements, truncated input, oversized elements, per-element memory bounds), archive safety (path traversal, absolute and Windows paths, null bytes, entry count, per-entry size, total size, compression ratio, ZIP detection by content, directory and bare-file sources), adapter detection including the case where ChatGPT and Claude both ship a file named `conversations.json`, ChatGPT branch ordering and parent cycles, missing timestamps left null, image parts becoming attachments, Claude thinking and tool blocks recorded but not inlined, Claude legacy text fallback, Gemini HTML conversion with script removal, the refusal of an HTML-only Takeout export, deterministic synthesized identifiers, idempotent re-import, incremental merge, retention of messages absent from a newer archive, owner separation, raw-source preservation, the provenance chain from a message back to an archive SHA-256, redaction applied to normalized text but not to the preserved source, truncation of oversized messages, duplicate message identifiers, dry runs, limits, cross-provider retrieval, owner scoping on every route, deterministic ranking, citation resolution, invented-citation neutralization, the structural separation of evidence from the user turn, and behaviour when the model is unavailable.

The frontend Vitest coverage checks redacted chat rendering, sensitive-memory approval, live browser graph sync typing, and the history surface: the empty-state export instructions, the cross-provider summary, cited evidence rendering with resolvable links, the unresolved-citation warning, the no-evidence message, and the notice shown when answer generation is disabled.

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
│   │   │   ├── ImportConversationArchive.php # php artisan memory:import-archive
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
│   │   │   ├── QueryRelevanceScorer.php     # deterministic lexical query-to-node scoring for query-aware retrieval
│   │   │   ├── MemorySummarizationService.php
│   │   │   ├── RedactionService.php          # deterministic PII, financial, and credential redaction
│   │   │   ├── RedactionResult.php           # redacted text plus category metadata
│   │   │   ├── ConsolidationService.php     # compresses episodic clusters into concept nodes (weekly)
│   │   │   ├── GraphExtractionService.php   # LLM extracts node type, label, tags per memory or document chunk
│   │   │   ├── MemoryGraphService.php       # stores nodes, wires edges, Physarum dynamics
│   │   │   ├── ClusterDetectionService.php  # weighted label propagation community detection
│   │   │   ├── MultiAgentGraphService.php   # collective Physarum, shared edges, agent seeding
│   │   │   ├── BenchmarkService.php         # retrieval strategy benchmark harness
│   │   │   ├── Conversations/               # imported AI history
│   │   │   │   ├── Adapters/
│   │   │   │   │   ├── ConversationArchiveAdapter.php  # the contract every provider parser implements
│   │   │   │   │   ├── AbstractArchiveAdapter.php      # shared timestamp, text, and identifier helpers
│   │   │   │   │   ├── AdapterDetection.php            # recognized / supported / reason
│   │   │   │   │   ├── ChatGptArchiveAdapter.php       # mapping-tree parser, branch aware
│   │   │   │   │   ├── ClaudeArchiveAdapter.php        # chat_messages parser, content-block aware
│   │   │   │   │   └── GeminiTakeoutArchiveAdapter.php # Takeout activity-log parser
│   │   │   │   ├── Archive/                 # safe access to ZIPs, folders, and bare files
│   │   │   │   ├── Json/JsonArrayStreamReader.php      # element-at-a-time JSON array streaming
│   │   │   │   ├── ConversationArchiveRegistry.php     # adapter resolution
│   │   │   │   ├── ConversationImportService.php       # detect, normalize, redact, persist idempotently
│   │   │   │   ├── ConversationEvidenceRetrievalService.php # message-level lexical retrieval
│   │   │   │   ├── ConversationAskService.php          # grounded, cited answers over the corpus
│   │   │   │   ├── CorpusOverviewService.php           # deterministic counts and theme timelines
│   │   │   │   ├── NormalizedConversation.php
│   │   │   │   ├── NormalizedMessage.php
│   │   │   │   └── ImportReport.php
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
│   │       ├── GraphSnapshot.php            # cluster payload for one 15-minute interval
│   │       ├── Conversation.php             # normalized conversation from any provider
│   │       ├── ConversationMessage.php      # normalized message, redacted content_text
│   │       ├── ConversationImport.php       # one archive import run
│   │       └── ConversationRawRecord.php    # preserved provider JSON, owner-only
│   ├── database/migrations/
│   │   ├── ..._create_memory_nodes_table.php
│   │   ├── ..._create_memory_edges_table.php
│   │   ├── ..._add_access_tracking_to_memory_graph.php
│   │   ├── ..._add_consolidated_at_to_memory_nodes.php
│   │   ├── ..._add_goal_type_to_memory_nodes.php
│   │   ├── ..._create_redaction_policies_table.php
│   │   ├── ..._create_conversation_imports_table.php
│   │   ├── ..._create_conversations_table.php
│   │   ├── ..._create_conversation_messages_table.php
│   │   ├── ..._create_conversation_raw_records_table.php
│   │   ├── ..._create_agents_table.php
│   │   ├── ..._create_shared_memory_edges_table.php
│   │   ├── ..._create_graph_snapshots_table.php
│   │   └── ..._create_evidence_facts_table.php
│   ├── database/benchmarks/
│   │   ├── corpus_01_software_developer.json
│   │   ├── corpus_02_researcher.json
│   │   ├── corpus_03_business_owner.json
│   │   ├── corpus_04_longhorizon_engineer.json
│   │   └── corpus_05_longhorizon_product.json
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── History/Index.vue            # imported history: Ask, Explore, Timeline, Imports
│   │   │   ├── History/Show.vue             # one conversation, its branches, and its preserved source
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
├── AGENTS.md                                # binding rules for coding agents: authorship, personal data, boundaries
├── VISION.md                                # research position: design decisions, what this proves, open questions
├── DEVLOG.md                                # captain's log: what was discovered building it, entry by entry
├── RESEARCH.md                              # active research agenda: open scientific claims and what needs to be built to test them
└── SCIENCE.md                               # plain-language explanations of the mathematics and biology behind the graph layer
```

The benchmark corpora live in `app/database/benchmarks/`. Corpora 01-03 are the compact persona sets used in the first complete run. `corpus_04_longhorizon_engineer.json` is the harder 12-month retrieval challenge added for the long-horizon pass. `corpus_05_longhorizon_product.json` covers 14 months, labels every question with a class, includes a small number of paraphrased questions, and floods the recent window with routine operational notes so recency-leaning strategies pay a measurable price on questions about older knowledge. `BenchmarkService` and `benchmark:retrieval` also support goal ablation, so the same corpus can be rerun with goal nodes excluded when measuring Claim 3.

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
| QueryRelevanceScorer | Deterministic lexical scoring of the current redacted message against node content, tags, and labels for query-aware seed selection |
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
| ConversationImportService | Detects the provider, normalizes an archive, redacts message text, preserves the source, and persists idempotently |
| ConversationArchiveRegistry | Resolves which adapter can read an archive, and surfaces the reason when one recognizes it but cannot parse it |
| JsonArrayStreamReader | Streams the top-level elements of a JSON array so peak memory scales with one conversation, not the archive |
| ConversationEvidenceRetrievalService | Deterministic lexical retrieval over imported messages, returning excerpts whose citations resolve |
| ConversationAskService | Builds the grounded prompt over selected excerpts, validates citations after generation, and reports invented ones |
| CorpusOverviewService | Counts the corpus and tracks a subject through it over time, with no model call |
| ConversationHistoryController | Owner-scoped review surface; the raw route is the only path that serves unredacted imported content |
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
