# Authenticated ownership setup and migration

This implementation checkpoint was prepared on 2026-09-23. It covers local account authentication and explicit legacy ownership binding, not the context resolver or application-grant architecture.

Current checkpoint, 2026-09-25: per-application resolver grants and owner-bound GitHub connections are now implemented. Read the [context guide](LOCAL_CONTEXT.md), [GitHub guide](GITHUB_PROVIDER.md), and [whole-system review](POST_PHASE_5_REVIEW.md) for those additions. Statements below about deferred grants describe the original ownership checkpoint; legacy MCP credentials remain separate.

## Identity boundaries

| Identity | Authority in this implementation |
|---|---|
| OpenMemory user | A Laravel password login establishes the authenticated browser account. A generated owner UUID identifies that account independently of its email. |
| Corpus owner key | A unique database binding maps one account to one existing string namespace used by imports and legacy local records. The key is not a password or authentication token. |
| External provider identity | Internet Identity authenticates direct canister operations. A submitted principal does not authenticate Laravel or bind a corpus. |
| Application identity | The existing MCP deployment key remains a separate trusted application credential. It does not log a browser in or expose imported conversations. Per-client grants remain deferred. |

Every browser route that reads or mutates local user data now requires Laravel authentication. History ownership is resolved from the authenticated account's binding, never from configuration, query parameters, a principal string, or the old session identity. Browser navigation redirects unauthenticated users to login; JSON requests receive HTTP 401. Authenticated requests for another owner's conversation, raw record, or deletion return HTTP 404.

New accounts receive a unique native namespace. A missing ownership binding fails with HTTP 403 rather than falling back to configuration. Existing corpora remain unchanged and inaccessible until an operator explicitly binds them.

## New local installation

Install dependencies, configure the database and application key, and run the migrations using the existing development instructions. From the application directory, create the account:

~~~bash
php artisan migrate
php artisan openmemory:user:create owner@example.test --name="Local owner"
~~~

The command prompts twice for a password without accepting a password argument. Passwords must contain at least twelve characters and at most seventy-two bytes. Laravel stores a password hash, not plaintext. There is no public registration endpoint or default login account.

The command prints the generated import-owner UUID. Pass that exact value to imports:

~~~bash
php artisan memory:import-archive /path/to/export.zip --user=PRINTED_OWNER_UUID
~~~

Open /login, authenticate with the local account, and then open /history. Set OPENMEMORY_LOCAL_USER_ID to the same import-owner key only if a CLI default is convenient. This setting never authenticates a requester or changes a binding.

## Existing corpus migration

Back up the database and configuration using the deployment's established backup procedure. Keep the backup outside the repository. Stop web traffic and background writers during migration and binding, since the binding command is not an online namespace-merging tool.

Run migrations, create the intended local account, and explicitly bind the exact key previously supplied to imports:

~~~bash
php artisan migrate
php artisan openmemory:user:create owner@example.test --name="Local owner"
php artisan openmemory:corpus:bind legacy-owner --email=owner@example.test
~~~

If a Laravel account already exists, skip account creation and use its email. The migration generates UUIDs for existing accounts without assigning any configured or legacy corpus. The operator must verify the intended owner before running the binding command; possession of an email address or principal string proves nothing.

Binding does not rewrite conversations, messages, raw records, imports, or graph identifiers. It also grants access to legacy local graph, document, and agent records using that same owner key. Review the whole namespace before assigning it.

The command rejects a key already assigned to another account, another account's reserved UUID, an agent graph namespace, replacement of an existing legacy binding, and replacement of a native namespace containing persisted records or mock memories. Repeating the same binding succeeds without changing records. One account supports one active namespace in this increment; merging multiple corpora is deliberately unsupported.

Continue importing with the original legacy key after binding. Both the --user option and configured CLI default remain supported. Importing under a different key does not transfer ownership automatically. Local CLI access is trusted operator access to the database, not an untrusted application API.

Do not resume an older unauthenticated application version against the private corpus. Rolling back the new schema cannot make those older routes safe.

## Browser and canister compatibility

Login regenerates the Laravel session identifier and discards legacy chat context. Logout invalidates the session and rotates its CSRF token. Owner changes discard the previous chat-session reference without deleting another owner's transcript. Private responses prohibit HTTP caching.

The global layout provides a separate OpenMemory logout control. Internet Identity sign-out only concerns the provider delegation. Signed canister writes and owner-signed private reads retain their existing canister authorization; they do not verify ownership of a Laravel namespace. Existing principal-based local recall requires an explicit operator binding.

The memory inspector remains a global public-record view. Both mock storage and adapter responses exclude private, sensitive, and unclassified records from that view, including private records belonging to the logged-in user. This increment does not add a new private mock-record listing API. The authenticated graph surface can inspect the owner's existing local projection.

MCP routes retain their existing key validation and history exclusion. They receive no browser session authority. The legacy shared MCP key still carries broad application trust over its existing graph operations and must not be distributed as a scoped user credential.

## Deployment and remaining limits

Use HTTPS and SESSION_SECURE_COOKIE=true for non-loopback deployments, protect APP_KEY and database credentials, disable application debug output, and restrict access to administration and the adapter. Preserve HTTP-only session cookies and CSRF middleware. Do not seed demonstration credentials into a deployed instance.

There is no password-reset UI, MFA, external login federation, per-application grant system, encryption-at-rest guarantee, or protection from a compromised host operator. Email is a local login identifier, not proof of provider-account ownership. Binding should occur with traffic stopped; the command does not coordinate concurrent legacy import or MCP writers.

These checks do not repair the separate model-prompt, model-disclosure, or payload-logging findings in the architecture report. Generated history answers and private-document processing require their own follow-up review. Authentication does not retract content already received or retained by a browser, application, backup, or provider.

## Verification coverage

OwnershipAuthenticationTest exercises unauthenticated denial across history routes, real authenticated owner access, cross-owner reads and deletion, configured-owner isolation, successful forged-principal requests, private mock writes, binding safeguards, local import and re-import, migration of existing accounts, password login, throttling, logout, account deletion, session transitions, CSRF enforcement, and mock/adapter public-only parity.

Existing browser tests use explicit authenticated owner fixtures rather than bypassing middleware. Provider archives remain fabricated through ConversationFixtures, and the ownership tests prohibit stray HTTP requests. Adapter parity uses HTTP fakes, not a deployed canister. The migration is exercised with SQLite; a PostgreSQL upgrade rehearsal remains necessary before deploying against that database.
