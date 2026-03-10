# OpenMemoryAgent

> A conversational AI whose memory lives on open infrastructure instead of private servers.

A Laravel/Vue AI application that stores agent memory in ICP canisters instead of locking it inside a traditional cloud database.

---

## The Idea

Most AI agents store memory in private cloud infrastructure (Redis, Pinecone, PostgreSQL on GCP). That means the agent's memory is **owned by the platform hosting it**.

OpenMemoryAgent proves a different model:

- The **application** is a normal modern web app (Laravel + Vue + Inertia + Tailwind)
- The **AI memory layer** is stored in an Internet Computer Protocol canister — portable, transparent, and not owned by one platform

**Pitch:** "Normal AI application. Unusual memory layer."

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS |
| Database | PostgreSQL (app data) / SQLite (local dev) |
| Dev environment | Docker |
| LLM | Swappable — Claude, Gemini, or OpenAI |
| Memory layer | ICP canister (Motoko) |

---

## Architecture

```
Browser
  │
  │  Inertia/Vue
  ▼
Laravel (PHP)
  ├── ChatController      — handles messages
  ├── LlmService          — LLM orchestration (swappable provider)
  ├── MemorySummarizer    — extracts durable facts from conversations
  └── IcpMemoryService    — reads/writes memory to canister
        │
        │  HTTP
        ▼
  ICP Adapter (Node/Express)     ← optional bridge for local dfx
        │
        │  Candid
        ▼
  ICP Memory Canister (Motoko)
        │
        └── store_memory()
            get_memories()
            list_recent_memories()
```

**What stays in PostgreSQL:** sessions, chat history, user records, app metadata.

**What lives in ICP:** conversation memory summaries, retrieved to inject context into future prompts.

---

## Quickstart (Local — No Docker)

```bash
# 1. Clone and enter app directory
cd app

# 2. Copy SQLite env (no Docker needed)
cp .env.sqlite .env

# 3. Add your LLM API key to .env
#    LLM_PROVIDER=claude  CLAUDE_API_KEY=sk-ant-...

# 4. Install dependencies
composer install
npm install

# 5. Run migrations
php artisan migrate

# 6. Build frontend
npm run build

# 7. Start dev server
php artisan serve
```

Open http://localhost:8000

Memory runs in mock mode by default (`ICP_MOCK_MODE=true`). No canister needed to try the demo.

---

## Quickstart (Docker — PostgreSQL)

```bash
# Copy and configure .env for Docker
cp app/.env.bak app/.env
# Add CLAUDE_API_KEY= to app/.env

# Start all containers
docker compose up -d

# Run migrations inside container
docker compose exec app php artisan migrate

# Open
open http://localhost:8080
```

---

## LLM Provider Swap

Change one line in `.env`:

```env
LLM_PROVIDER=claude    # Claude (default)
LLM_PROVIDER=gemini    # Google Gemini
LLM_PROVIDER=openai    # OpenAI
```

The memory layer stays the same regardless of which LLM you use. That's the point.

---

## ICP Canister (Local Development)

```bash
# Install dfx (ICP SDK)
sh -ci "$(curl -fsSL https://internetcomputer.org/install.sh)"

# Start local ICP replica
cd icp
dfx start --background

# Deploy canister
dfx deploy

# Get canister ID
dfx canister id memory

# Update .env
ICP_MOCK_MODE=false
ICP_CANISTER_ID=<your-canister-id>
ICP_CANISTER_ENDPOINT=http://localhost:4943
```

### ICP Adapter (optional Node bridge)

```bash
cd icp/adapter
npm install
ICP_MOCK=false ICP_CANISTER_ID=<id> node server.js
```

---

## Screens

| Screen | Route | Description |
|---|---|---|
| Chat | `/chat` | Conversational interface with memory-aware responses |
| Memory Inspector | `/memory` | Live view of ICP canister records |

---

## Demo Script (5 minutes)

1. **Open chat** — "My name is Anthony and I build AI tools."
   - Agent replies, stores memory summary to ICP canister.

2. **Show Memory Inspector** — memory record appears with user ID, content, timestamp.
   - Point out: "This is stored in an ICP canister, not our database."

3. **Reset the conversation** — clear message history.

4. **Ask:** "What do you remember about me?"
   - Agent retrieves memory from ICP, responds with context.

5. **Punchline:** "The app server could be replaced and the memory would still be there."

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
│   │   ├── main.mo               # Motoko canister
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js             # Node HTTP bridge
│   └── dfx.json
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
└── docker-compose.yml
```

---

## Philosophy

> "What if an AI agent's memory was public infrastructure instead of locked inside a cloud provider?"

Today, AI agents forget everything when their server disappears. OpenMemoryAgent demonstrates what it looks like when agent memory is decoupled from the application host — stored on open, inspectable infrastructure that no single company controls.
