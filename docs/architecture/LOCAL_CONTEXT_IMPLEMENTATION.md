# Local context implementation report

Phase 4 was implemented on 2026-09-23, with final verification completed on 2026-09-24. It adds local context resolution over native memory and imported history, plus owner-managed application grants. External federation remains unimplemented.

The [contract guide](./LOCAL_CONTEXT.md) contains the request and response examples, complete API reference, Mermaid pipeline, and threat model. [ADR 0012](../adr/0012-local-context-and-application-grants.md) remains proposed pending review of this implementation.

## Verification results

The baseline below was measured after the accepted Phase 3 finishing changes and before Phase 4 implementation. Those existing working-tree changes were preserved.

| Check | Before Phase 4 | After Phase 4 |
|---|---|---|
| Backend regression suite | 514 tests passed with 2,588 assertions. | 565 tests passed with 2,843 assertions. |
| Frontend component tests | 29 tests passed across three files. | 37 tests passed across four files. |
| CLI tests | Five tests passed before implementation. | All five tests continue to pass. |
| Node disclosure-security tests | Six tests passed before implementation. | All six tests continue to pass. |
| Production frontend build | The build passed with the existing large-chunk warning. | The build passes with the same ThreeD chunk warning. |
| Scoped PHP formatting | This check concerns newly introduced files. | Pint passes for 25 selected files. |
| PHP syntax | This check concerns changed Phase 4 files. | Syntax checks pass for 29 files. |
| Composer metadata | The existing lock file was out of date. | Validation still reports stale lock metadata; dependencies were not changed. |

The full backend command was php artisan test --compact from app. Frontend verification used npm run test:front and npm run build from app. CLI and disclosure checks used npm run test:cli and npm run test:security from the repository root.

No final functional test regression remains. The expanded backend suite initially exhausted the default 128 MiB budget while materializing an existing large native-memory portability fixture. PHPUnit now has a test-only 256 MiB limit, and the normal backend command passes. Production PHP limits remain unchanged.

The existing Composer lock mismatch and build size warning were not repaired through unrelated dependency or UI changes. No live provider, real archive, production database migration, or external deployment was needed for verification.

## Added test coverage

ContextResolverTest adds 51 backend cases, including parameterized request-validation cases. The eight new application-page tests verify explicit grants, one-time credential handling, revocation, revision conflicts, safe error messages, and metadata-only access display.

Backend cases cover the following guarantees.

- Owner requests remain isolated across native records and imported corpora.
- Application credentials establish their own owner even when another owner's browser session accompanies the request.
- Query text, supplied identifiers, owner sessions, and MCP keys cannot replace application authentication.
- Resolver permission alone supplies no source authority, and native grants do not grant history access.
- Retrieval and disclosure are checked separately, including a disclosure denial after retrieval.
- Expiry, revocation, and grant revisions invalidate captured application authority.
- Applications cannot register applications, change grants, write native memory, or inspect owner access events.
- Source failure preserves other authorized evidence and reports incomplete coverage.
- Resource deletion and lifecycle changes prevent selected native records from appearing.
- Provenance survives normalization, including archived system-role messages that remain untrusted evidence.
- Candidate, result, excerpt, request, and response budgets are enforced.
- Temporal filters use documented source-specific timestamps rather than inferred event time.
- Adversarial instructions remain data, and sensitive query, content, credential, and exception payloads do not enter access records or normal logs.
- Raw conversation records are not queried by the resolver, and MCP history exclusion remains intact.
- Literal SQL wildcard characters do not unexpectedly broaden history matching.
- Owner operations retain CSRF protection, while application authentication is stateless.
- Audit failure prevents disclosure, and retention pruning removes only expired access metadata.

The regression suite retains the ownership, disclosure, native-memory lifecycle, export/import, and local-operation tests from earlier phases. Resolver tests disable model authority and prevent unexpected HTTP calls, so success does not depend on ICP, an LLM, a connected provider, or Cloud.

## API and contracts

Owner requests use POST /api/context/resolve with Laravel session authentication and CSRF protection. Applications use POST /api/app/context/resolve with a dedicated bearer credential and no session fallback.

ContextRequest requires version context-request-v1, query, and an explicit sources list containing native_memory, history, or both. Optional UTC from/to bounds and result limits narrow the request. Unknown fields are rejected, including caller-supplied ownership and grant identifiers.

ContextFragment carries an excerpt, original resource identity, source, provenance, source-local rank, private classification, and an untrusted-data marker. Native fragments retain memory identity, attribution, revision, and timestamps. History fragments retain conversation and message identity, provider, original role, sequence, and message/storage timestamps.

ContextBundle uses version context-bundle-v1 and contains fragments, source outcomes, coverage where authorized, request identity, resolution time, incomplete/truncated indicators, and verified recipient metadata. It does not echo the query or persist the returned context.

The owner application-management surface provides these endpoints.

| Method and path | Purpose |
|---|---|
| GET /applications | The owner inspects registrations and recent access metadata. |
| GET /api/context/applications | The owner lists registrations without plaintext credentials or hashes. |
| POST /api/context/applications | The owner creates a registration and receives its credential once. |
| PUT /api/context/applications/{id}/grants | The owner replaces capabilities using a checked grant revision. |
| DELETE /api/context/applications/{id} | The owner revokes the registration's credential. |
| GET /api/context/access-events | The owner inspects the latest one hundred retained events. |

## Grants and disclosure

Registrations are owner-scoped and have no implicit grants. Each bearer credential contains 256 random bits; only its SHA-256 hash is stored. Credentials expire after thirty days by default, with an owner-selected range of one through 365 days.

Native evidence requires context.resolve, memory.read, and memory.disclose. Imported history requires context.resolve, history.search, and history.disclose. Neither source receives write authority, and no capability grants raw-history access.

The resolver checks retrieval before querying, prechecks the disclosure destination to avoid unnecessary reads, and independently rechecks disclosure after retrieval. It revalidates local record state and captured application grant revision before returning the bounded response.

Grant changes and revocation are owner-only operations. The UI starts with every capability unchecked, warns about source-wide plaintext disclosure, and keeps the one-time credential only in transient component state.

## Resolver and source behavior

The source registry contains only NativeMemorySource and HistorySource. Both implement a small internal search and current-resource validation interface that can be wrapped or extended later without changing canonical storage.

NativeMemorySource searches active native SQL records using deterministic lexical relevance. Archived, superseded, and deleted records are excluded. Temporal constraints apply to creation time, not an inferred effective date.

HistorySource reuses ConversationEvidenceRetrievalService over the redacted active-path projection. Both message and conversation ownership are checked. Temporal constraints apply to provider message time; undated messages are excluded when a date bound applies. No raw record, title, attachment, or complete archive is returned.

Each source ranks its own matches. The resolver interleaves those ranked lists in a fixed order instead of comparing incompatible scores or inventing confidence values.

Default candidate selection is bounded at one hundred records per source, with one look-ahead record for overflow detection. Responses permit at most ten fragments per source, twenty overall, and six hundred characters per excerpt. Serialized fragments have a 24,000-byte budget, and the full bundle cannot exceed 32 KiB.

Context resolution does not create memories, invoke a model, or make an outbound provider call. The new disclosure destination is the authenticated owner or explicitly authorized consuming application receiving the HTTP response.

## Partial failure and audit behavior

Per-source outcomes distinguish complete, no_matches, source_not_authorized, disclosure_denied, source_unavailable, and search_incomplete. Denied sources disclose no coverage or match counts, and a failure in one source does not discard successful authorized evidence from another.

A source denial makes coverage incomplete without falsely claiming payload truncation. Candidate, result, and payload limits report truncation; resource changes also make the search incomplete. Complete means the eligible local search completed within its budgets, not that OpenMemory holds the person's complete history.

Access events contain request, owner and application identifiers, operation, outcome, source statuses, returned counts, duration, and time. They contain no query, excerpt, source resource identifiers, credentials, or exception text. For application-management events, the owner is the actor and application_id identifies the target registration. For resolution and authentication events, application_id identifies the consuming application.

Failure to persist the access event prevents bundle delivery. A daily scheduled context:audit:prune command removes events older than thirty days, provided the operator runs Laravel's scheduler or invokes the command. Invalid unknown credentials and requests rejected before attributable authentication are not written to an owner's access table.

## Migration and compatibility

The single new migration is 2026_09_23_000002_create_context_applications_and_access_events.php. It creates context_applications and context_access_events, with owner foreign keys and indexes, and can roll back those two tables without changing native-memory or history schemas.

No production migration was run during implementation. After review, an operator must run the normal Laravel migrations before using the new routes. Rolling back the new tables discards application credentials, grants, and access records, not the underlying corpus.

There is no contexts table, corpus backfill, automatic memory conversion, or provider-connection schema. Phase 3 native CRUD and portability retain their existing ownership and lifecycle semantics. Application credentials, grants, and audit records are not exported through openmemory-export-v1.

History retrieval gains additive provenance and candidate-overflow fields. Existing History and Ask calls keep their default 2,000-candidate budget; resolver calls explicitly select the smaller budget. Literal wildcard escaping, stable tie-breaking, and matching message/conversation ownership are tightened.

MCP routes and authentication were not expanded. Phase 2 model-operation permissions remain separate from application-disclosure grants, and neither permission system silently authorizes the other destination.

## Changed components

The Phase 4 additions and modifications are grouped below. Paths are relative to the repository root.

| Component | Files |
|---|---|
| Application persistence | app/database/migrations/2026_09_23_000002_create_context_applications_and_access_events.php; app/app/Models/ContextApplication.php; app/config/context.php. |
| Transient contracts | app/app/Services/Context/ContextInput.php, ContextRequest.php, ContextCaller.php, ContextFragment.php, ContextBundle.php, and SourceResult.php. |
| Source abstraction and adapters | app/app/Services/Context/ContextSource.php, ContextSources.php, ContextExcerpt.php, NativeMemorySource.php, and HistorySource.php. |
| Policy, orchestration, and audit | app/app/Services/Context/ContextPolicy.php, ContextApplications.php, ContextResolver.php, and ContextAudit.php. |
| HTTP boundary | app/app/Http/Middleware/ContextJson.php and AuthenticateContextApplication.php; app/app/Http/Controllers/ContextController.php and ContextApplicationController.php. |
| Routing and retention | app/routes/context.php, web.php, and console.php; app/bootstrap/app.php; app/app/Console/Commands/PruneContextAccessEvents.php. |
| Reused history retrieval | app/app/Services/Conversations/ConversationEvidenceRetrievalService.php. |
| Owner application interface | app/resources/js/Pages/Applications/Index.vue and Index.spec.js; app/resources/js/Components/AppLayout.vue. |
| Backend verification | app/tests/Feature/ContextResolverTest.php and app/phpunit.xml. |
| Architecture documentation | docs/architecture/LOCAL_CONTEXT.md, LOCAL_CONTEXT_IMPLEMENTATION.md, DISCLOSURE_BOUNDARY.md, and NATIVE_MEMORY.md. |
| Decision records | docs/adr/0012-local-context-and-application-grants.md, 0002-context-evidence-contract.md, 0006-authorization.md, and README.md. |
| Repository documentation | README.md, CONTRIBUTING.md, SECURITY.md, and the appended DEVLOG.md entry. |

Previously accepted Phase 3 finishing changes also remain in the working tree. They include native service/controller tests and portability documentation, and are not attributed to Phase 4 in the test baseline or migration count.

## Performance and known limitations

SQL substring prefilters can scan substantial eligible text. Candidate limits bound PHP scoring work rather than database scan cost, and recency-biased candidates can omit older relevant evidence. Database collation affects lexical matching, while per-fragment freshness checks add bounded database round trips.

Application grants cover an entire source, including future records. They do not restrict individual memories, categories, purposes, external accounts, or durable time ranges. Per-request budgets and throttling cannot prevent an authorized application from accumulating data across requests.

OpenMemory cannot retract delivered plaintext or technically prevent the recipient from forwarding it. The onward_disclosure marker records that no onward model/provider grant exists; it is not a data-loss-prevention mechanism. TLS, safe proxy logging, and application credential storage remain deployment responsibilities.

Sources run as trusted in-process code, not isolated plugins. Final policy and resource checks cannot eliminate the concurrency window before network delivery, and the response is not a transactionally consistent corpus snapshot.

Only SQLite-backed automated behavior was verified for this phase. Non-SQLite concurrency, deployed proxy configurations, and a manual end-to-end browser session were not validated. Application tokens have expiry and revocation but no refresh or rotation endpoint; replacement requires a new registration.

Audit metadata still reveals activity patterns, and its retention depends on scheduler operation. Source exceptions are suppressed into unavailable outcomes rather than logged with payloads. Detailed payload-based diagnostics must not be enabled to compensate.

## Architectural decisions and review boundary

This implementation narrows the proposed design to explicit source selection, deterministic interleaving, separate source retrieval/disclosure capabilities, and two distinct authentication transports. It deliberately omits purpose claims, external connection identities, universal context persistence, autonomous planning, and forwarding grants.

Review should focus on whether source-wide application grants are sufficiently understandable for the intended users, whether the audit retention policy fits deployment expectations, and what consistency and narrower authorization guarantees will be required before federation.

No external connector, Context Resolver model-generation workflow, vector infrastructure, derived observation, native-memory redesign, Cloud dependency, or commercial service was added. Work stops at the local resolver and application-grant review boundary.
