# OpenMemoryAgent

> A conversational AI whose memory lives on open infrastructure instead of private servers.

A Laravel/Vue AI application that stores agent memory summaries in an ICP canister instead of locking them inside a traditional cloud database.

---

## The Idea

Most AI agents store memory in private cloud infrastructure (Redis, Pinecone, PostgreSQL on GCP). That means the agent's memory is **owned and controlled by the platform hosting it**.

OpenMemoryAgent demonstrates a different split:

- The **application** is a normal modern web app (Laravel + Vue + Inertia + Tailwind)
- The **AI memory layer** is stored in an Internet Computer Protocol canister — not in the app's database

**Pitch:** "Normal AI application. Unusual memory layer."

### What this demo proves

- Agent memory can be stored outside the host application's database
- The same memory persists across chat session resets
- Memory records are externally inspectable and not locked to one app server
- The LLM provider can be swapped without affecting where memory lives

### What this demo does not yet prove

- **User-owned identity**: the user's identity key is currently server-mediated (stored in a session cookie). A future version would use an ICP principal or user-controlled key so memory is truly portable across app deployments.
- **Full portability across deployments**: while memory lives outside the app DB, re-establishing identity after deploying a new app instance is not implemented in this demo.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS |
| Database | PostgreSQL (app data) / SQLite (local dev) |
| Dev environment | Docker |
| LLM | Swappable — Claude, Gemini, or OpenAI |
| Memory storage | ICP canister (Motoko) via Node adapter |

---

## Architecture

```
Browser
  │
  │  Inertia / Vue
  ▼
Laravel (PHP)
  ├── ChatController          — handles messages
  ├── LlmService              — LLM orchestration (swappable provider)
  ├── MemorySummarizer        — extracts durable facts from conversations
  └── IcpMemoryService        — reads/writes memory via adapter
        │
        │  HTTP JSON (port 3100)
        ▼
  ICP Adapter (Node/Express)  — required when ICP_MOCK_MODE=false
        │
        │  Candid (@dfinity/agent)
        ▼
  ICP Memory Canister (Motoko)
        └── store_memory()
            get_memories()
            list_recent_memories()
            health()
```

**What stays in PostgreSQL:** sessions, chat transcript, user records, app metadata.

**What lives in ICP:** conversation memory summaries, retrieved on future chat turns.

**In mock mode** (default): memories are stored in Laravel's file cache. The adapter is not needed. The UI shows an amber "Mock memory" badge everywhere.

**In ICP live mode**: the adapter translates HTTP JSON → Candid calls against the deployed canister. The UI shows a green "ICP Live" badge and queries real canister health.

---

## Quickstart (Local — No Docker)

```bash
# 1. Enter app directory
cd app

# 2. Copy SQLite env
cp .env.sqlite .env

# 3. Add your LLM API key
#    Open .env and set:  CLAUDE_API_KEY=sk-ant-...

# 4. Install and run
composer install
npm install
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000 — memory runs in mock mode by default. No canister required.

---

## Quickstart (Docker — PostgreSQL)

```bash
# 1. Copy and configure .env
cp app/.env.bak app/.env
# Set CLAUDE_API_KEY= in app/.env

# 2. Start all containers (app, nginx, db, icp-adapter)
docker compose up -d

# 3. Run migrations
docker compose exec app php artisan migrate

# 4. Open
open http://localhost:8080
```

In Docker, the app talks to the `icp-adapter` container at `http://icp-adapter:3100`. The adapter runs in mock mode by default (`ICP_MOCK_MODE=true`).

---

## LLM Provider Swap

Change one line in `.env`:

```env
LLM_PROVIDER=claude    # Claude (default)
LLM_PROVIDER=gemini    # Google Gemini
LLM_PROVIDER=openai    # OpenAI
```

The memory layer stores the same records regardless of which LLM is used. That is the point.

---

## Connecting to a Real ICP Canister

The ICP adapter is **required** when `ICP_MOCK_MODE=false`. It bridges the PHP app to the deployed Motoko canister.

```bash
# 1. Install dfx (ICP SDK)
sh -ci "$(curl -fsSL https://internetcomputer.org/install.sh)"

# 2. Start local ICP replica
cd icp
dfx start --background

# 3. Deploy the memory canister
dfx deploy

# 4. Get the canister ID
dfx canister id memory

# 5. Start the adapter (in a separate terminal)
cd icp/adapter
npm install
ICP_MOCK=false ICP_CANISTER_ID=<canister-id> node server.js

# 6. Update app/.env
ICP_MOCK_MODE=false
ICP_CANISTER_ENDPOINT=http://localhost:3100   # app talks to adapter
ICP_CANISTER_ID=<canister-id>                 # displayed in inspector UI
```

The adapter uses `ICP_DFX_HOST` (default `http://localhost:4943`) to reach dfx. In Docker, set `ICP_DFX_HOST` to the dfx host reachable from the adapter container. For ICP mainnet, set `ICP_DFX_HOST=https://ic0.app`.

---

## Screens

| Screen | Route | Description |
|---|---|---|
| Chat | `/chat` | Conversational interface with memory-aware responses |
| Memory Inspector | `/memory` | Live view of memory records — shows mode, canister health, and record count |

---

## Demo Script (5 minutes)

### Setup
> The app is running. Notice the amber **Mock memory** badge in the nav — that shows the current memory layer status. For this demo, memory is stored in a local cache; with a deployed canister, it would show **ICP Live**.

### Step 1 — Introduce yourself
> Say: "My name is Anthony and I build AI tools."
> The agent replies, and after a moment an emerald notification appears: **"Memory stored (mock): User is Anthony, builds AI tools."**

### Step 2 — Show the Memory Inspector
> Click **Memory Inspector** in the nav. Point out:
> - The record appears with the user identity key, content, and timestamp.
> - In ICP live mode, this record lives in a canister external to the app database.

### Step 3 — Reset the chat session
> Click **New session**. The confirm dialog says the memory is preserved.
> After reset, notice the same **user identity key** appears in the chat header. The transcript is gone but the identity — and the memory — remain.

### Step 4 — Ask what it remembers
> Say: "What do you remember about me?"
> The agent retrieves memory from the layer and responds with context.

### The point to make
> "The memory is stored outside this app's database. In full ICP mode, a different app instance would retrieve the same records. The chat server doesn't own the memory — the infrastructure does."

---

## Project Structure

```
OpenMemoryAgent/
├── app/                          # Laravel application
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── ChatController.php
│   │   │   └── MemoryController.php
│   │   ├── Services/
│   │   │   ├── IcpMemoryService.php
│   │   │   ├── MemorySummarizationService.php
│   │   │   └── LLM/
│   │   │       ├── LlmProviderInterface.php
│   │   │       ├── LlmService.php
│   │   │       ├── ClaudeProvider.php
│   │   │       ├── GeminiProvider.php
│   │   │       └── OpenAIProvider.php
│   │   └── Models/Message.php
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── Chat/Index.vue
│   │   │   └── Memory/Index.vue
│   │   └── Components/
│   │       ├── AppLayout.vue
│   │       ├── NavLink.vue
│   │       ├── StatCard.vue
│   │       ├── ArchNode.vue
│   │       └── ArchArrow.vue
│   └── database/migrations/
├── icp/
│   ├── src/memory/
│   │   ├── main.mo               # Motoko canister — store/retrieve/health
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js             # Node adapter (required for live ICP)
│   └── dfx.json
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
└── docker-compose.yml
```

---

## What Each Layer Does

| Layer | Role |
|---|---|
| Laravel | Request handling, LLM orchestration, memory extraction, session identity |
| Vue + Inertia | Chat UI, Memory Inspector, live mode/mock status display |
| PostgreSQL | Chat transcript, user records, app-level data |
| IcpMemoryService | Talks to adapter; mock-or-live switchable via `ICP_MOCK_MODE` |
| ICP adapter (Node) | Translates HTTP JSON → Candid; required for live mode |
| ICP canister (Motoko) | Stable memory storage outside the application database |
| LLM providers | Claude / Gemini / OpenAI — swappable, memory layer unchanged |

---

## Philosophy

> "What if an AI agent's memory was decoupled from the host application?"

Today, AI agents remember you because the operator stored your memory in their infrastructure. OpenMemoryAgent explores what it looks like when that memory lives on a separate layer — one the app uses but does not own.

The current implementation stores distilled memory summaries (not raw transcripts) in an ICP canister. The app retrieves them on future turns to personalize responses. The memory outlives any individual chat session.

The deeper ambition — fully user-controlled portable identity — is the natural next step.
