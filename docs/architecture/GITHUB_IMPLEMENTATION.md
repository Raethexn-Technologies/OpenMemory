# Phase 5 implementation and architecture evaluation

This report records the GitHub federation experiment completed on 2026-09-24 against the committed Phase 4 baseline. The detailed [provider guide](GITHUB_PROVIDER.md) contains setup instructions, official research references, the temporal example, and operational semantics.

The subsequent [post-Phase-5 review](POST_PHASE_5_REVIEW.md), dated 2026-09-25, evaluates the complete system and qualifies this report's bounded architectural validation. Live GitHub behavior and independent application usefulness remain unvalidated; the later review contains the manual validation checklist and recommended next milestone.

## Verification before and after implementation

| Check | Before implementation | After implementation |
|---|---|---|
| Backend suite | All 565 tests passed with 2,843 assertions. | All 613 tests passed with 3,285 assertions. |
| Frontend suite | All 37 tests passed across four files. | All 41 tests passed across five files. |
| CLI suite | No new baseline run was recorded. | All five existing tests passed. |
| Disclosure security suite | No new baseline run was recorded. | All six existing tests passed. |
| Frontend production build | No new baseline build was recorded. | The build completed with the existing ThreeD chunk-size warning. |
| Agent syntax checks | No new baseline run was recorded. | All seven source files checked by CI passed. |
| PHP formatting | No baseline formatting changes were needed. | The provider and touched resolver files passed the installed formatter. |

Forty-eight backend cases were added in GitHubFederationTest. They cover encrypted connection credentials, verified identity, forged identity rejection, owner isolation, explicit resource grants, independent source grants, disclosure denial, application revocation, reconnect reset, repository identity changes, and deselection without network access.

Temporal tests exercise all four combinations of connection consent and application cross-source permission. They verify minimized outbound dates, absent anchors, actual synthetic database history combined with native and GitHub evidence, timestamp freshness, and consent withdrawal between metadata and commit requests. The fixture is fabricated in ConversationFixtures; no personal archive was inspected.

Failure tests distinguish unavailable providers, timeouts, rate limits, invalid credentials, local expiry, inaccessible repositories, no matches, pagination truncation, mixed repository outcomes, redirects, malformed responses, oversized responses, and authorization changes during retrieval. Additional checks cover response header limits, three-repository bounds, credential redaction, native export isolation, metadata-only audit records, CSRF, and untrusted commit instructions.

Four frontend tests were added for masked token submission and clearing, explicit discovery and selection, inert rendering of provider labels, disconnection, and application repository grants. Existing application tests now recognize the additional capabilities and empty resource allowlists. The Phase 4 unsupported-source fixture now names an unsupported provider because GitHub is supported.

The final backend run retained the Phase 2 disclosure, Phase 3 native-memory, Phase 4 resolver/grant, ownership, raw-history, and MCP regression tests. HTTP calls use synthetic responses with unexpected requests prevented. No real token, private repository payload, model call, cloud service, or ICP connection was used.

## Implementation and migration

Authentication uses a fine-grained personal access token with selected repositories, Contents read permission, required Metadata read permission, and an explicit local expiry. GitHub account identity comes from the authenticated user endpoint; it never authenticates an OpenMemory owner. GitHub App and OAuth alternatives were researched but are not implemented.

Migration `2026_09_24_000001_create_federated_source_connections.php` creates source_connections and source_resources and adds context_applications.source_resources. It changes no imported-history or native-memory schema and needs no backfill. Test databases exercised the migration; the owner's database was not migrated.

Repository discovery exposes repository.list only to the authenticated owner. Resolution exposes repository.commits.list through selected resources and centralized grants. Commit metadata becomes the existing ContextFragment shape, with repository ID and SHA as identity, redacted message content, account and repository provenance, author association when available, separate event/retrieval timestamps, and an explicit retrieval method.

The resolver gained a deterministic planning step and internal SourceAccess snapshots. ContextPolicy authorizes external resources and history-to-GitHub date disclosure before each outbound operation and at final disclosure. ContextRequest accepts an optional explicit temporal operator; SourceResult can preserve provider failure status and attempted retrieval; ContextFragment can describe a nonlexical retrieval method. The ContextSource method signatures and ContextBundle version remain unchanged.

Applications require github.commits.read, github.disclose, and explicit selected repository UUIDs in addition to context.resolve. history.query_disclose.github separately permits authorized history dates to constrain GitHub requests when the owner has enabled connection consent. Existing native and history capabilities retain their original scope. Requests cannot supply authority snapshots, arbitrary URLs, or expanded repository selections.

The client streams bounded responses from fixed GitHub.com HTTPS endpoints with redirects disabled, explicit API versioning, and request/read timeouts. Resolution limits repositories, commit pages, commit counts, date windows, and final bundle size. GitHub rate headers can persist a retry timestamp but never evidence. There is no content cache, synchronization engine, or source-code retrieval.

The owner UI adds connection, verified account inspection, discovery, selection, date consent, and disconnection at /sources/github. The existing application page adds GitHub capabilities and explicit repository grants. Credentials are encrypted using Laravel, hidden from serialization, and cleared locally on disconnection or rejected authentication.

## Security and retention assessment

The implementation preserves separate owner and external identities, defaults external authority to empty grants and selection, minimizes outbound queries, and treats commit messages as untrusted evidence. Repository numeric identity checks prevent name reuse from expanding access. Deleting selection UUIDs prevents old grants from reviving after reselection or reconnect.

Connection revisions, application revisions, source checks, and timestamp freshness checks detect observed changes during retrieval. They cannot eliminate the final delivery race, recall a date already sent to GitHub, or erase a bundle retained by an application. isCurrent checks local authority after a live response rather than promising a distributed snapshot.

Disconnection removes credentials, consent, retry metadata, and selected repositories while retaining owner-only connection identity and timestamps. Rejected tokens are removed after a 401. Repository deselection removes its configuration row; upstream deletion is reported during the next live request without a cached-content fallback. Audit records retain metadata under the existing scheduled thirty-day pruning policy.

Host operators and holders of the Laravel application key can decrypt credentials. Upstream PAT permissions are broader than the endpoints OpenMemory chooses to call, and an owner can supply an overprivileged fine-grained token. Database backups, browser compromise, HTTP diagnostics, and external telemetry remain deployment responsibilities. Disconnection does not globally revoke other token copies at GitHub.

## Architecture evaluation

The conclusion is VALIDATED for this bounded first-provider experiment. That judgment rests on preserving the source interface and consumer envelope while implementing federation through one provider, not merely on passing tests.

| Question | Architectural finding |
|---|---|
| Did GitHub fit the Phase 4 source interface? | It implemented search and isCurrent without changing either signature or converting commits into native records. |
| Which Phase 4 contracts changed? | ContextRequest gained optional temporal intent and internal authority, ContextFragment gained a method field, SourceResult gained explicit outcomes, and application grants gained resource selectors. Bundle outcomes expanded without changing the envelope. |
| Were changes generic or provider leakage? | Planning, minimized requests, authority snapshots, resource checks, outcomes, and retrieval methods are generic. GitHub names remain in provider registration, capability configuration, explicit input allowlists, and its owner UI; the resolver loop contains no GitHub branch. |
| Could another provider implement the interface? | A bounded request/response metadata provider could reuse the scope, outcome, freshness, and fragment contracts while supplying its own authentication and query compiler. This is plausible rather than demonstrated. |
| Did federation require storing external content? | It persisted connection identity, selected repository configuration, credentials, cooldown metadata, and audit events. Commit payloads and resolved context remained transient. |
| Did authorization remain centralized? | ContextPolicy makes source, recipient, resource, application-revision, and cross-source decisions. Providers call those checks before transport, and the resolver performs final disclosure checks. |
| Could consumers remain provider-agnostic? | Consumers use the same request transport and bundle/fragment fields. Configuring a GitHub connection requires provider knowledge; consuming returned evidence requires neither credentials nor provider-specific API calls. |
| Which assumptions failed? | A fixed lexical retrieval label and a source result lacking failure codes were insufficient. Local content hashes alone did not validate a temporal anchor. Upstream commit-only token scope and unambiguous revocation/deletion causes cannot be assumed. |
| Is Context Resolver still the correct abstraction? | It remains the appropriate bounded authorization and evidence assembly boundary. Connection management, provider transport, and interpretation stay outside its source loop. |

The original architecture report's central proposal survived: authorized external evidence can be retrieved at its source without a durable content copy or an autonomous planner. Its cautions about query disclosure, in-process adapter privilege, and irreversible recipient disclosure remain applicable. The experiment does not demonstrate a universal connector interface for streaming, bulk export, or arbitrary provider computation.

Status vocabulary is extensible even though the bundle remains version one. Consumers that assumed an exhaustive Phase 4 status enumeration need to tolerate the added outcomes. The owner UI and provenance necessarily expose provider details without requiring provider-specific evidence parsing.

## Current limitations and operational follow-up

GitHub.com and one fine-grained token connection per owner are supported. Token resource-owner restrictions, organization approval, manual expiration management, and default-branch coverage remain limitations. Renamed repositories require reselection, discovery is bounded, and the resolver considers only three authorized repositories per request.

The temporal experiment chooses one ranked dated history match and one bounded window. It cannot prove that every repository commit represents the owner's work or resolve ambiguous natural language without explicit temporal intent. Missing archives, undated history, other branches, provider outages, and pagination limit coverage.

There was no live private-repository acceptance run, PostgreSQL concurrency run, or browser automation session against a running server. Validation consists of synthetic backend integration, frontend component tests, transport-option tests, regression suites, and a successful production build. The next owner action is to review the changes, migrate the local schema, and configure an explicitly scoped GitHub token if live use is desired.

No other provider, publishing operation, deployment, commit, or push was performed. All changes remain in the working tree for owner review.

## Changed files

The complete implementation touches the following files, grouped by responsibility. Existing dated reports were not rewritten.

```text
app/database/migrations/2026_09_24_000001_create_federated_source_connections.php
app/app/Models/SourceConnection.php
app/app/Models/SourceResource.php
app/app/Models/ContextApplication.php

app/app/Services/GitHub/GitHubClient.php
app/app/Services/GitHub/GitHubConnections.php
app/app/Services/GitHub/GitHubFailure.php
app/app/Services/GitHub/GitHubSource.php
app/app/Services/Context/SourceAccess.php
app/app/Services/Context/ContextPlan.php
app/app/Services/Context/ContextApplications.php
app/app/Services/Context/ContextFragment.php
app/app/Services/Context/ContextInput.php
app/app/Services/Context/ContextPolicy.php
app/app/Services/Context/ContextRequest.php
app/app/Services/Context/ContextResolver.php
app/app/Services/Context/ContextSources.php
app/app/Services/Context/HistorySource.php
app/app/Services/Context/SourceResult.php
app/app/Services/RedactionService.php
app/config/context.php

app/app/Http/Controllers/GitHubConnectionController.php
app/routes/web.php
app/resources/js/Components/AppLayout.vue
app/resources/js/Pages/Applications/Index.vue
app/resources/js/Pages/Sources/GitHub.vue

app/tests/Feature/GitHubFederationTest.php
app/tests/Feature/ContextResolverTest.php
app/tests/Support/ConversationFixtures.php
app/resources/js/Pages/Applications/Index.spec.js
app/resources/js/Pages/Sources/GitHub.spec.js

docs/architecture/GITHUB_PROVIDER.md
docs/architecture/GITHUB_IMPLEMENTATION.md
README.md
CONTRIBUTING.md
DEVLOG.md
```
