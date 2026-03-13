# Contributing to OpenMemoryAgent

OpenMemoryAgent is a research project maintained by Raethexn Technologies. The codebase is intentionally narrow in scope, and contributions that improve correctness, clarity, or the local development experience are welcome.

---

## Running locally

**Requirements:** PHP 8.3, Composer, Node.js 20 or later, Docker (optional)

### Without Docker, using SQLite

```bash
cd app
cp .env.example .env
# Set OPENROUTER_API_KEY in .env
php artisan key:generate
composer install
npm install
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000. Memory runs in mock mode by default, so no canister or adapter is required.

### With Docker, using PostgreSQL

```bash
cp app/.env.example app/.env
# Set OPENROUTER_API_KEY in app/.env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://localhost:8080.

---

## Mock mode and development without ICP

Setting `ICP_MOCK_MODE=true` (the default) replaces the ICP canister with Laravel's file cache. This is the recommended starting point for anyone contributing to the application layer. No canister, adapter, or ICP installation is required, which makes it practical for local development and CI environments.

The consent flow runs identically in mock mode. Private and sensitive memories still require user approval before being written, so you can develop and test the full approval dialog flow without touching any ICP infrastructure.

For contributors who want to test the live canister path, the full setup steps are in the README under "Connecting a real ICP canister."

---

## Running the tests

```bash
cd app
php artisan test
```

The test suite uses SQLite in-memory and mock mode throughout, so no API key or canister is needed to run it.

---

## Memory types

The three-tier memory model is the core architectural claim of the project. Please preserve it in any contribution:

| Type | LLM context | Owner read | Requires approval |
|---|---|---|---|
| public | Yes | Yes | No |
| private | No | Yes, owner only | Yes |
| sensitive | No | Yes, owner only | Yes |

`getPublicMemories()` is the explicit application-layer gate that keeps LLM context limited to public records. The canister enforces the same boundary at the protocol level, and both layers need to stay aligned.

---

## Scope

This project is working through a layered set of research questions. The foundational question is: what does AI memory look like when the storage layer enforces its own access control independently of the host application? The research direction is extending that toward collective Physarum dynamics across multiple agents with cryptographic provenance on shared edge weights. Both layers are in scope; expansions that are unrelated to either are not.

Things outside scope include encryption at rest, token economies, governance mechanisms, additional dashboard pages, analytics pipelines, and agent orchestration frameworks that treat memory as a configuration detail rather than the research subject. If you find yourself thinking "what if we also added..." the right move is usually a separate project that builds on this one.

---

## Documentation writing standard

All markdown in this repository follows a plain research writing style. The rules apply to DEVLOG.md, VISION.md, README.md, and this file.

No em-dashes. Use a comma, semicolon, or rewrite the sentence structure instead.

No corporate or marketing language. Words like "leverage", "robust", "scalable", "seamless", "streamline", "empower", and "next-generation" do not belong in a research document. State what the system does; do not describe it as impressive.

No sentence fragments. Every sentence must contain at least five words and a complete grammatical thought.

No repetition. If a point was made in the preceding paragraph, it does not need to be restated in different phrasing.

Write as if explaining the system to another researcher who will read critically. Every sentence should carry information that the reader could not infer from the surrounding context.

---

## Reporting problems

Open a GitHub issue and include what you expected to happen, what actually happened, whether you were running in mock or live mode, and any relevant output from `app/storage/logs/`.
