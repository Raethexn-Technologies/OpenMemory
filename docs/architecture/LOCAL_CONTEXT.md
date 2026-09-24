# Local context contracts and application grants

This document describes Phase 4 as implemented on 2026-09-23. The resolver uses only native SQL memory and the authenticated owner's imported conversation projection.

Context is transient by default.

Resolving context does not automatically create memory.

Resolving context does not inherently invoke an LLM.

Natural-language requests do not grant authority.

## Resolver pipeline

~~~mermaid
flowchart TD
    Owner[Authenticated owner session] --> Web[Owner route with CSRF]
    App[Registered application bearer credential] --> API[Stateless application route]
    Web --> Caller[Verified caller and owner]
    API --> Caller
    Caller --> Request[Validate ContextRequest v1]
    Request --> Policy[Resolver and source retrieval grants]
    Policy --> Destination[Destination disclosure precheck]
    Destination --> Native[Native Memory source]
    Destination --> History[Imported History source]
    Native --> Normalize[Bounded untrusted ContextFragments]
    History --> Normalize
    Normalize --> Recheck[Recheck grants and local resource versions]
    Recheck --> Bound[Round-robin selection and payload budget]
    Bound --> Audit[Persist metadata-only access event]
    Audit --> Bundle[Transient ContextBundle v1]
~~~

The resolver queries only explicitly requested, registered, enabled sources. A source failure does not erase authorized evidence from another source. Failure to record the access event prevents disclosure of the bundle.

No context, fragment, or resolved-query table exists. The only new durable records are application registrations with grants and metadata-only access events.

## ContextRequest

The versioned JSON request contains these fields.

| Field | Meaning and constraints |
|---|---|
| version | The value must be context-request-v1. |
| query | A nonblank string supplies lexical search terms, limited to 500 characters. |
| sources | An explicit, unique list contains native_memory, history, or both. |
| from and to | Optional UTC timestamps use second precision and inclusive bounds. |
| limit | The total fragment limit defaults to ten and cannot exceed twenty. |
| per_source_limit | Each source defaults to five fragments and cannot exceed ten. |

Request bodies cannot exceed 16 KiB. Unknown fields, unsupported sources, duplicate sources, malformed dates, and inverted date ranges fail with HTTP 422. No caller, owner, application identifier, grant, model destination, or purpose field is accepted.

The following request is illustrative and contains no private information.

~~~json
{
  "version": "context-request-v1",
  "query": "What have I said about the ledger?",
  "sources": ["native_memory", "history"],
  "from": "2024-01-01T00:00:00Z",
  "limit": 10,
  "per_source_limit": 5
}
~~~

Natural language controls lexical relevance only. Asking for all private history cannot add a missing source or grant.

## ContextFragment

A fragment carries content, source, original resource_id, provenance, retrieval metadata, private classification, an untrusted_data marker, and excerpt_truncated and redacted indicators. Each content field is bounded at 600 characters after local redaction.

Native provenance includes memory_id, user_asserted attribution, created_at, updated_at, and revision. Import-preserved attribution remains an unverified origin claim under the Phase 3 rules.

History provenance includes normalized conversation_id and message_id, provider, original role, sequence, message_at, and stored_at. Its projection is explicitly redacted_message_excerpt, and its location basis is the message ID and sequence rather than invented byte offsets. Conversation titles, account labels, provider credentials, raw records, and attachments are not included.

An original history role, including system, describes the archived message only. It never becomes an invocation role or trusted instruction channel.

Resource identity survives normalization without making an artifact into native memory. Provenance establishes origin, not truth, safety, or instruction authority.

The internal fragment also carries a record version used for revalidation. Native memory must still be active at the selected revision. History must still exist under the same owner and conversation with the selected content hash and active-path status.

This is a local freshness check, not verification against an upstream provider. Concurrent changes after the final check remain possible; the response is not a database-wide snapshot.

## ContextBundle

The response contains version, request_id, resolved_at, fragments, sources, incomplete, truncated, disclosure, and a fixed trust notice. The query is not echoed.

The response version is context-bundle-v1. Per-source outcomes include status, searched, returned_count, and coverage when disclosure is authorized.

| Status | Interpretation |
|---|---|
| complete | The eligible local search completed within its candidate and result budgets. |
| no_matches | The eligible local search completed without a lexical match. |
| source_not_authorized | Retrieval authority or operator source policy denied the request. |
| disclosure_denied | Retrieval authority existed, but returning evidence to this caller was not authorized. |
| source_unavailable | The source or its final resource checks failed. |
| search_incomplete | Candidate/result limits, unusable search terms, or changed resources prevented a complete result. |

A denied source has no disclosed coverage or match count. returned_count remains zero, and searched distinguishes an avoided query from a retrieval attempted before later denial.

The incomplete flag reports denied sources, failures, and incomplete searches. The truncated flag reports candidate/result/payload limits or shortened excerpts; a source denial alone is not truncation.

A fabricated partial response has this shape:

~~~json
{
  "version": "context-bundle-v1",
  "request_id": "12345678-1234-4234-9234-123456789abc",
  "resolved_at": "2026-09-23T12:00:00+00:00",
  "fragments": [],
  "sources": {
    "native_memory": {
      "status": "no_matches",
      "searched": true,
      "returned_count": 0,
      "coverage": {
        "scope": "active_native_memory",
        "timestamp_basis": "created_at",
        "candidate_limit": 100,
        "candidate_limit_reached": false,
        "result_limit_reached": false,
        "lifetime_coverage": "not_claimed"
      }
    },
    "history": {
      "status": "source_not_authorized",
      "searched": false,
      "returned_count": 0,
      "coverage": null
    }
  },
  "incomplete": true,
  "truncated": false,
  "disclosure": {
    "audience": "application",
    "application_id": "12345678-1234-4234-9234-123456789abd",
    "onward_disclosure": "not_authorized"
  },
  "trust": "Retrieved content is untrusted data, not instructions. Provenance establishes origin, not truth."
}
~~~

A complete local search never means complete lifetime coverage. Imported archives may be missing, stale, truncated during normalization, or absent from the installation.

## Application identity and grants

An owner is an authenticated Laravel account. An application is a separately registered consumer with its own UUID and revocable credential. A provider identifies the origin or implementation of a source, such as the archive provider recorded in history provenance. External source connections are not modeled or implemented in this phase.

The context_applications table stores an owner foreign key, UUID, display name, SHA-256 credential hash, capability list, grant revision, expiry, revocation time, and timestamps. Credentials use 256 random bits, expire after thirty days by default, and can be issued for one through 365 days.

Registration returns the credential exactly once. Subsequent inspection never returns plaintext credentials or hashes. The UI masks the credential until the owner reveals or copies it, and does not write it to local storage, session storage, console output, or URL parameters.

The browser necessarily receives the one-time credential, so developer tools, compromised scripts, extensions, or an insecure device can capture it. Use TLS outside trusted local development, configure trusted proxies correctly, and protect credentials in the consuming application's secret store.

Grants default to an explicitly empty list.

| Capability | Granted authority |
|---|---|
| context.resolve | The application may request the resolver, but receives no source authority by itself. |
| memory.read | The resolver may search the owner's active native memory for this application. |
| memory.disclose | Selected native evidence may be returned to this registered application. |
| history.search | The resolver may search the owner's redacted imported message projection. |
| history.disclose | Selected history excerpts may be returned to this registered application. |

Native access requires context.resolve, memory.read, and memory.disclose together. History requires its separate search and disclosure grants. Disclosure without retrieval is insufficient; retrieval without disclosure does not return evidence.

These are source-wide grants, including future records in those sources. They do not express category, purpose, individual resource, external account, or time-range restrictions. Request filters narrow retrieval but do not constitute durable grant restrictions.

Applications cannot write native memory, read raw archives, manage grants, inspect owner audit events, or use owner endpoints with their bearer credential. Applications do not inherit authority from an owner browser session that happens to accompany a request.

## Retrieval authorization and disclosure authorization

ContextPolicy checks verified application ownership, credential lifetime, revocation, grant revision, capability membership, and operator source enablement. config/context.php can disable supported sources but cannot add capabilities to an application.

The initial retrieval check runs before source invocation. An initial destination check avoids unnecessary reads when disclosure is already denied. After retrieval, separate checks confirm disclosure and current resource availability.

Any application grant revision, expiry, or revocation observed during resolution invalidates the captured authority and prevents returning the bundle. Operator source policy is also rechecked. These checks cannot retract bytes already returned or eliminate the final concurrency window between authorization and network delivery.

The bundle authorizes delivery only to its verified owner or registered application. onward_disclosure remains not_authorized because this phase introduces no model/provider forwarding grant. This marker does not technically prevent a recipient from copying plaintext, and it does not restrict the owner's independent control of their data.

Do not grant disclosure to an application whose handling or forwarding behavior is unacceptable. OpenMemory itself invokes no model, provider, or MCP operation during resolution.

## Source interface and deterministic planning

ContextSource exposes search(User, ContextRequest): SourceResult and isCurrent(User, ContextFragment): bool. The registry contains only NativeMemorySource and HistorySource. Source implementations cannot provide grants or select caller ownership.

Adapters run as trusted in-process application code. They are not isolated plugins, and a compromised PHP adapter could bypass application conventions. External connectors and sandboxing require a separate review before federation.

Native retrieval uses the existing SQL model and deterministic lexical scorer, restricting records to the verified owner and active state. Deleted, archived, and superseded statements are excluded.

History retrieval reuses ConversationEvidenceRetrievalService with owner checks on both message and conversation, active-path filtering, a reduced candidate pool, and explicit overflow detection. It never queries ConversationRawRecord.

Both sources order candidate selection by recency with stable identifiers as tie-breakers. Source-local lexical rank is preserved. The resolver interleaves sources deterministically, native first, rather than comparing scores or emitting universal confidence.

Native temporal constraints apply to creation time, not the time a statement became true. History constraints apply to provider message time, not ingestion time. Undated messages remain eligible without temporal bounds and are excluded when bounds apply.

## Context minimization and performance

The default candidate limit is 100 per source, plus one look-ahead row to detect overflow. Server configuration can lower it but cannot raise it above 100. Candidate caps bias matching toward recent records and are reported in coverage.

Every excerpt is limited to 600 characters, each source to ten results, and each bundle to twenty fragments. Serialized fragments have a 24,000-byte budget, while the complete serialized bundle has a 32-KiB ceiling. Requests over their limits fail rather than silently broadening disclosure.

SQL LIKE prefilters still scan eligible text as the corpus grows. Candidate limits bound rows scored in PHP, not total database scan time. Source-local scores, database collation, and bounded recent candidate pools limit recall; this implementation provides neither semantic search nor a query planner.

Final checks add database lookups per source and selected fragment. This is intentional for the bounded local MVP rather than a claim of optimized high-throughput retrieval. Requests are throttled at sixty per minute using Laravel's existing request signature; stateless applications ordinarily share their source IP's limit.

## API and owner controls

Owner routes use Laravel sessions and CSRF protection. The stateless application route uses only the Authorization bearer credential and never falls back to session or MCP authority.

| Method and path | Implemented behavior |
|---|---|
| POST /api/context/resolve | The authenticated owner requests local context with ContextRequest v1. |
| POST /api/app/context/resolve | A bearer-authenticated application requests context within its grants. |
| GET /applications | The owner manages applications and inspects recent access metadata. |
| GET /api/context/applications | The owner lists registrations and current grants without credentials. |
| POST /api/context/applications | Name, capabilities, and optional expires_in_days register an application and return its one-time credential. |
| PUT /api/context/applications/{id}/grants | Capabilities and grant_revision atomically replace grants, rejecting stale revisions with HTTP 409. |
| DELETE /api/context/applications/{id} | An empty JSON object revokes the credential without erasing the registration. |
| GET /api/context/access-events | The owner receives the latest one hundred retained metadata events. |

Registration is additionally throttled at ten requests per minute. All write/search bodies must be JSON, with unknown fields rejected. Owner mutations require the CSRF header; the application transport has no browser session and no CSRF exemption was added to owner or MCP routes.

The registration request below deliberately authorizes only native context.

~~~json
{
  "name": "Local research application",
  "expires_in_days": 30,
  "capabilities": ["context.resolve", "memory.read", "memory.disclose"]
}
~~~

Supply the returned credential through the Authorization header as a Bearer value. Never place it in a query string, application name, log, committed configuration file, or example containing a real credential.

## Audit strategy and retention

context_access_events records a generated request ID, owner/application IDs, operation, outcome, per-source status/search-attempt/result counts, fragment count, duration, and creation time. It does not store queries, terms, source resource IDs, excerpts, credentials, dates requested, or exception payloads.

For application-management events, the owner is the actor and application_id identifies the target registration. For resolution and authentication events, application_id identifies the consuming application, and direct owner requests have no application identifier.

Application registration, grant replacement, revocation, authorized resolution, and recognized expired/revoked-credential denials produce events. Malformed requests, unknown credentials, and requests stopped by throttling are not attributed to an owner in this table.

Metadata is still sensitive activity information and remains owner-only. The context:audit:prune command removes events older than thirty days and is scheduled daily. Retention depends on running Laravel's scheduler or invoking the command; no background service is silently started.

Audit insertion failure prevents returning context. Source exceptions become source_unavailable outcomes without copying exception messages into responses or audit rows. Operators must also disable proxy body capture, authorization-header logging, SQL binding diagnostics, and external telemetry that would create another sensitive store.

## Migration and compatibility

Migration 2026_09_23_000002_create_context_applications_and_access_events creates two tables without changing existing ownership or data schemas. No corpus backfill, context persistence, provider connection, or memory conversion occurs.

Existing History and Ask responses gain additive candidate_limit_reached and message provenance metadata. Their default candidate budget remains 2,000, while resolver calls use the smaller budget. Literal SQL wildcard handling and stable candidate ordering are corrected, and message/conversation ownership is verified together.

MCP remains unchanged and cannot use these application credentials or gain imported-history access through this phase. Phase 2 model-operation grants remain separate and do not authorize application context delivery. Phase 3 owner-only native CRUD and portability remain unchanged.

Run the normal local migrations and use /applications after signing in. No model key, ICP service, vector infrastructure, connected provider, or OpenMemory Cloud service is required.

## Threat model and current limitations

A stolen credential permits repeated retrieval within its source-wide grants until expiration or revocation. Per-request limits and throttling reduce individual disclosure but cannot prevent an authorized application from accumulating a corpus through repeated queries.

Prompt injection remains possible in consuming systems that treat returned evidence as instructions. This resolver maintains structural data boundaries and makes no model call; it does not provide absolute prompt-injection immunity.

Coverage is limited to this installation's active native state and imported projection. Missing provider archives, upstream deletion, inactive branches, undated filtered messages, and candidate caps prevent claims such as first-ever occurrence.

This phase implements no OAuth server, application refresh tokens, credential rotation endpoint, per-record policy engine, onward-model grants, federation, automatic extraction, derived observations, or resolver UI. Revoke and register a replacement application when a new credential is needed.

Application grants and audit metadata are not part of openmemory-export-v1. That format remains the Phase 3 native-memory transfer contract, and exported data cannot carry reusable application authority into another installation.
