# Disclosure and evidence trust boundary

This document describes the security-foundation increment reviewed on 2026-09-23. It does not implement a Context Resolver, application grant database, new provider, or cloud service.

## Trust model

The trusted control plane consists of application-authored instructions, Laravel-authenticated ownership, and server-controlled disclosure configuration. Browser principal strings, provider credentials, model classifications, and retrieved text cannot add authority.

User intent is the authenticated request, including an explicit choice where private history or document processing can reach a model. Retrieval permission authorizes selecting information for the owner. Disclosure permission separately determines whether that selection may leave for a configured model or become publicly readable.

The untrusted data plane includes conversations, documents, memory records, model responses, source code, tool results, and future provider artifacts. Provenance establishes origin, not trustworthiness. A genuine message from a known account can still contain malicious instructions.

Application policy, the user request, and retrieved evidence remain separate message components. EvidenceMessages serializes a versioned JSON envelope in a separate lower-priority user-role message, because the existing provider accepts ordinary chat messages. Its kind is openmemory.untrusted_evidence.v1; it is not the authenticated user's request. Application instructions are never populated from that envelope. This is structural separation and a prompting constraint, not a security capability supplied by the model API. It does not prevent every prompt-injection attack.

## Audited data-flow map

The following paths existed before this increment. The authorization and retention columns describe the resulting boundary, including intentionally retained external paths.

| Source and retrieval | Transformation | Authorization before disclosure | Destination | Retention and diagnostics |
|---|---|---|---|---|
| Imported conversation rows are searched within the authenticated owner's corpus. | Local lexical selection produces bounded excerpts; current owner redaction is applied again before generation. | Owner inspection needs Laravel authentication. Generation additionally needs an explicit generate=true request, the history generation switch, and the history_ask operation grant. | The owner browser receives selected evidence. Optional generation sends excerpts and the question through OpenRouter to the configured model. | Source rows remain local. Generated History Ask answers are returned, not saved as memories. Provider retention is outside this implementation's control. |
| ConversationRawRecord is read for one owner-authorized conversation. | The raw route serializes the original provider record. | The existing owner-authenticated raw route remains the only permitted reader. | The authenticated browser receives unredacted source when explicitly requested. | Raw source is stored locally and is sensitive. No model, MCP, public graph, or prompt builder reads it. |
| Live chat reads at most ten recent session messages and selected owner-scoped public graph or canister records. | Redaction and an allowlist of evidence fields remove unnecessary storage metadata. | Laravel authentication and the chat model grant are required. The chat UI describes this transmission when sending. | The configured model receives the live request, bounded history, and public evidence. The owner receives its answer. | Redacted chat messages and replies remain in the local transcript. A model failure does not establish that the provider received nothing. |
| A chat reply and the current turn enter memorability and summarization. | Recent novelty candidates include only the same owner's public, unconsolidated nodes. | These calls belong to the explicitly enabled chat workflow. A returned node ID must belong to the selected candidates. | The configured model returns a proposed memory. | Proposals are not published or stored as memories until owner approval. The underlying transcript remains. |
| A memory write requests graph metadata. | Private, sensitive, or disabled-model writes use deterministic local metadata. | Only public_extraction permits model extraction of public content. Public classification alone does not supply this grant. | Public extraction can reach the configured model; all other cases stay local. | Structured node metadata is stored. Invalid model output is logged only by error category. |
| Documents arrive through the authenticated upload endpoint. | Text and title are redacted locally, then text is chunked and stored with source links. | Documents default to private. Model processing needs public sensitivity, allow_model_processing=true, and document_processing authorization. | Local-only ingestion creates graph chunks without model calls. Opted-in public documents send chunks to model extraction and fact extraction. | Anchor, chunks, relationships, and any extracted facts persist locally. Public documents are visible to existing public graph/MCP paths; mock mode also stores a public anchor locally. |
| Scheduled consolidation selects existing memory clusters. | Only public nodes qualify, and the node set is checked again before summarization. | The consolidation operation must be explicitly enabled. | The configured model receives eligible public facts. | Existing consolidation persistence remains. Private nodes do not enter the model or become consolidated through this path. |
| The existing GitHub commit importer fetches configured source records. | Local redaction precedes model summarization; non-public classifications are rejected. | The ingestion model grant and separate ALLOW_INGEST_PUBLICATION switch are required for the publication workflow. Existing repository allowlist behavior remains. | GitHub receives the configured API request; the model receives selected commit content; public results enter existing graph/MCP storage. | Source cursors and public summaries persist. Payload-bearing provider errors are no longer logged or returned. No new GitHub federation was added. |
| The benchmark selects its existing synthetic evaluation context. | Evidence is packaged separately from static judging instructions. | Model judging requires the benchmark operation grant. | The configured model receives evaluation evidence. | Benchmark artifacts remain intentional local outputs, not telemetry. Do not run benchmarks over personal datasets and share their artifacts. |
| MCP reads existing public graph or public canister records. | Public-only filtering is retained, with defensive filtering on canister responses and an untrusted-evidence notice on formatted records. | Existing shared-key and public-read rules are unchanged. Imported history is never included. | The requesting MCP client receives public records. | Client retention and downstream model use are not controlled by OpenMemory. MCP diagnostics omit provider bodies and identity-file parse content. |
| The unsigned HTTP adapter serves canister-backed or demonstration records. | Both read paths and Laravel model recall filter explicitly public classifications; missing labels are not treated as public. | Its mock write endpoint rejects private, sensitive, and missing classifications because it has no ownership proof. | Only public records reach unsigned callers. | Adapter mock storage remains temporary. Laravel's authenticated owner-only mock store is separate and still supports private records. |
| The optional agent retrieves repository/tool results and its existing memory-client results. | Research evidence is separated from the user request; tool results remain tool-result blocks and are bounded. | Model disclosure, channel replies, unsandboxed tools, and memory publication each require separate default-off environment switches. | Opted-in paths can reach Anthropic/OpenRouter, Discord, GitHub, or the existing memory API. | Provider/channel retention is external. The prototype has no secure tool sandbox and should not receive a personal corpus. |
| Exceptions and application diagnostics are generated during these operations. | Safe categories, operation identifiers, counts, durations, and random request IDs replace payloads. | There is no implicit permission to log source content or credentials. | Laravel's configured log destination and local diagnostic consoles receive sanitized operational information. | Log rotation remains deployment configuration. Remote log channels must be assessed separately. |
| Local import and setup commands inspect explicitly selected files or generate configuration. | Imports stay local; setup output uses credential placeholders and strips URL credentials/query parameters. | Local operator access governs these commands, not browser principal strings. | The local terminal receives counts, selected paths, warnings, and requested manifests. | Manifests can intentionally contain redacted source material and remain sensitive files. Archive errors can identify selected local paths. Do not publish terminal transcripts or manifests blindly. |

## Invocation sequence

~~~mermaid
flowchart TD
    A[Authenticated owner request] --> B[Owner-scoped local selection]
    B --> C[Owner inspection response]
    B --> D{Separate disclosure permission}
    D -->|Denied| E[No model call]
    D -->|Allowed| F[Redaction and bounded evidence envelope]
    G[Static application instructions] --> H[Model invocation boundary]
    I[User request] --> H
    F --> H
    H --> J[Configured model provider]
    H --> K[Metadata-only diagnostics]
    J --> L[Redacted response to owner]
    L --> M{Explicit memory publication approval}
    M -->|Approved| N[Existing memory storage]
~~~

The disclosure gate does not grant retrieval authority. Every browser data path still depends on the accepted ownership foundation. A compromised application process can bypass its own PHP methods, so these checks are not process isolation.

## Configuration and compatibility

A configured API key no longer enables model processing. MODEL_DISCLOSURE_OPERATIONS is an empty comma-separated allowlist by default. Unknown or omitted operation names fail closed.

| Operation | Enabling this operation permits the following model processing. |
|---|---|
| chat | It permits live chat generation, novelty evaluation, and turn summarization. |
| history_ask | It permits optional question-selected archive excerpts after the owner explicitly requests generation. |
| public_extraction | It permits metadata extraction from public memory writes. |
| document_processing | It permits extraction from explicitly opted-in public document chunks. |
| ingestion | It permits summarization by the existing commit ingestion workflow. |
| consolidation | It permits summarization of eligible public memory clusters. |
| benchmark | It permits model-based judging of benchmark evidence. |

For example, a self-hosted owner who wants live chat and optional History Ask can deliberately configure the following values:

~~~dotenv
MODEL_DISCLOSURE_OPERATIONS=chat,history_ask
CONVERSATIONS_ASK_GENERATE_ANSWER=true
~~~

History Ask still defaults to retrieval only on each page load. Its unchecked control must be selected before a request sends generate=true. The server generation switch cannot be overridden by the request. A denied generation request returns evidence with generation_disabled; it does not prevent authorized local retrieval. A failed authorized attempt reports model_called=true conservatively, since a provider may have received data before failing.

The model input budget is 64,000 serialized bytes, excluding static application policy, enforced before transmission. Oversized requests are rejected rather than silently expanded. History retrieval retains its default twelve excerpts of six hundred characters. The budget is not a tokenizer-based guarantee. Document processing can transmit an entire explicitly opted-in public document across multiple chunk requests, rather than only a query-selected excerpt. Private and sensitive documents never enter those requests.

All chat-generated memories now require review before storage, including public proposals. The existing store-memory endpoint accepts owner-approved public records. In live mode, approval triggers a browser-signed canister write; in mock mode, it triggers the authenticated Laravel write. Public approval means public graph/MCP access and possible model recall, not merely local storage.

A document request that omits sensitivity now creates private records. Explicit sensitivity=public permits the existing public graph and MCP exposure independently of model processing. Setting allow_model_processing=true without the server grant returns a denial before storing document nodes. Local-only chunks have deterministic labels, empty inferred tags/entities, and no model-generated evidence facts.

Existing commit ingestion additionally requires ALLOW_INGEST_PUBLICATION=true. Without it, the pipeline refuses publication and does not advance its source cursor. This is an operator-wide permission for that existing workflow, not item-by-item classification review.

Changed configuration must be loaded by restarted processes or cleared/rebuilt Laravel configuration caches. No schema migration, account rebinding, data conversion, or deletion is required. Previously published records remain public until changed through existing controls; this phase does not retract copies already held by clients.

## Diagnostics and retention

Model-call diagnostics contain a random request ID, operation, destination category, authorization result, message count, duration, and outcome. They do not retain the question, excerpt, response, title, raw owner key, session ID, credential, or a content fingerprint. Existing document error metadata can include local node IDs and chunk indexes. This is operational tracing, not a durable per-owner disclosure ledger.

The application exception handler suppresses raw exception messages, traces, previous exceptions, SQL bindings, and framework debug pages even when APP_DEBUG is enabled. It preserves authentication redirects, validation handling, and HTTP status categories. Console exception rendering is generic. This reduces debugging detail intentionally; investigate using synthetic reproductions rather than re-enabling payload diagnostics around a personal corpus.

No new telemetry integration was introduced. Laravel still offers configurable file, stderr, Slack, and Papertrail channels. Reverse-proxy access logs, PHP runtime diagnostics, browser extensions, model SDK instrumentation, and independently installed observability tools are outside these application guarantees. Do not log request bodies or full URLs with private query terms, and disable capture of headers, session cookies, source documents, and provider responses.

Provider retention, training policies, and deletion behavior are not controlled or guaranteed here. Use a model only when its configured destination and retention arrangements are acceptable. OpenRouter can route to the model provider selected by existing task-model settings; authorization covers that configured route, not a provider-neutral private computation guarantee.

## Threats closed and limits retained

Poisoned evidence cannot add a system-role message through the existing evidence path. Supplemental system/developer messages and unexpected message fields are rejected. The PHP boundary does not enable model tools or execute returned instructions. Models can still follow malicious lower-priority content, fabricate answers, or repeat sensitive evidence. Citation validation checks references, not truth or entailment.

Owner authentication no longer implicitly authorizes History Ask generation. Private document upload and private memory storage no longer trigger hidden model extraction. Novelty checks and consolidation exclude private records, and a model-selected public label no longer causes automatic chat publication.

Provider failures, malformed model output, browser adapter failures, and unhandled Laravel exceptions no longer intentionally serialize sensitive payloads into normal diagnostics. This is not a claim that arbitrary third-party logging or every dependency error is safe. The redactor is a configurable pattern-based safeguard, not proof that all personal data was removed.

The shared MCP key remains broad and is not an owner identity. It cannot read imported private history, but public records are deliberately available to other readers. The existing agent memory client still cannot authenticate to Laravel's owner-only refresh route using that key; its access was not broadened.

The optional agent remains disabled for disclosure by default. AGENT_ALLOW_UNSANDBOXED_TOOLS=true explicitly enables powerful existing tools, including interpreters and write operations; lexical path checks and executable allowlists are not a sandbox. Do not enable it against private personal data. This phase does not redesign agent execution, channel audience authorization, or MCP application grants.

Deployment-wide operation grants are a transitional self-hosting boundary. They are not per-owner, per-application, per-provider, purpose-limited, expiring, or transactionally revocable grants. Configuration revocation prevents subsequent calls after reload, not a request already in flight. Federation and multi-tenant processing should wait for stronger destination-aware grants and connector isolation.
