# OpenMemory Roadmap

Date: 2026-09-10

Supersedes the 2026-09-03 roadmap, which is summarized below rather than deleted.

## What changed

The previous roadmap named the product "a local-first OS memory substrate for Codex, Claude Code, Gemini CLI, and Omarchy-style AI desktops". That was a correct description of a useful capability and too small a description of the opportunity.

The wider observation is that a person now talks to several assistants, and each one accumulates a partial understanding of the same person. One holds career deliberation, another months of personal reflection, another the programming work. No provider can see the others, and the person cannot see across them either. The provider boundary is an artifact of who sold the model, not a fact about the person.

So the product is now: a user-owned memory layer for a person's history with AI. Coding-agent MCP recall becomes one ingestion and recall pathway inside that, rather than the whole category.

The technical work already here mostly transfers. Deterministic redaction, provenance, MCP least-context retrieval, document evidence, typed graph nodes, local-first operation, and the standard of measuring claims rather than asserting them are all more valuable at the larger scope, not less.

## Current diagnosis

Universal conversation import is implemented and the first vertical slice is complete: three provider adapters, a provider-neutral conversation model, preserved raw source, idempotent imports, and an evidence-grounded review and Ask surface at `/history`.

What that slice does not yet do is the more interesting half. Retrieval is lexical, so a question phrased in synonyms of the stored vocabulary finds nothing. Nothing is derived from the corpus: there are no entities, no goals, no decisions, no recurring questions, and no connection between imported history and the memory graph. The temporal analysis is a term-frequency count, which is honest and shallow.

The next blocker is therefore derivation, not more ingestion.

## Principles that constrain the work

1. Local-first stays the default. The product must be useful before a user deploys anything, understands ICP, or configures a model provider.
2. The raw source is never destroyed. Everything derived is recomputable and replaceable.
3. Derived knowledge points back to evidence that resolves. A citation that cannot be opened is not a citation.
4. Sending a message to one provider is not consent to send it to another. Only query-selected, redacted excerpts cross a model boundary.
5. Claims about retrieval quality are measured, not asserted. The benchmark standard from Track 10 applies to everything built on top of the corpus.
6. Observation is distinguished from inference, and neither is dressed up as insight about who a person is.

## Workstream 1: Universal conversation import

Goal: a person can bring the history they already have into one place.

Status: first version complete.

Done:

1. Provider-neutral `conversations`, `conversation_messages`, `conversation_imports`, and `conversation_raw_records` model.
2. Adapter contract with detection that distinguishes "not mine" from "mine but unsupported, and here is why".
3. ChatGPT adapter: mapping-tree traversal, active-branch reconstruction, abandoned branches retained, attachments, tool messages, custom instructions.
4. Claude adapter: content blocks, thinking and tool blocks recorded without inlining, legacy text fallback, project labels, attachments.
5. Gemini adapter for Takeout activity logs, with the activity-record grain recorded rather than papered over, and HTML-only exports refused with the specific fix.
6. Streaming JSON reader so archive size does not become peak memory.
7. Archive safety limits: traversal, entry count, per-entry size, total size, compression ratio.
8. Idempotent import with new, updated, unchanged, and skipped counters, and retention of messages a newer archive no longer contains.
9. Redaction on normalized text with the preserved source left intact.

Next:

1. Attachment bytes. Metadata is captured; the files themselves are not imported.
2. Provider account and workspace grouping where an export supplies it.
3. A Claude Code and Codex session-log adapter, so local agent transcripts join the same corpus as provider exports.
4. Background import for very large archives, with progress the browser can watch. The current command is synchronous and reports progress to the terminal.
5. A re-parse command that re-reads preserved raw records under a newer adapter version, so improving a parser does not require re-exporting.

## Workstream 2: Derivation over the corpus

Goal: turn a corpus into something that answers questions the corpus cannot answer by search.

This is the highest-leverage next milestone. The rule is that derivation augments the archive and never replaces it, and that every derived record points at the messages behind it.

1. Entity extraction over imported conversations: people, projects, places, tools, organizations. Scoped, batched, and local-first where possible, with an explicit cost and privacy boundary when a model is involved.
2. Derived records for goals, decisions, recurring questions, and unresolved threads, each carrying `source_conversation_id` and `source_message_id`.
3. A link from derived records into the existing memory graph, so imported history and live agent memory share one set of primitives.
4. Change detection: first appearance, last appearance, dormancy, resurfacing, and stated positions that later reverse.
5. Contradiction surfacing, which the current model records nowhere.

Decision gate: a derived layer ships only when an answer-level benchmark shows it beats retrieval over raw messages on questions the raw corpus answers badly.

## Workstream 3: Retrieval beyond lexical matching

Goal: find the conversation the person meant, not the one that shares their vocabulary.

1. An embedding provider interface with a deterministic local fallback, matching the existing swappable-provider pattern.
2. Hybrid lexical and semantic retrieval over `conversation_messages`, measured against the lexical baseline rather than assumed better.
3. Temporal retrieval operators: before, after, first, last, during, and since, expressed as filters rather than hoped for from the model.
4. Candidate-pool behaviour on large corpora. The current pool is bounded and newest-first, which biases toward recent history when a common term matches more messages than the pool holds. That bias is recorded in the trace and needs measuring.

## Workstream 4: Answer-level evaluation

Goal: measure whether the corpus improves answers, not only whether retrieval looks reasonable.

Carried forward from the previous roadmap, now with a second corpus to evaluate.

1. Extend the benchmark harness so each strategy generates an answer from retrieved context.
2. Judge answer correctness, citation accuracy, unsupported claim rate, harmful leakage, and unnecessary recall.
3. Build a synthetic longitudinal corpus with known ground truth about change over time, so temporal questions have checkable answers.
4. Ablate: no memory, lexical retrieval, semantic retrieval, graph expansion, derived-layer retrieval.

Decision gate: graph expansion becomes a default only when answer-level results beat lexical retrieval on older-context and cross-source tasks without increasing leakage. The July 2026 retrieval benchmark showed query relevance produced the measurable gain and graph traversal did not, and that result stands until a newer one replaces it.

## Workstream 5: Live memory through MCP

Goal: the coding-agent workflow keeps working and gets better, without becoming a way to read someone's life.

1. Keep least-context retrieval. An external agent receives the smallest useful slice.
2. Per-client scopes and labels, so a client can be granted less than the user has.
3. An audit log for every MCP call, readable in the browser.
4. Careful, explicit exposure of derived knowledge to MCP once a derived layer exists. Imported conversations themselves stay out of MCP responses.
5. Streamable HTTP transport before the MCP server is installed as a user service.

## Workstream 6: Ownership and portability

Goal: the corpus is not hostage to OpenMemory either.

1. A documented export format covering conversations, messages, derived records, and provenance.
2. `memory:export` producing that format, and an importer that reads it back.
3. Deletion that is verifiable: one conversation, one provider, one date range, or everything, with the preserved source going with it.
4. ICP as an optional signed-ownership and sync path for derived records, not a prerequisite for anything. The local-first path stays the simplest way to use the product.

## Workstream 7: Interface

1. Cross-provider timeline that reads as a life in work rather than a bar chart.
2. Conversation detail improvements: branch navigation, thread reconstruction where a provider supplies it.
3. Import progress and history that a person can watch and trust.
4. Do not add another graph visualization until the graph earns it on a benchmark.

## Deferred

1. Another graph visualization redesign.
2. Moving graph state into ICP.
3. Autonomous agent orchestration.
4. Multi-user or shared corpora.
5. Background operating-system event capture. Explicit, auditable sources first.

## Source notes

Verified September 2026:

- OpenAI ChatGPT data export: Settings, Data Controls, Export data. https://help.openai.com/en/articles/7260999-how-do-i-export-my-chatgpt-history-and-data
- OpenAI Codex and ChatGPT memories: https://learn.chatgpt.com/docs/customization/memories
- Anthropic Claude data export: https://privacy.claude.com/en/articles/9450526-how-can-i-export-my-claude-data
- Google Takeout: https://takeout.google.com
- Google My Activity schema reference, which documents the generic activity record and does not cover Gemini Apps: https://developers.google.com/data-portability/schema-reference/my_activity
- MCP architecture: https://modelcontextprotocol.io/docs/learn/architecture
- MCP roadmap: https://blog.modelcontextprotocol.io/posts/mcp-roadmap/
- Claude Code memory: https://code.claude.com/docs/en/memory
- Gemini CLI context files: https://google-gemini.github.io/gemini-cli/docs/cli/gemini-md.html
- Omarchy AI manual: https://github.com/omacom/omarchy/blob/quattro/manual/17-ai.md

Neither OpenAI, Anthropic, nor Google publishes a schema for their export archives. The adapters are written against synthetic fixtures and defensive detection, which is the honest engineering response to an undocumented format that has changed before.
