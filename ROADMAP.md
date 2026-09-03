# OpenMemory OS Memory Roadmap

Date: 2026-09-03

## Current Diagnosis

OpenMemory already has the hard research pieces that make it interesting: a Laravel and Vue reference app, deterministic redaction, MCP read and write tools, query-aware retrieval, graph reinforcement, document ingestion, grounded document QA experiments, and a benchmark harness. The next blocker is product shape, not another visualization or graph variant.

The useful product is OpenMemory Local: a local-first memory daemon that every AI tool on the machine can query through MCP with minimal setup. ICP should remain available for signed sync and ownership experiments, but the first open-source release should feel valuable before a user deploys a canister.

## External Signals

Omarchy already treats AI coding agents as first-class OS citizens and wires multiple CLIs into the desktop. Its config model uses user dotfiles, hooks, extensions, and skills, which makes it a good target for an OpenMemory package.

MCP is the right integration layer because it gives each host a standard way to reach local tools, resources, and prompts over stdio or HTTP. The 2026 roadmap is also moving toward better discovery, agent identity, and remote transports, so OpenMemory should align with MCP instead of building per-provider plugins first.

Current provider memory systems are useful but siloed. OpenAI ChatGPT and Codex use memory controls, Claude Code has project memory files, and Gemini CLI loads hierarchical context files. OpenMemory should import these local memories with provenance and expose a shared recall surface back to all of them.

## Product Decision

Build the local OS memory substrate first.

The default install target is:

1. A local OpenMemory app running with SQLite or PostgreSQL.
2. The MCP server started as a user service.
3. A setup command that prints or installs config snippets for Codex, Claude Code, Claude Desktop, and Gemini CLI.
4. An import command for existing agent memories and project instruction files.
5. A small browser UI for review, deletion, privacy changes, and search.

Default retrieval should stay `query_lexical` until answer-level evaluation proves graph expansion improves final responses. The July benchmark showed query relevance created the measurable gain; graph traversal did not yet prove itself.

## Workstream 1: One-Command Local Setup

Goal: a developer can run one command, connect two AI clients, store one memory from client A, and recall it from client B within 10 minutes.

Current status: the root `openmemory` CLI now has `doctor`, `setup-clients`, and `import` commands. It does not yet start the Laravel app or install system services.

Tasks:

1. Done: add a read-only MCP client setup helper that prints Codex, Claude, Claude Desktop, and Gemini config snippets.
2. Done: add `openmemory doctor` checks for Laravel reachability, MCP API key, database migrations, Node dependencies, identity file, and write scope.
3. Add app start/stop wrappers for local development.
4. Add a Streamable HTTP MCP mode before installing the MCP server as a user service.
5. Keep writes public-only by default. Require an explicit flag for private writes.
6. Done: make mock mode deterministic so `OMA_MOCK_URL` writes and search use `OMA_USER_ID`, even when a live identity file exists.

Acceptance test: store a memory through one MCP client and retrieve it from a second MCP client using `search_memories` without manual database edits.

## Workstream 2: Import Existing Agent Memories

Goal: OpenMemory can absorb the memory surfaces users already have.

Current status: `openmemory import` scans Codex, Claude, Gemini, and project instruction files and writes redacted JSONL candidates for review. Direct ingestion into the graph is still deferred until there is a review UI or approval flow.

Import targets:

1. Codex local memories under `~/.codex/memories`.
2. Claude Code project memory under `~/.claude/projects/<project>/memory` and durable `CLAUDE.md` instructions.
3. Gemini CLI context from `GEMINI.md` files and imported context files.
4. Shared project instruction files such as `AGENTS.md`.

Rules:

1. Imports are explicit and reviewable.
2. Each imported node records `source`, `source_path`, `imported_at`, and a content hash.
3. Redaction runs before storage.
4. Duplicates are skipped by content hash.
5. Private-by-default imports should be supported for home-directory memories.

## Workstream 3: Answer-Level Evaluation

Goal: measure whether memory improves the final answer, not only the retrieved context set.

Tasks:

1. Extend the benchmark harness so each strategy generates an answer from the retrieved context.
2. Judge answer correctness, source use, harmful leakage, and unnecessary recall.
3. Keep the current retrieval-only benchmark as a lower-level diagnostic.
4. Add ablations for no memory, query lexical, query graph, hybrid query graph, and grounded document mode.

Decision gate: graph expansion becomes default only when answer-level results beat query lexical on older-context and cross-source tasks without increasing leakage.

## Workstream 4: Daily Source Ingestion

Goal: make OpenMemory useful between explicit chat saves.

Near-term sources:

1. Git commits, pull request summaries, and issue discussions for selected repos.
2. Project docs and design notes.
3. Optional shell history summarization with conservative redaction.
4. Optional browser export or manual pasted longform notes.

Do not build full desktop event capture first. OpenAI Computer History shows the power of OS context, but it also shows the privacy cost. OpenMemory should start with explicit, auditable sources.

## Workstream 5: Identity And Permissions

Goal: one person can safely run local memory across many tools.

Tasks:

1. Separate local user identity, MCP client identity, and ICP principal in the data model.
2. Add per-client write scopes and labels.
3. Add an audit log for every MCP `store_memory` call.
4. Add local deletion guarantees and a retention policy for imported sources.
5. Document the threat model for prompt injection, accidental sensitive capture, and malicious local clients.

## Workstream 6: Omarchy Integration

Goal: make OpenMemory feel native on an AI-first Linux desktop.

Package shape:

1. `openmemory` CLI for start, stop, status, doctor, import, and setup-clients.
2. `openmemory-mcp` systemd user service.
3. Omarchy menu extension entries for memory search, review, and pause writes.
4. Bar indicator for write status: read-only, public-write, private-write.
5. Optional Omarchy install hook that links the OpenMemory skill into `~/.agents/skills` and provider-specific skill directories.

This should be an integration package, not a fork of Omarchy.

## Workstream 7: Defer Until After Usefulness

Defer these until the local product is boring and reliable:

1. Another graph visualization redesign.
2. Moving all graph state into ICP.
3. Autonomous agent orchestration.
4. Multi-user collective memory beyond local single-user sync.
5. Background OS event capture.

## Source Notes

- Omarchy AI manual: https://github.com/omacom/omarchy/blob/quattro/manual/17-ai.md
- Omarchy dotfiles and hooks: https://github.com/omacom/omarchy/blob/quattro/manual/31-dotfiles.md
- MCP architecture: https://modelcontextprotocol.io/docs/2026-07-28/learn/architecture
- MCP roadmap: https://blog.modelcontextprotocol.io/posts/mcp-roadmap/
- OpenAI Docs, ChatGPT and Codex memories: https://learn.chatgpt.com/docs/customization/memories
- OpenAI Docs, Computer History: https://learn.chatgpt.com/docs/customization/computer-history
- OpenAI Docs, Codex MCP: https://learn.chatgpt.com/docs/extend/mcp
- Claude Code memory: https://code.claude.com/docs/en/memory
- Claude Code MCP: https://code.claude.com/docs/en/mcp
- Gemini CLI context files: https://google-gemini.github.io/gemini-cli/docs/cli/gemini-md.html
- Gemini CLI MCP setup: https://geminicli.com/docs/cli/tutorials/mcp-setup/