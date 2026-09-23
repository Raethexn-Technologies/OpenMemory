# Ownership foundation implementation review

This change set was completed for review on 2026-09-23. It implements only authenticated private access and explicit legacy corpus ownership. No commit, hosted pull request, deployment, or live database migration was performed.

## Verification results

| Check | Recorded result |
|---|---|
| The pre-change backend suite established the baseline. | All 412 tests passed with 2,060 assertions. |
| The final backend suite includes the ownership regression tests. | All 440 tests passed with 2,229 assertions. |
| The new ownership suite exercises the security foundation. | All 28 tests passed with 171 assertions. |
| The existing frontend suite checks browser behavior. | All 18 tests passed across two files. |
| The existing root CLI suite checks local commands. | All four tests passed without regressions. |
| The frontend production build checks compilation only. | The build succeeded with a warning about chunks larger than 500 kB. |
| Diff and documentation checks inspect the review artifacts. | Whitespace checks and local documentation links passed. |

The initial regression run exposed four expectations tied to unauthenticated sessions or principal-based logout. Those tests now assert the deliberately changed authentication behavior. New migration coverage caught and fixed an SQLite foreign-key failure when upgrading populated user tables. No known test failures remain.

Verification used the configured SQLite in-memory test database and synthetic provider fixtures. Adapter-backed filtering was exercised through HTTP fakes. Neither PostgreSQL migration behavior nor a deployed ICP canister was verified.

## Security properties enforced

Laravel's web guard authenticates browser access to history, raw records, chat, local graph inspection and mutation, documents, ingestion, memory inspection, and agent-management routes. JSON requests without authentication receive HTTP 401; browser requests redirect to login. Cross-owner history identifiers return HTTP 404, and a missing binding returns HTTP 403.

Configuration and browser principal strings no longer establish authority. A server-side binding selects the local namespace, and stale chat context is discarded when that binding or authenticated account changes. Login regenerates the session identifier, logout invalidates the session, and browser writes retain CSRF protection.

Mock and adapter-backed global inspection return only explicitly public records. Private, sensitive, and unclassified records are excluded independently of adapter behavior. Imported history remains excluded from MCP, public graph retrieval, and chat recall.

The local import path remains unchanged. The operator can import or re-import under an existing owner key and then bind that key to the legitimate authenticated account without rewriting source records.

## Schema and operator action

The single migration is [2026_09_22_000001_add_authenticated_corpus_ownership.php](../../app/database/migrations/2026_09_22_000001_add_authenticated_corpus_ownership.php). It adds a unique owner UUID to users and creates the unique user-to-corpus binding table. Existing accounts receive fresh namespaces rather than automatic ownership of configured corpora.

The [setup guide](OWNERSHIP_SETUP.md) explains account creation, explicit legacy binding, backup precautions, and stopped-traffic migration. There is one active namespace per account; the binding command intentionally does not merge populated namespaces or transfer existing legacy bindings.

## Files added

| File | Responsibility |
|---|---|
| app/app/Console/Commands/CreateOpenMemoryUser.php | The command creates a local login through a hidden password prompt. |
| app/app/Console/Commands/BindCorpusOwner.php | The command binds a legacy namespace with conflict and data-preservation checks. |
| app/app/Http/Controllers/AuthController.php | The controller handles throttled login, logout, and login-page navigation. |
| app/app/Http/Middleware/SetAuthenticatedOwner.php | The middleware establishes authenticated local ownership and private response caching policy. |
| app/app/Models/CorpusOwnerBinding.php | The model records the explicit ownership mapping. |
| app/database/migrations/2026_09_22_000001_add_authenticated_corpus_ownership.php | The migration adds the ownership UUID and binding schema. |
| app/resources/views/auth/login.blade.php | The view provides the local password login form. |
| app/tests/Feature/OwnershipAuthenticationTest.php | The suite adds twenty-eight ownership security tests. |
| docs/architecture/OWNERSHIP_SETUP.md | The guide documents setup, migration, compatibility, and limitations. |
| docs/architecture/OWNERSHIP_IMPLEMENTATION.md | This report records the implementation and verification results. |

## Existing files changed

| Files | Change made |
|---|---|
| app/app/Models/User.php | Account creation reserves a fresh ownership namespace, and corpus lookup requires a binding. |
| app/app/Http/Controllers/ConversationHistoryController.php | History resolves the authenticated user's binding instead of configuration or legacy session identity. |
| app/app/Http/Controllers/ChatController.php | Supplied principals cannot establish ownership, and provider sign-out does not log out the local account. |
| app/app/Services/IcpMemoryService.php | Global inspection filters both storage paths to explicitly public records. |
| app/routes/web.php | Browser data routes require authentication and authenticated owner context. |
| app/config/conversations.php and app/.env.example | Configuration documentation now identifies the owner value as a CLI default only. |
| app/resources/js/Components/AppLayout.vue | The layout exposes a separate OpenMemory logout control. |
| app/resources/js/Pages/Chat/Index.vue and Index.spec.js | The chat UI separates identities and stops sending principal-based ownership claims. |
| app/tests/TestCase.php | The shared helper creates explicit authenticated owner fixtures without disabling middleware. |
| app/tests/Feature/ConversationHistoryControllerTest.php | Existing history tests now use authenticated ownership. |
| app/tests/Feature/ExampleTest.php and ChatMemoryGraphTest.php | Chat tests preserve their original coverage while asserting the revised identity boundary. |
| app/tests/Feature/DocumentControllerTest.php and IngestControllerTest.php | Browser tests authenticate explicitly and expect unauthorized responses without login. |
| app/tests/Feature/GraphControllerTest.php, GraphControllerMaintenanceTest.php, GraphSnapshotTest.php, and ClusterDetectionServiceTest.php | Existing graph endpoint tests use authenticated fixtures. |
| app/tests/Feature/AgentAlignmentTest.php, MultiAgentReinforcementTest.php, SimulateDayTest.php, and ThreeDPageTest.php | Existing simulation and inspection tests use authenticated fixtures. |
| README.md, CONTRIBUTING.md, and SECURITY.md | Documentation describes the new browser boundary and points to migration instructions. |
| DEVLOG.md | A dated entry was appended without rewriting historical entries. |
| docs/adr/README.md and 0006-authorization.md | The working direction and limited implementation checkpoint are recorded without accepting unimplemented decisions. |

The architecture report and other proposed ADR files were already present from the preceding audit. They remain in the working tree and are not new runtime implementation.

## Remaining limitations

The legacy MCP key remains a broadly trusted application credential, not a per-user grant. There is no new access to private imported history, but application-scoped authorization remains future work.

Internet Identity still authenticates the canister independently. No verified external-account-to-Laravel linking protocol was added, and a binding grants local namespace access only.

The implementation does not add password recovery, MFA, encryption at rest, a private mock listing API, or automatic corpus merging. HTTPS, secure cookies, protected configuration, and trusted operator access remain deployment responsibilities. Namespace binding requires stopped writers rather than online coordination.

The separate prompt-instruction, model-disclosure, and payload-logging findings from the architecture audit remain unresolved. Authentication also cannot retract copies already held by a recipient or backup.

The next architectural layer was not started. The Context Resolver, federation, vector search, persistent derived observations, Cloud features, and additional providers remain outside this change set.
