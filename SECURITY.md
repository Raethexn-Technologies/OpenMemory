# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| `main` branch | Yes |

Only the current `main` branch receives security fixes. Older tagged releases are not backported.

## Reporting a vulnerability

Do not open a public GitHub issue for security reports. Public disclosure before a fix is available puts all users of the project at risk.

Send an email to security@raethexn.com with the subject line "OpenMemory Security Report". Your report should include a clear description of the vulnerability, the steps needed to reproduce it, and an assessment of the potential impact. Attach any proof-of-concept code as a file attachment rather than pasting it inline.

You can expect an acknowledgement within 72 hours of sending your report. After triage, you will receive a message with a resolution timeline. If you do not hear back within 72 hours, send a follow-up to the same address.

## Secrets and credentials

The project never stores secrets in source code. All credentials, API keys, and tokens are configured through environment variables. Each component documents its required variables in a `.env.example` file:

- `app/.env.example` covers the Laravel application.
- `agent/.env.example` covers the autonomous agent.
- `icp/mcp-server/` relies on the same environment variable pattern.

Do not commit `.env` files. The root `.gitignore` and each component's local `.gitignore` exclude them by default.

`REDACTION_HASH_KEY` can be set to a separate HMAC key for deterministic redaction tokens. If it is unset, the Laravel `APP_KEY` is used. Treat either value as secret because a stable token lets the system recognize the same redacted value across writes without storing the raw value.

## Authenticated local ownership

Laravel session authentication protects private browser routes. Each user has an explicit unique corpus binding; configuration, principal strings, and MCP keys cannot establish browser ownership. Global memory inspection returns only explicitly public records in mock and adapter-backed modes. The [setup guide](./docs/architecture/OWNERSHIP_SETUP.md) describes migration, transport requirements, and remaining limitations.

The subsequent [disclosure boundary](./docs/architecture/DISCLOSURE_BOUNDARY.md) adds default-deny model operations, explicit History Ask and document choices, separate evidence messages, approval before public chat publication, and metadata-only application diagnostics. Its limitations include deployment-wide grants, external provider retention, and unsandboxed optional agent tools.

## Scope

The following areas are in scope for security reports:

**ICP canister access control.** The Motoko canister enforces read access using `msg.caller`. Private and Sensitive records are only returned to the principal that stored them. A bypass of this check is a critical vulnerability.

**Deterministic redaction floor.** `RedactionService` must redact or tokenize payment cards, CVV, bank routing and account numbers, IBANs, SSNs, SINs, credentials, JWTs, private keys, and minor-age details before those values cross LLM or storage boundaries. A bypass that lets those raw values reach the transcript, prompt history, graph nodes, document chunks, MCP storage path, or ICP mock store is in scope.

**Sensitivity filtering in memory retrieval.** Every retrieval strategy in `MemoryGraphService::retrieveContext()`, including the query-aware strategies, must restrict seed selection and every graph traversal hop to public, unconsolidated nodes owned by the requesting user. The current redacted user message is the only query input to lexical seed scoring; raw pre-redaction text must never reach seed selection. Retrieval traces expose node IDs, node types, and score components but must not contain node content, labels, or tags. A strategy or trace that surfaces a private or sensitive record into LLM context, benchmark output, or a trace payload is in scope. Regression tests for these boundaries live in `app/tests/Feature/QueryAwareRetrievalTest.php`.

**Archive handling in conversation import.** A provider export is untrusted input, and one of the largest files a user will ever hand this application. `ZipArchiveSource` validates every entry name through `ArchiveEntryName` before it enters the index, rejecting parent-directory segments, absolute paths, Windows drive letters, UNC prefixes, and embedded null bytes. Entry count, per-entry uncompressed size, total uncompressed size, and per-entry compression ratio are all checked before any parsing starts, so a decompression bomb is refused rather than expanded. No entry is written to disk: entries are read through per-entry streams. `JsonArrayStreamReader` bounds the bytes buffered for a single JSON element, so a malformed or hostile array element cannot exhaust memory. Any bypass that lets an import write outside the database, exhaust memory or disk, or read a file outside the supplied archive is in scope. Tests live in `app/tests/Unit/ArchiveSourceSecurityTest.php` and `app/tests/Unit/JsonArrayStreamReaderTest.php`.

**Imported history containment.** Imported conversations default to `private` and must not reach the public memory graph, chat recall, or any MCP response. `conversation_raw_records` holds unredacted provider JSON and must be reachable only through the owner-authenticated raw route, one conversation at a time; no retrieval path, prompt builder, or MCP tool may query that table. Every route on `ConversationHistoryController` is scoped to the corpus owner, and there is no parameter that widens that scope. A path that surfaces one owner's imported history to another identity, to an MCP client, or into a chat prompt is in scope. Tests live in `app/tests/Feature/ConversationHistoryControllerTest.php`.

**The model-provider boundary.** A message a user once sent to one provider is not thereby consented to a different provider. Only redacted excerpts selected by the user's own question may cross a model boundary, bounded by `conversations.ask.evidence_limit` and `conversations.ask.excerpt_chars`, and `CONVERSATIONS_ASK_GENERATE_ANSWER=false` must disable generation entirely while leaving retrieval working. A change that sends whole conversations, unredacted text, or archive contents to a model is in scope.

**Prompt injection carried inside imported history.** Imported archives contain text written by other AI systems, much of it instructions, and some of it potentially planted. Retrieved excerpts must never reach a model as a system instruction and must never be presented as though the user had typed them: they are delimited, labelled with provider and date, and introduced by a policy stating that instructions inside them are content to be reported. Gemini responses, which Takeout stores as HTML, are converted to text with script and style bodies removed rather than tag-stripped. A change that inlines imported content into a system prompt or the authenticated user's request, or that renders imported markup in the UI, is in scope.

**API key validation for `/mcp/store`.** The Laravel endpoint that accepts memory writes from the MCP server and the agent requires an `X-OMA-API-Key` header. Missing or incorrect validation of that header allows unauthenticated memory writes.

**Path traversal in agent file tools.** The `read_file`, `write_file`, and `list_directory` tools in the agent validate every path against `REPO_PATH` before performing any file system operation. A path that escapes `REPO_PATH` is rejected. Any bypass of this validation is in scope.

**Shell command whitelisting in the agent.** The `run_command` tool only accepts a fixed set of executables: `git`, `npm`, `npx`, `node`, `php`, `composer`, and `pnpm`. Arguments matching known destructive flags (`--force`, `--hard`, `--no-verify`, `--allow-empty-message`) are also rejected. A bypass that allows arbitrary command execution is in scope.

## Native memory and portable files

Native memory is private owner-scoped SQL state, with no automatic MCP, model, or provider disclosure. Imports accept only the versioned native format and cannot assign another owner. The [native-memory guide](./docs/architecture/NATIVE_MEMORY.md) documents validation, protected-content checks, deletion, and export limitations.

Exports are unencrypted files containing personal statements. Store them outside the repository, pause writes during paged transfer, and remove downloaded copies separately when deletion is intended. Content checks detect known patterns rather than guaranteeing that arbitrary text contains no secrets.

## Context application authority

The [local resolver](./docs/architecture/LOCAL_CONTEXT.md) accepts revocable application credentials only on its stateless bearer route. Source retrieval and recipient disclosure require separate explicit grants, while owner management remains session-authenticated and CSRF-protected.

Source-wide grants permit applications to accumulate data through repeated requests. Revocation cannot recall plaintext already delivered, and the current grants do not authorize onward model/provider transmission. Require TLS outside trusted local development, protect application secrets, disable payload/header capture, and run the audit-retention schedule.
