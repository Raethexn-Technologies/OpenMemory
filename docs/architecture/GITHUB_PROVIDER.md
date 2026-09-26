# GitHub federated context provider

This guide describes Phase 5 implemented on 2026-09-24. GitHub provides live repository and commit metadata behind the existing context resolver. The dated [Phase 4 guide](LOCAL_CONTEXT.md) remains the record of the local baseline; this document describes its additive extensions.

No live GitHub account has yet been validated. The [post-Phase-5 manual checklist](POST_PHASE_5_REVIEW.md#8-manual-live-system-validation-checklist) specifies the real-account checks, expected outcomes, and cases that should remain synthetic rather than consume or disrupt a real account's quota.

## GitHub Provider

GitHubSource implements the existing ContextSource search and isCurrent methods. The resolver returns ContextBundle v1 with transient ContextFragment values, separate source outcomes, and private, untrusted evidence classification. Federation neither creates native memory nor invokes a model.

The provider uses GitHub.com REST endpoints exclusively. It does not call the legacy GitHub ingestion pipeline, retrieve files or patches, mirror repositories, synchronize content, or create an index. Legacy ingestion remains a separate operation with its existing disclosure policy.

## Authentication

The local experiment uses an expiring fine-grained personal access token. Create it for one resource owner, choose only the intended repositories, and grant Contents read permission plus GitHub's mandatory Metadata read permission. No write, organization administration, email, or webhook permission is requested. Organizations may require approval before private resources become accessible. Fine-grained tokens have resource-owner and collaborator limitations described in [GitHub's token documentation](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens).

Contents read is the narrowest documented permission for the chosen commit-list endpoint, but it also permits reading files upstream. OpenMemory narrows that permission through fixed metadata operations. The token prefix rejects classic tokens but does not prove that an owner created a minimally privileged token. No general token-permission introspection is claimed. See the [commit endpoint](https://docs.github.com/en/rest/commits/commits#list-commits) and [fine-grained permission reference](https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens).

GitHub Apps would be preferable for a distributed integration needing managed installation lifecycles. They support repository selection and narrowed installation tokens that expire after one hour, but require app registration and private-key management. This phase does not implement that flow. Installation token formats changed during 2026, so future support must not assume a forty-character token. See [GitHub Apps](https://docs.github.com/en/apps/creating-github-apps/about-creating-github-apps/about-creating-github-apps) and [installation tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-an-installation-access-token-for-a-github-app).

OAuth Apps are not used because their private-repository repo scope includes broader read and write authority. GitHub authentication establishes an external account association only; an authenticated OpenMemory session establishes ownership. Matching numeric identifiers or names never links owners. See [OAuth scopes](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps) and the [authenticated user endpoint](https://docs.github.com/en/rest/users/users#get-the-authenticated-user).

The client explicitly requests API version 2026-03-10. GitHub currently supports the previous 2022-11-28 version until March 10, 2028. The implementation does not depend on GitHub's default version. See [API versioning](https://docs.github.com/en/rest/about-the-rest-api/api-versions).

## Source Connections

An owner manages GitHub at `/sources/github` after signing in locally. The token is submitted in a masked field and cleared after submission, including failure. OpenMemory verifies the account through GitHub's user endpoint and accepts no claimed external account ID from the form. The owner supplies the token's expiry, which becomes an enforced local deadline. Earlier upstream expiry still produces a credential failure.

| Identity | Representation and meaning |
|---|---|
| OpenMemory owner | The users foreign key identifies the authenticated local account. |
| OpenMemory application | A context_applications UUID identifies a separately authorized consumer. |
| GitHub provider | The registry identifies the trusted bundled implementation. |
| GitHub external account | The verified account ID and login describe the token holder. |
| GitHub source connection | An owner-bound UUID holds configuration for one GitHub association. |
| Selected repository | A source_resources UUID binds upstream repository identity to this connection. |

One GitHub connection per owner is supported. The token holder and a repository's owning organization need not be the same identity. Reconnection requires prior disconnection, resets cross-source consent, and requires repository reselection and new application resource grants.

Migration `2026_09_24_000001_create_federated_source_connections.php` creates source_connections with its UUID, owner foreign key, provider, external account ID/login, encrypted credential, credential deadline, disconnection time, retry time, query disclosure list, revision, and timestamps. An owner/provider uniqueness constraint permits one configuration slot.

It also creates source_resources with its UUID, connection foreign key, external repository ID, reference, selection flag, revision, and timestamps. A nullable JSON source_resources allowlist is added to context_applications and interpreted as empty for existing registrations. No content table or corpus backfill is introduced.

Run `php artisan migrate` from app to install the schema. Implementation tests migrate only synthetic in-memory databases; this work does not migrate the owner's existing database.

## Repository Selection

Repository discovery is an explicit owner action. Pages contain at most thirty repositories, and the UI permits ten pages. Discovery results are transient and never select repositories implicitly. At most twenty repositories can be selected; their identity metadata is configuration rather than a content cache. See [repository listing](https://docs.github.com/en/rest/repos/repos#list-repositories-for-the-authenticated-user).

Selection verifies each newly selected repository with GitHub and records its numeric ID. Resolution checks that the reference still resolves to that ID before requesting commits. Redirects and changed identities fail closed. A renamed or transferred repository requires owner review and reselection; a reused name cannot silently grant access to another repository.

Applications cannot invoke discovery or selection endpoints. Queries accept no repository URLs or additional resource identifiers. The resolver intersects owner selection with the application's resource allowlist and uses at most three repositories in stable local-ID order. Additional repositories produce incomplete coverage.

Deselection deletes the configuration row and invalidates its UUID. Reselection creates a new UUID, preventing old application grants from reactivating. Deselecting repositories and withdrawing date consent require no GitHub call. Connection revisions reject stale owner updates and detect concurrent selection changes.

## Capabilities

The registry advertises repository.list for owner discovery and repository.commits.list for resolution. The latter lists metadata from the current default branch with since and until parameters. GitHub search is deliberately not exposed.

GitHub search has separate rate limits, capped results, default-branch commit coverage, and incomplete-result responses. Its permission requirements differ from commit listing and cannot substitute for the chosen endpoint's requirements. See [search documentation](https://docs.github.com/en/rest/search/search#search-commits).

## Grants

The owner edits application permissions at `/applications`. Existing registrations gain no external access from this migration.

| Method and route | Owner operation |
|---|---|
| GET /api/context/github | This inspects connection identity, selection, and capabilities. |
| POST /api/context/github | This verifies and stores an expiring fine-grained token. |
| POST /api/context/github/{id}/repositories | This retrieves one bounded discovery page. |
| PUT /api/context/github/{id} | This replaces selected repository references and date consent using a revision check. |
| DELETE /api/context/github/{id} | This disconnects using an empty JSON object. |

Connection creation accepts token and expires_at only. Selection accepts revision, repositories, and query_disclosures; repositories contains explicit owner/name strings, and query_disclosures is empty or contains history. Discovery accepts a page number from one through ten. The existing `/api/context/resolve` and `/api/app/context/resolve` endpoints retain owner and bearer transports respectively.

| Grant or constraint | Required authority |
|---|---|
| context.resolve | The application may invoke context resolution. |
| github.commits.read | The resolver may retrieve GitHub commit metadata. |
| github.disclose | GitHub evidence may be returned to this application. |
| source_resources | The allowlist identifies selected repository UUIDs accessible to this application. |
| history.query_disclose.github | Authorized history dates may constrain GitHub queries, subject to connection consent. |

Native and history grants remain independent. Retrieval without recipient disclosure performs no external read. Grant replacement uses the existing revision check; omitted source_resources clears external grants. Application bearer credentials cannot manage connections or establish owner sessions.

ContextPolicy centralizes owner, grant, resource, and cross-source decisions. SourceAccess is an internal authority snapshot constructed by ContextPlan, never accepted through JSON. The provider checks it before every outbound request; the resolver rechecks before disclosure. The adapter remains trusted application code, not an isolated security sandbox.

## Cross-Source Disclosure

Connection query_disclosures defaults to an empty list. The owner must explicitly allow history dates to constrain GitHub requests. An application additionally needs history.query_disclose.github, history retrieval and disclosure grants, and GitHub grants. Direct owner requests also require connection consent.

A temporal request identifies its origin, destination, and window radius. The deterministic plan selects the first ranked history fragment with an available timestamp that passes its freshness check. It derives one window with a radius between one and seven days and makes no remote request if permission or the anchor is unavailable.

This consent permits timestamp constraints only. It does not authorize history text, titles, identifiers, or excerpts to be sent to GitHub. Receipt of the final bundle remains a separate disclosure decision, and onward_disclosure remains not_authorized.

## Query Minimization

External adapters receive an internal request containing an empty natural-language query, authorized resources, and bounded UTC dates. GitHub receives only a repository path, since, until, per_page, and page. Source text and history identifiers never become GitHub search terms.

Explicit GitHub date queries require from and to, ordered within a maximum thirty-one-day window and Git's documented timestamp range. Missing or excessive bounds produce bounded_window_required for GitHub while local sources can still complete. OpenMemory cannot infer how an external application obtained caller-supplied dates.

The original temporal experiment uses the following fabricated request through owner or application resolution transport.

```json
{
  "version": "context-request-v1",
  "query": "What was I working on around the time I was discussing OpenMemory portability?",
  "sources": ["native_memory", "history", "github"],
  "temporal": {"from_source": "history", "to_source": "github", "days": 2},
  "limit": 10,
  "per_source_limit": 5
}
```

Suppose the first current history match occurred at 2025-01-02T12:00:00Z. With connection consent and application grants, GitHub receives since=2024-12-31T12:00:00Z and until=2025-01-04T12:00:00Z for authorized selected repositories. It does not receive the portability question. Without cross-source consent, GitHub reports query_disclosure_denied and local evidence remains available.

The result establishes temporal proximity, not authorship or a conclusion about the owner's work. Repository activity can include other contributors, and commit dates are authored metadata. The connected account is not automatically equated with every commit author.

## Provenance

Commit fragments use repository ID plus SHA as resource identity. Content is a redacted commit-message excerpt of at most six hundred characters. Provider-controlled provenance is also redacted before disclosure, retaining private and untrusted_data markers.

| Fragment field | Provider evidence mapping |
|---|---|
| provider | The value identifies GitHub as the authority. |
| connection_id and external_account_id | These identify the connection and verified token-holder account. |
| external_account_login | This preserves the verified account label from connection time. |
| source_resource_id and repository_id | These identify local selection and upstream repository identity. |
| repository and commit_sha | These identify the checked reference and exact commit. |
| author_login and author_id | These report GitHub's author association when available; email is omitted. |
| commit_time | This records the committer timestamp in UTC. |
| retrieved_at | This records retrieval separately from event time. |
| url | This constructs a canonical reference from validated identifiers. |
| capability and retrieval.method | Both identify repository.commits.list as the retrieval operation. |

Provenance establishes origin, not truth, verified signatures, or semantic relevance. Provider-supplied URLs, files, patches, arbitrary metadata, and credentials are omitted. Commits are sorted by committer time with stable identity tie-breaking, then interleaved with local results under the existing budget.

## Freshness

Every resolution requests new upstream evidence, with no persisted content cache. The adapter's isCurrent method checks local ownership, selection, connection revision, and credential deadline. It does not repeat the upstream request or promise that GitHub has not changed since retrieved_at.

Observed disconnection or grant changes suppress returned evidence. A concurrency window remains between the final authorization check and byte delivery. Previously transmitted dates and delivered evidence cannot be recalled.

## Partial Results

ContextBundle v1 gains additive source outcomes. Consumers can display any source's evidence and coverage without calling a provider endpoint. SourceResult adds optional status and searched fields while preserving local adapter defaults.

| Outcome | Interpretation |
|---|---|
| complete | The bounded default-branch retrieval completed successfully. |
| no_matches | The bounded retrieval found no matching commits. |
| source_not_authorized or disclosure_denied | Retrieval or recipient permission prevented access. |
| resource_not_authorized | No selection intersects the caller's repository authority. |
| source_disconnected | No connected provider association is available. |
| query_disclosure_denied | Cross-source date transmission lacks explicit permission. |
| temporal_anchor_unavailable | No current, dated history match is available. |
| bounded_window_required | The external date window violates supported bounds. |
| credential_expired | The configured local credential deadline has passed. |
| credential_invalid | Authentication failed or an earlier rejection disabled the token. |
| repository_access_denied | GitHub returned an ordinary permission denial. |
| repository_inaccessible | GitHub returned 404 or 410; deletion and hidden access are ambiguous. |
| repository_empty | GitHub returned the empty-repository conflict response. |
| repository_moved or repository_identity_changed | A redirect or changed identity requires owner review. |
| rate_limited | GitHub returned a limit response or its cooldown remains active. |
| source_unavailable or provider_timeout | Provider or transport failure prevented retrieval. |
| response_too_large or provider_response_invalid | Response bytes or shape violated constraints. |
| authorization_changed | Resource or connection authority changed during retrieval. |
| search_incomplete | A repository, page, commit, fragment, or payload budget limited coverage. |
| partial_failure | Repository outcomes differ and include failure or incompleteness. |

A 401 can indicate revocation or expiry earlier than the configured deadline; the adapter does not fabricate a distinction. It clears rejected credentials, making later attempts fail locally. GitHub documents multiple [token expiration and revocation causes](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/token-expiration-and-revocation).

Authorized coverage includes per-resource outcomes and safe retry times. One repository's failure preserves other repositories' evidence. Failure on a later page conservatively discards that repository's earlier evidence. Local evidence survives provider failure. The searched flag describes a retrieval attempt, which can stop locally at a credential or cooldown check.

Resolution considers at most three repositories, two ten-commit pages per repository, ten fragments per source, and twenty total fragments. Existing fragment and complete-bundle budgets remain 24,000 bytes and 32 KiB. A next-page indication at the cap marks truncation; its URL is never followed. See [pagination](https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api).

HTTP responses have a streamed 512-KiB ceiling, a two-second connection timeout, a five-second request/read-loop deadline, and a one-second read timeout. An active blocking read can finish after the deadline before control returns. Retrieval and selection stop starting requests after twenty-five seconds; this is an application deadline, not an operating-system scheduling guarantee. No automatic retries occur during resolution.

Rate limits use 429 or recognized 403 responses, Retry-After, and X-RateLimit headers. Only retry_at is persisted, including successful responses exhausting the primary limit. Later requests avoid GitHub until cooldown ends. Concurrent in-flight requests can still reach a limit because no distributed scheduler is implemented. See [rate-limit behavior](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api).

## Disconnection

Disconnect clears the encrypted credential, consent, retry state, and repository rows. It retains connection identity, owner association, external account ID/login, configured expiry, revision, and timestamps as owner-only configuration history. Applications cannot reconnect or restore resource UUIDs.

Deselection deletes repository metadata but can leave inert UUIDs in application grants until edited. Token rejection clears credentials while retaining selection configuration for inspection. Upstream deletion leaves configuration intact, but future retrieval reports inaccessibility without returning cached content.

OpenMemory disconnection does not globally revoke a PAT at GitHub. Revoke it in GitHub settings if other copies must become unusable. Database backups may retain old ciphertext, and recipients may retain delivered context. Native memory remains unaffected.

Access events retain the existing thirty-day scheduled pruning policy. Connection, selection, discovery, and disconnect events contain metadata only. Queries, requested dates, repository names, commit text, tokens, and authorization headers are absent from audit storage.

## Security

Credentials use Laravel's encrypted cast and application key. They are hidden from serialization and absent from native exports, owner inspection, bundles, grants, and intended logging. Host operators, process memory, key holders, backups, browsers, and TLS termination remain trusted. Disable SQL binding capture, HTTP body/header logging, and external telemetry that could retain private data.

Owner routes use session authentication and CSRF. Connection and discovery have separate throttles; resolution retains Phase 4 throttling. No arbitrary API passthrough exists. HTTP destinations are fixed HTTPS operations at api.github.com with redirects disabled.

Commit messages remain data even when they demand policy changes or private history. No evidence enters trusted model instructions because resolution invokes no model. Consumers must preserve this boundary when interpreting bundles. Pattern redaction does not prove semantic safety or recognize every possible secret.

Private import defaults, raw-record containment, local archive parsing, graph filtering, and MCP restrictions remain unchanged. Tests use fabricated history and mocked HTTP without personal archives or model credentials.

## Current Limitations

This phase supports GitHub.com, one connection per owner, and the token's resource-owner restrictions. It implements no OAuth callback, GitHub App setup, renewal, Enterprise Server support, webhook listener, synchronization, source-code retrieval, or commit search. Installation webhooks exist but are unnecessary for this live PAT experiment; see [webhook events](https://docs.github.com/en/webhooks/webhook-events-and-payloads#installation_repositories).

Default-branch commits cannot establish complete work history. Unpushed commits, other branches, inaccessible repositories, missing archives, ambiguous dates, lexical recall limits, and pagination remain gaps. Owners can narrow repository grants to control which repositories participate in the bounded intersection.

Validation uses synthetic transport and in-memory SQLite, not a live private repository or PostgreSQL concurrency run. The [Phase 5 report](GITHUB_IMPLEMENTATION.md) records the architecture evaluation and verification results.
