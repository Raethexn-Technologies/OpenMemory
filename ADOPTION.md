# OpenMemory Adoption Plan

Date: 2026-09-03

## Category

OpenMemory should name the category it wants to own:

> Shared memory for AI agents on your machine.

The technical layer is MCP. The product promise is cross-agent recall with local review and privacy controls.

## Demo Wedge

The first public demo should be narrow enough that anyone can verify it in under two minutes:

1. Start OpenMemory locally.
2. Connect Codex and Claude Code through MCP.
3. Ask Codex to remember a durable project decision.
4. Ask Claude Code a question that needs that decision.
5. Claude calls `search_memories` and answers from the shared record.

The demo should avoid ICP, graph jargon, and background OS capture. Those are research depth, not the first adoption hook.

## Distribution

The first install target is a local developer machine:

1. `node bin/openmemory.js doctor`
2. `node bin/openmemory.js setup-clients mock`
3. `node bin/openmemory.js import all --dry-run`

The Omarchy package should come after the local CLI can diagnose setup, generate MCP config, and create import manifests reliably. Omarchy integration should add menu entries, hooks, and skills around OpenMemory; it should not require a fork of Omarchy.

## Credibility Artifacts

Adoption will come faster if claims are measured:

1. A short video showing cross-agent recall.
2. A blog post explaining why provider memory silos are the problem.
3. An answer-level benchmark comparing no memory, provider-local memory, OpenMemory lexical retrieval, and graph retrieval.
4. A privacy page that shows exactly where memory files live, what gets redacted, and how deletion works.
5. A threat-model document for local malicious clients, prompt injection, and sensitive-memory capture.

## Community Targets

Initial outreach should go where the problem is already obvious:

1. Omarchy users and contributors.
2. MCP server directories and community lists.
3. AI coding-agent users who switch between Codex, Claude Code, Gemini CLI, and OpenCode.
4. Local-first software communities.
5. Open-source AI safety and privacy communities.

## Naming

Use one sentence consistently:

> Tell one AI agent something once. Every other agent on your machine can recall it later.

Avoid leading with "decentralized", "ICP", "Physarum", or "hive mind" in the first paragraph. Those ideas matter, but they are second-order until the product is useful.

## Near-Term Public Milestones

1. Alpha: local CLI, MCP config generation, manual memory store/search demo.
2. Alpha 2: reviewable imports from Codex, Claude, Gemini, and project instruction files.
3. Alpha 3: browser review UI for import candidates.
4. Beta: Omarchy package and setup menu integration.
5. Research release: answer-level eval report and graph-vs-lexical results.
