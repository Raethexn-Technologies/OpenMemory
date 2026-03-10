# OpenMemoryAgent — Research Vision

> *What does user-sovereign AI memory actually look like when you try to build it?*

This document is the research record for OpenMemoryAgent. It is not a setup guide (see README.md) and not a feature list. It captures the design questions driving the project, what we actually learned building it, what the implementation honestly proves, and where the hard problems remain.

---

## The Core Question

Most AI products that remember you store that memory in the operator's infrastructure. Your conversation history, personality profile, preferences, and extracted facts live in Redis, Pinecone, or a managed PostgreSQL instance belonging to the company running the app. When you stop using the product, the memory stays with them. When they get acquired, your memory gets acquired. When they change their privacy policy, your memory is subject to those new terms.

The question this project is exploring is simple but hard to implement well:

**What does it look like when the memory layer belongs to the user instead of the host application?**

Not theoretically. Concretely. With a working chat interface, a real AI, and an actual storage layer enforcing identity at the protocol level.

---

## Why This Is Harder Than It Sounds

The obvious first move is "just let the user own the database." But that doesn't work cleanly because:

1. **The AI needs to read the memory to generate responses.** If memory is fully private, the AI can't help you. Useful memory requires a read path the agent can use.

2. **Writes must be authenticated.** If anyone can write to your memory under your identity, the privacy claim is hollow. The write path needs cryptographic identity enforcement, not just application-level trust.

3. **The app server is in the middle.** The server generates the LLM response, extracts the memory summary, and orchestrates everything. In a naive implementation the server can write whatever it wants under any identity. Removing the server from the write path requires the client (browser) to hold and use the signing key.

4. **Sensitivity is contextual.** Not all memory is equal. Your name is public. Your relationship status is personal. Your salary is sensitive. A binary public/private split loses nuance; treating everything as private makes the AI useless.

5. **The user has to be involved.** If the system silently classifies and stores everything, the user has approved nothing — they've just trusted the server with a different label on it. Real agency requires the user to see and decide on at least the sensitive cases.

These constraints define the design space this project is working in.

---

## What Was Built

OpenMemoryAgent is a working prototype of one concrete answer to the above questions. The stack is intentionally conventional (Laravel, Vue, Tailwind) so the novel parts are clearly isolated.

### The novel parts

**Browser-generated identity.** An Ed25519 key pair is generated in the user's browser on first load and persisted in `localStorage`. The server never sees the private key. The ICP principal derived from this key becomes the user's identity in the memory layer — not a server-assigned user ID.

**Browser-signed canister writes (live mode).** When the server extracts a memory summary, it returns it to the browser instead of writing it directly. The browser signs the write using the Ed25519 identity and sends it to the ICP canister. The canister enforces `msg.caller` — the signed principal — as the record owner. The server cannot forge writes under the user's principal.

**Three-tier memory classification.** Each extracted memory is classified as Public, Private, or Sensitive by an LLM call. This classification determines:
- What the agent can recall (only Public reaches the LLM)
- What the user must approve (Sensitive requires explicit consent before signing)
- What's accessible outside the app (only Public is served at the HTTP endpoint)

**Canister-level read enforcement.** The Motoko canister enforces access by `msg.caller`. Anonymous callers — the server adapter, the HTTP gateway, the MCP server — can only retrieve Public records. Private and Sensitive records are returned only to a caller whose principal matches the record's owner. This is cryptographic enforcement, not application-level trust.

**Public HTTP endpoint.** Any Public memory record is readable at `https://<canister-id>.ic0.app/memory/<principal>` with no authentication, no API key, no dependency on the Laravel server. This is the memory living outside the app — accessible from a terminal, another app, or an MCP-compatible AI agent.

**MCP server.** A Model Context Protocol server wraps the public HTTP endpoint. Any MCP-compatible agent (Claude Desktop, other LLMs) can read a user's public memories by principal, without touching the host application.

### The conventional parts

The chat interface, LLM integration, session management, and transcript storage are standard. The memory sensitivity classification is an LLM call with a structured prompt. The mock mode uses Laravel's file cache. None of this is novel — it exists to make the novel parts demonstrable.

---

## What This Proves (Honestly)

**The canister enforces identity at the protocol level.** In live ICP mode, `msg.caller` on `store_memory` is cryptographically the browser's Ed25519 principal. The server cannot write under a user's principal. This is a real property, not application-level trust, and it's verifiable by reading the Motoko source on-chain.

**Anonymous reads are limited to Public records.** The LLM, the server adapter, and the MCP server all call the canister anonymously. The canister's `get_memories` returns Private and Sensitive records only to an authenticated caller whose principal matches the owner. The LLM genuinely cannot recall Private or Sensitive memories — not because of application logic, but because the canister won't return them to an anonymous caller.

**Memory outlives the application session.** The browser key survives chat resets. A user who clears their chat history still has the same principal and the same canister records. The memory is not session-scoped.

**Memory lives outside the app's database.** Records are stored in the canister, not in PostgreSQL. The HTTP endpoint works independently of the Laravel server. A user can read their public memories from any context — another app, a terminal, a different AI assistant — using only their principal.

**The MCP connection is real.** The MCP server reads from the canister's public endpoint. Any MCP-compatible agent can be given a principal and retrieve that user's public memories, with no integration work beyond adding the MCP server to their config.

---

## What This Does Not Prove

**User-controlled memory content.** The server still decides what text gets extracted and stored. The browser signs the write, but the user sees only the summary — not the full extraction logic, not alternative phrasings. Approving a memory is consent to store *this string*, not consent to the summarization decision.

**Strong key custody.** `localStorage` is accessible to any same-origin JavaScript. An operator-controlled frontend could read the private key. A script injection attack could exfiltrate it. True user key custody requires a hardware key, WebAuthn, or Internet Identity. The current implementation is meaningfully better than a server-generated ID (the server never has the key) but meaningfully weaker than a hardware-backed identity.

**User-chosen classification.** The LLM classifies each memory. The user cannot say "mark this private." Classification accuracy depends on model quality and prompt design. There is no correction mechanism — a misclassified memory stays misclassified until deleted.

**Multi-device portability.** The Ed25519 key lives in one browser's `localStorage`. Clearing it generates a new identity. Cross-device access requires manual key export/import. Internet Identity would solve this; it's the obvious next step and requires only swapping the identity source.

**Decentralized application layer.** The application itself (Laravel, Vue) runs on conventional infrastructure. Only the memory storage layer is decentralized. "Decentralized AI memory" is accurate; "decentralized AI" is not.

---

## The Honest Security Analysis

### What's real

- Server cannot write under user principal (live mode)
- Canister enforces read access by `msg.caller` (cryptographic, not application logic)
- Private and Sensitive memories never reach the LLM recall path (enforced at both canister and application layers)
- Sensitive memories require explicit user approval before any write happens (live mode and mock mode)
- Private memories require user approval before signing (changed from auto-sign after recognizing the consent gap)
- LLM classification failures now discard the memory rather than defaulting to Public (fail-closed)
- The adapter's live write fallback hard-rejects rather than silently dropping `memory_type`

### What's not real (yet)

- The user has no first-party path to read their own Private/Sensitive memories back within the app (write-only problem — partially addressed in the UI with an authenticated read panel, but this is the most honest gap)
- Classification is LLM-generated, non-deterministic, and user-uncorrectable
- localStorage key custody is weaker than hardware-backed identity
- Mock mode (the default for local development) is not a security simulation — it's a functional approximation for UI development

### The trust boundary

The honest version of the trust claim is:

> In live ICP mode, the memory storage layer enforces its own access control independently of the host application. Private and Sensitive records are inaccessible to unauthenticated callers at the protocol level. The host application cannot forge writes under a user's identity. The user must approve Sensitive writes and Private writes before they are signed.
>
> The host application still controls what text gets presented for signing. The user cannot fully verify that the LLM extraction is faithful to the conversation. The key is as secure as the browser environment it lives in.

---

## The Design Decisions That Defined This Project

### Decision 1: Keep the app conventional

The memory layer is the experiment. Laravel and Vue are not. This decision means the novel parts stand out clearly and the project is approachable by anyone who has built a web app.

### Decision 2: Browser-signed writes, not server-signed

If the server signed writes, it could write anything under any identity. Making the browser sign writes means the server must return the summary to the browser, which means the user sees it (at least briefly) before it's committed. This is a real improvement in user agency, even if the summary was server-generated.

### Decision 3: Three tiers instead of two

Binary public/private is too coarse. "My name" and "my medical history" should not have the same classification. Three tiers with user approval at the Sensitive boundary gives the user meaningful control at the cases that matter most, without requiring approval for every memory.

### Decision 4: Fail-closed on LLM classification errors

When the LLM returns something that can't be parsed, discard the memory rather than default to Public. Losing a memory fact is recoverable in the next conversation. Accidentally publishing a Sensitive memory as Public is not.

### Decision 5: LLM recall is explicitly Public-only

The `getPublicMemories()` method exists as explicit application-layer policy, separate from the canister's enforcement. Even if the adapter were given an authenticated identity (breaking the implicit-only canister enforcement), the application layer would still filter to Public. Defense in depth over relying on a single implicit property.

### Decision 6: Private memories require user review before storing

Initially, Private memories were auto-signed (only Sensitive required approval). On reflection: relationships, health preferences, location, and habits are Private by classification. Auto-signing these without the user seeing them is not meaningfully different from the server storing them. The approval boundary was moved to `!== public`.

---

## What We Learned

**The hardest part is not the canister — it's the trust boundary in the middle.** The canister enforcement is clean and provably correct. The hard part is the server that sits between the user and the canister, generating summaries and deciding what to surface for approval. That server is still a trusted intermediary even in live mode. Reducing that trust requires either putting classification in the browser or making the classification verifiable.

**Mock mode creates a false development environment.** The default local development experience is mock mode, where there is no canister, no identity enforcement, and no meaningful privacy guarantee. Developers building against mock mode develop a completely different intuition about the system than users running in live mode. This gap is dangerous for a project where the security properties are the point.

**The write-only problem is real and visible.** A user who approves a Private memory and then cannot see it again within the app has experienced a broken product — not a privacy feature. The first-party owner-read path is not optional; it is how the user verifies that the privacy guarantee is real.

**LLM classification is probabilistic infrastructure.** Treating LLM output as reliable classification for security-relevant decisions is dangerous without validation. The system currently assumes the LLM output is correct. In practice, classification accuracy will vary by model, by content type, and by language. Any production version of this needs human review or deterministic validation for the classification step.

**The demo story and the implementation must match exactly.** A claim that cannot be demonstrated live is worse than no claim. "Private memories are access-controlled" cannot be demonstrated if there is no first-party way to show the owner reading a private memory. The story must be constrained to what can be shown.

---

## The Research Questions This Opens

This project is one concrete implementation. The questions it surfaces are more interesting than the implementation itself:

1. **Can users meaningfully consent to memory storage if the server controls the summary?** The current model gives users veto power (reject the write) but not authorship (choose the summary). Is that enough?

2. **What is the minimum viable key custody story for AI memory?** Hardware keys are too heavy for most users. Internet Identity is more practical — what is the real cost of the upgrade?

3. **Should the LLM read Private memories at all?** Currently, Private memories are inaccessible to the LLM by design. But a user might *want* their AI to remember private context. How do you build an opt-in path for the agent to access Private memories while maintaining the owner-only guarantee for other callers?

4. **What happens when memory migrates between AI providers?** If memory is in a canister and any agent can read Public records via MCP, what does it mean to switch from one AI assistant to another? Can your memory follow you?

5. **What is the right granularity for user approval?** Per-memory approval (current model) is high-friction for frequent users. Bulk policy ("always store relationships as private") would be lower friction. How do you give users control without requiring them to approve every fact?

6. **Can the summarization step itself be user-verifiable?** Right now the user sees the summary but not the extraction process. Could a commitment scheme or verifiable computation make the relationship between conversation and summary auditable?

---

## The Strongest Truthful Pitch

> "We're exploring what AI memory looks like when the storage layer enforces its own access control, independent of the host application. In live mode, the canister verifies the caller's cryptographic identity before returning private records — the LLM, the server, and external agents can only see what you've marked as public. Writes are signed by your browser key, not the server. The memory lives on open infrastructure and is readable by any tool that knows your principal.
>
> This is an experiment, not a product. The key is in localStorage. The classification is LLM-generated. The server still writes the summary. We know where the trust boundary actually is, and we can tell you exactly. What we've built is a specific, working instantiation of a question: what would it take for AI memory to belong to the user?"

---

## Next Steps (If This Were Productionized)

These are not near-term goals — they are the research trajectory the design points toward.

- **Internet Identity or WebAuthn** for key custody: swap the identity source, keep everything else
- **User-correctable classification**: let users re-classify or delete memories they disagree with
- **Opt-in Private recall**: a user-gated path for the LLM to access Private memories for a session
- **Memory portability**: export principal + records, import into another app that speaks the same canister interface
- **Verifiable summarization**: a commitment scheme that lets users audit the relationship between conversation and stored summary
- **Cross-device sync**: share the Ed25519 key across devices via an encrypted vault, or replace with Internet Identity entirely

---

*This document was written to preserve the research thinking behind OpenMemoryAgent. The implementation will change; the questions it's asking are the part worth keeping.*
