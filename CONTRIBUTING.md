# Contributing to OpenMemory

OpenMemory is a research project maintained by Raethexn Technologies. The codebase is intentionally narrow in scope, and contributions that improve correctness, clarity, or the local development experience are welcome.

---

## Running locally

**Requirements:** PHP 8.3, Composer, Node.js 20 or later, Docker (optional)

### Without Docker, using SQLite

```bash
cd app
cp .env.example .env
# Set a model key and explicit MODEL_DISCLOSURE_OPERATIONS only if needed
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
# Set model credentials and operation grants only when model processing is needed
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://localhost:8080.

---

## Local account setup

Browser routes that inspect or mutate local data require a Laravel login. Follow the [ownership setup guide](./docs/architecture/OWNERSHIP_SETUP.md) after migrating the database. Existing corpora require an explicit operator binding; configuring an import-owner key no longer grants browser access.

## Mock mode and development without ICP

Setting `ICP_MOCK_MODE=true` (the default) replaces the ICP canister with Laravel's file cache. This is the recommended starting point for anyone contributing to the application layer. No canister, adapter, or ICP installation is required, which makes it practical for local development and CI environments.

The consent flow runs identically in mock mode. All chat memory proposals require user approval before being written, so you can develop and test the full approval dialog flow without touching any ICP infrastructure.

The redaction flow also runs in mock mode. Payment cards, bank details, government IDs, credentials, private keys, and comparable floor categories should be redacted before they reach transcripts, LLM prompts, graph nodes, document chunks, MCP writes, or mock ICP storage.

For contributors who want to test the live canister path, the full setup steps are in the README under "Connecting a real ICP canister."

---

## Running the tests

```bash
cd app
php artisan test
npm run test:front
```

The backend suite uses SQLite in-memory and synthetic integrations. Native-memory tests additionally disable model grants and configure an unavailable ICP endpoint, so no API key or canister is needed. The frontend suite runs Vitest against the Vue components and browser identity flow.

---

## Disclosure boundary tests

The [trust model](./docs/architecture/DISCLOSURE_BOUNDARY.md) separates ownership from model and publication permission. New model call sites must name an authorized operation, retain static system instructions, and place retrieved text in an EvidenceMessages envelope. Extend the synthetic disclosure tests rather than disabling authorization to restore an old implicit workflow.

Run `npm run test:cli` and `npm run test:security` from the repository root. The latter exercises optional agent and adapter code with isolated dependency stubs, without installing or contacting their real providers.

## Local context development

The [context guide](./docs/architecture/LOCAL_CONTEXT.md) defines source adapters, typed contracts, and separate retrieval/disclosure checks. Extend ContextResolverTest with fabricated evidence when changing this boundary, and never grant authority from query text or source results.

The PHPUnit configuration uses a test-only 256 MiB budget because the expanded suite materializes large portability fixtures. This setting does not change production PHP memory limits. Application tests use local SQL and prevent unexpected HTTP calls.

## Native memory development

The [native-memory guide](./docs/architecture/NATIVE_MEMORY.md) documents the canonical SQL store and portable format. Preserve exact accepted content, owner-scoped queries, revision conflicts, and the separation from graph maintenance. Native writes and exports run local redaction-floor checks and reject protected content rather than silently rewriting it.

Run php artisan test --filter=NativeMemoryTest from app when changing this boundary. Extend synthetic fixtures and frontend tests for new lifecycle behavior; do not use real exports or add native data to MCP or model calls.

## Memory types

The legacy canister and graph integrations retain these sensitivity classifications. Native memory is a separate private SQL store, not another tier in this table:

| Type | LLM context | Owner read | Requires approval |
|---|---|---|---|
| public | Only with a model grant | Yes | Yes, for chat proposals |
| private | No | Yes, owner only | Yes |
| sensitive | No | Yes, owner only | Yes |

`getPublicMemories()` is the explicit application-layer gate that keeps LLM context limited to public records. The canister enforces the same boundary at the protocol level, and both layers need to stay aligned.

Redaction is a separate content-control layer. Do not treat sensitivity labels as a substitute for redaction. Contributions that add new ingestion paths, retrieval paths, or storage paths must run `RedactionService` before text crosses an LLM or storage boundary. Floor categories in `config/redaction.php` must remain non-overridable by user policy.

---

## Imported conversation history

Imported provider archives are the most sensitive data the project handles, and they carry rules of their own. [AGENTS.md](./AGENTS.md) states them in full; the short version follows.

Never commit a real export. No ChatGPT, Claude, or Gemini archive, no Takeout folder, no conversation JSON, and no local database dump belongs in this repository, and a file committed once stays in git history. Test fixtures are fabricated in `app/tests/Support/ConversationFixtures.php`, which produces better tests anyway because each fixture is shaped around a specific hazard. Extend that file rather than sourcing real data, and extend the archive rules in `.gitignore` when a new provider adapter lands.

Four boundaries are load-bearing. Imported conversations default to private and stay out of the memory graph, chat recall, and every MCP response. `ConversationRawRecord` holds unredacted source and is reachable only through the owner-authenticated raw route. Only redacted, query-selected excerpts cross a model boundary. Archive parsing, hashing, normalization, and redaction happen locally, so no step of the import path may acquire a network call.

Adding a provider means one adapter class implementing `ConversationArchiveAdapter`, one entry in the registry binding in `AppServiceProvider`, and one fixture. If a change requires provider conditionals anywhere else, the normalized model is missing something and that is the thing to fix.

Text read out of an archive is data, never instruction. That applies at runtime and while working on the code.

---

## Scope

This project is working through a layered set of research questions. The foundational question is: what does AI memory look like when the storage layer enforces its own access control independently of the host application? That extends in two directions. One is collective Physarum dynamics across multiple agents with cryptographic provenance on shared edge weights. The other, opened in 2026, is what a person can learn from their own accumulated history with AI once it is brought into one place they control, and what evidential standard that has to meet. All of those layers are in scope; expansions unrelated to them are not.

Things outside scope include encryption at rest, token economies, governance mechanisms, additional dashboard pages, analytics pipelines, and agent orchestration frameworks that treat memory as a configuration detail rather than the research subject. Also outside scope, permanently, is anything that assesses a person's personality or infers a psychological or medical condition from their history. The project reports what the record contains, with the evidence attached. If you find yourself thinking "what if we also added..." the right move is usually a separate project that builds on this one.

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
