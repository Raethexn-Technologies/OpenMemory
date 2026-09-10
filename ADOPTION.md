# OpenMemory Adoption Plan

Date: 2026-09-10

Supersedes the 2026-09-03 plan, which named the category as "shared memory for AI agents on your machine". That capability still exists and still works. It is no longer the whole product, so it is no longer the whole pitch.

## Category

> Your AI history, in one memory.

The problem is that a person's history with AI is scattered across the companies that happened to sell them the models. Career deliberation sits in one provider, months of personal reflection in another, programming work in a third. Each holds a partial record of the same person. None can see the others, and neither can the person.

OpenMemory brings that history into one place they control, keeps the original source, and makes it searchable and answerable with evidence they can open.

## What to say

Lead with the problem, not the architecture.

Good:

> Your conversations shouldn't forget each other.

> Bring your history with AI together, and learn from it.

> Tell one AI agent something once. Every other agent on your machine can recall it later.

The third is still the clearest sentence for the coding-agent audience specifically, and it should keep being used with that audience. It is no longer the headline for everyone.

Avoid: "second brain", "AI-powered insights", "revolutionary", "intelligent knowledge engine", "hive mind". Avoid leading with decentralized, ICP, or Physarum. Those ideas matter and are second-order until the product is useful.

Be careful with the reflective framing. The honest promise is that a multi-year, multi-provider corpus lets someone see what they kept returning to, what they planned and dropped, when an interest first appeared, and where they changed their mind, with the conversations behind each observation available to read. The dishonest version is a personality report. The product should never tell someone what they are like, and marketing should not imply that it will.

## Demo wedges

Two demos now, for two audiences.

**Import demo, for anyone who uses AI.**

1. Export ChatGPT history. Export Claude history.
2. `node bin/openmemory.js import-archive ~/Downloads/chatgpt-export.zip --user me`
3. Watch the report: conversations detected, messages normalized, new, already known, changed.
4. Run it again. Watch it converge instead of duplicating.
5. Open `/history`, ask a question that spans both providers, and click through to the conversation behind a citation.

The moment that carries this demo is the second import. Everyone who has moved data between systems expects duplicates, and not getting them is a visible promise about the rest of the design.

**Cross-agent demo, for developers.**

1. Start OpenMemory locally.
2. Connect Codex and Claude Code through MCP.
3. Ask Codex to remember a durable project decision.
4. Ask Claude Code a question that needs it.
5. Claude calls `search_memories` and answers from the shared record.

Both demos should avoid ICP, graph jargon, and background capture.

## Distribution

The first install target is a local developer machine:

1. `node bin/openmemory.js doctor`
2. `node bin/openmemory.js setup-clients mock`
3. `node bin/openmemory.js import-archive <export> --user me`

The audience for import is wider than the audience for MCP configuration, which means packaging becomes the constraint sooner than it did under the previous plan. A person who wants to understand their own history should not have to install PHP. That is a real gap and it is named here rather than assumed away.

## Credibility artifacts

Claims about someone's own history need more evidence than claims about a coding tool.

1. A privacy page stating exactly where imported history lives, what is redacted, what crosses a model boundary, and how deletion works, with the file and table names.
2. A short video of the import, the second import, and one cross-provider question with its evidence opened.
3. An answer-level benchmark comparing no memory, lexical retrieval, semantic retrieval, and any derived layer, on a corpus with known ground truth about change over time.
4. A threat model covering malicious archives, prompt injection carried inside historical assistant output, secrets sitting in old conversations, and malicious MCP clients.
5. A written statement of what the product will not do: no personality assessment, no clinical inference, no claim that inference is certainty.

## Community targets

1. People who have been using AI assistants heavily for two or more years and can feel the fragmentation.
2. Local-first software communities.
3. Data portability and personal data ownership communities.
4. AI coding-agent users who switch between Codex, Claude Code, Gemini CLI, and OpenCode.
5. Privacy and open-source AI safety communities.
6. Omarchy users and contributors.

## Near-term public milestones

1. Alpha: import from ChatGPT, Claude, and Gemini; review, search, timeline, and cited answers at `/history`.
2. Alpha 2: derived entities, goals, decisions, and recurring questions, each pointing back at the messages behind them.
3. Alpha 3: semantic retrieval measured against the lexical baseline.
4. Beta: documented export format, verifiable deletion, and packaging that does not require a PHP toolchain.
5. Research release: answer-level evaluation across no memory, lexical, semantic, graph, and derived retrieval.
