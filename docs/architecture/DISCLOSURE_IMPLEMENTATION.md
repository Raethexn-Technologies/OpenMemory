# Security-foundation implementation report

This report records the disclosure-boundary increment on 2026-09-23. The authenticated ownership foundation remains the baseline. Changes are left in the working tree for owner review; no commit, pull request, deployment, or release was created.

## Scope and outcome

This increment adds explicit model-operation permission, separates evidence from system instructions, defaults documents to local private processing, and removes payload-bearing normal diagnostics. Public chat proposals now require the existing approval interaction before storage. It does not add a Context Resolver, new provider, native-memory schema, vector database, derived-observation subsystem, or Cloud dependency.

The [trust-boundary document](DISCLOSURE_BOUNDARY.md) contains the audited source-to-destination map, retention behavior, threat assessment, and configuration instructions. [ADR 0010](../adr/0010-disclosure-boundary.md) remains proposed pending review; the broader authorization ADR is not marked complete.

## Verification results

The backend baseline was rerun before this phase's edits. Frontend and CLI baseline counts are those of the accepted ownership increment and were confirmed during this work before adding their new tests.

| Verification | Before this phase | Final result |
|---|---|---|
| Backend tests | There were 440 passing tests and 2,229 assertions. | All 464 tests pass with 2,322 assertions. |
| Frontend tests | All 18 existing tests passed. | All 19 tests pass across two files. |
| CLI tests | All four existing tests passed. | All five tests pass with synthetic fixtures. |
| Optional-runtime security tests | This focused suite did not exist. | All six tests pass using isolated dependency stubs. |
| Production frontend build | The accepted baseline built successfully. | The build succeeds with the existing ThreeD chunk-size warning. |
| PHP syntax checks | No separate pre-edit syntax count was recorded. | Every changed or newly added PHP file in the working tree passes syntax checking. |
| Node syntax checks | The existing CI checks agent source syntax. | Agent, MCP, adapter, and CLI JavaScript pass syntax checking. |
| Installed Pint checker | No pre-edit style run was recorded. | The five newly added PHP security files pass its targeted check. |
| Git whitespace checks | No pre-edit whitespace result was recorded. | The final diff passes git diff --check. |
| Composer metadata validation | These dependency files were unchanged in the accepted baseline. | composer.json is valid, but composer.lock has an existing stale content hash. |

The Composer warning was confirmed against unchanged tracked composer.json and composer.lock files. This phase does not update dependencies or regenerate the lockfile. The build's existing chunk warning remains outside this security task.

Verification used fake HTTP, synthetic archives, local temporary fixtures, and stubbed optional-runtime dependencies. No real provider, Discord, GitHub account, personal archive, production corpus, or live canister was exercised. The Node adapter tests execute route handlers with Express/canister stubs; they are not a deployed adapter integration test. No live-model safety or prompt-injection immunity claim follows from these results.

The following commands reproduce the main verification runs:

~~~powershell
# Run from app.
php artisan test --compact
npm run test:front
npm run build
php vendor/bin/pint --test app/Exceptions/SafeExceptionHandler.php app/Services/LLM/ModelDisclosure.php app/Services/LLM/EvidenceMessages.php config/disclosure.php tests/Feature/DisclosureBoundaryTest.php
composer validate --no-check-publish

# Run from the repository root.
npm run test:cli
npm run test:security
git diff --check
~~~

## New and updated security tests

Twenty tests in DisclosureBoundaryTest exercise credential-without-permission denial, operation-specific grants, poisoned evidence placement, rejection of forged trusted roles, input budgets, payload-free success/failure logs, debug/console exception safety, local document defaults, denied document opt-in, private/sensitive document isolation, authorized public document processing, private memory extraction, cross-owner novelty isolation, denied chat persistence, separate ingestion publication, malformed model-output logging, redaction before transmission, sensitive document titles, and fail-closed unclassified recall in both storage modes.

Four additional ConversationRetrievalTest cases verify that History Ask requires request consent, cannot override either server gate, and keeps poisoned excerpts and private titles out of system prompts. Existing citation and provider-failure tests remain active. Failed authorized generation is reported conservatively because the provider may have received data.

The new frontend test proves that History Ask starts unchecked and sends generate=false until the user opts in. Existing chat tests now verify that public live writes wait for approval. A new CLI test verifies that supplied API keys and URL credentials do not appear in generated MCP setup output.

Six Node security tests exercise default-deny agent model access, forged roles, sanitized provider errors, independent memory-publication permission, unsigned adapter write rejection, and public-only adapter-backed reads.

Existing ownership, local import, raw-record isolation, MCP containment, and provider-independent archive tests remain in the passing backend suite. Tests that previously expected implicit document extraction, automatic chat publication, private consolidation, or evidence inside system prompts were updated to assert the intentional new behavior rather than bypass the production checks. Synthetic legacy workflow tests explicitly enable their required server grants; the new denial tests revoke those grants.

## Security findings fixed

1. A configured model credential no longer implicitly authorizes every model call. Unknown or omitted operation names fail closed.
2. History Ask no longer interpolates imported excerpts or titles into system instructions. A request cannot override a disabled server generation switch.
3. Chat graph recall, document QA, extraction, consolidation, ingestion, and benchmark judging use separate untrusted evidence envelopes.
4. Private and sensitive document chunks and memory writes no longer trigger model-based metadata or fact extraction.
5. Memorability candidates exclude private records and other owners; returned update targets must be selected candidates. Consolidation excludes private nodes before model use.
6. Chat recall is limited to ten recent messages and bounded selected public records. Nonessential storage metadata is omitted, and the model boundary enforces an input byte budget.
7. Public model classification no longer causes automatic chat publication. Document upload defaults to private, and the existing commit ingestion workflow needs a separate publication switch.
8. Provider bodies, raw model parse failures, document previews, and exception payloads were removed from normal application diagnostics. Debug and console exception rendering are sanitized.
9. The unsigned demonstration adapter refuses private writes and filters unknown/private records on reads. Its behavior no longer suggests owner protection it cannot enforce. Laravel recall also rejects legacy records without an explicit public classification.
10. Optional agent disclosure channels default to disabled, and MCP setup output no longer prints configured API credentials.

## Behavior changes and migrations

No migration or schema change was added in this phase. The ownership migration already present in the working tree belongs to the accepted baseline and was not reapplied. Existing imports and stored data were not rewritten, deleted, uploaded, or reclassified.

Operators must deliberately configure MODEL_DISCLOSURE_OPERATIONS for model-assisted workflows. History generation additionally requires CONVERSATIONS_ASK_GENERATE_ANSWER=true and an explicit owner request. Public document model processing requires allow_model_processing=true alongside its operation grant.

Documents that omit sensitivity now remain private and receive deterministic local metadata. Existing public documents are not automatically made private. All new chat proposals require approval before memory storage; the authenticated mock approval endpoint now accepts public records.

The existing commit importer requires ALLOW_INGEST_PUBLICATION=true in addition to its model-processing permission. Optional agent model, channel, tool, and publication switches are separate and default off. These controls do not add a new provider or broaden the agent's access to Laravel private history.

Unexpected application errors now return generic content even under APP_DEBUG. This intentionally removes detailed production diagnostics. MCP setup templates require a locally filled key placeholder rather than echoing a credential into terminal output.

## External transmission paths retained

Explicitly enabled PHP model operations still send authorized content through OpenRouter to the configured task model. History sends selected redacted excerpts; opted-in public document processing can send all chunks across multiple calls. Provider retention and subsequent deletion are not guaranteed.

Approved browser-signed canister writes still transmit content and metadata to the configured ICP endpoint. Public graph/canister records remain readable through existing public and MCP paths. The shared MCP key remains broad and does not become an owner identity.

The pre-existing commit importer still contacts GitHub when invoked. Its server-wide provider credential and repository allowlist are not a per-owner provider account model. Enabled optional agent tools can contact GitHub and execute interpreters; enabled Discord output is visible to its channel audience.

Metadata logs can reach explicitly configured remote Laravel log channels. Reverse-proxy logs, browser extensions, third-party instrumentation, backups, local database access, and provider-side retention are not controlled by this patch.

## Remaining findings and review gates

The evidence envelope uses a separately typed lower-priority chat message, not a model-enforced security compartment. A model can still follow injected content or reveal evidence to an authorized caller. Provenance and citation validity do not establish truth.

Operation permissions are deployment-wide configuration, not revocable per-owner/application/provider grants. They cannot retract an in-flight request. Destination-aware grants and isolated connectors remain prerequisites for sensitive multi-tenant federation.

The optional agent's tools are not sandboxed. Its existing memory refresh client remains incompatible with owner-only Laravel authentication, and this phase deliberately does not bypass that restriction. Keep this prototype disabled for personal corpora.

No historical logs, previously published records, provider copies, or local backups were deleted. Operators should review retention of artifacts created before this fix. Pattern-based redaction reduces risk but does not identify every secret or sensitive fact.

Review should validate these boundaries before authorizing another architectural phase. The Context Resolver phase has not begun.

## Changed-file inventory for this phase

The worktree also contains the accepted ownership implementation. The following inventory describes only files added or edited during this disclosure phase.

New files contain the small authorization/evidence boundary, exception handling, tests, and documentation:

~~~text
app/app/Exceptions/SafeExceptionHandler.php
app/app/Services/LLM/EvidenceMessages.php
app/app/Services/LLM/ModelDisclosure.php
app/config/disclosure.php
app/tests/Feature/DisclosureBoundaryTest.php
tests/disclosure-boundary.test.js
docs/architecture/DISCLOSURE_BOUNDARY.md
docs/architecture/DISCLOSURE_IMPLEMENTATION.md
docs/adr/0010-disclosure-boundary.md
~~~

Existing backend files connect the boundary to the current workflows:

~~~text
app/.env.example
app/config/conversations.php
app/app/Providers/AppServiceProvider.php
app/app/Console/Commands/IngestGitHub.php
app/app/Http/Controllers/ChatController.php
app/app/Http/Controllers/ConversationHistoryController.php
app/app/Http/Controllers/DocumentController.php
app/app/Http/Controllers/IngestController.php
app/app/Http/Controllers/McpController.php
app/app/Services/LLM/LlmService.php
app/app/Services/LLM/OpenRouterProvider.php
app/app/Services/Conversations/ConversationAskService.php
app/app/Services/Conversations/ConversationImportService.php
app/app/Services/BenchmarkService.php
app/app/Services/ConsolidationService.php
app/app/Services/DocumentIngestionService.php
app/app/Services/EvidenceFactExtractionService.php
app/app/Services/GraphExtractionService.php
app/app/Services/IcpMemoryService.php
app/app/Services/MemorabilityService.php
app/app/Services/MemorySummarizationService.php
app/app/Services/Ingest/GitHubIngestService.php
app/app/Services/Ingest/IngestPipeline.php
app/app/Services/Ingest/IngestSummarizer.php
~~~

Existing frontend and regression files reflect explicit consent and safe diagnostics:

~~~text
app/resources/js/Pages/Chat/Index.vue
app/resources/js/Pages/Chat/Index.spec.js
app/resources/js/Pages/History/Index.vue
app/resources/js/Pages/History/Index.spec.js
app/resources/js/composables/useIcpIdentity.js
app/resources/js/composables/useIcpMemory.js
app/tests/TestCase.php
app/tests/Support/ConversationFixtures.php
app/tests/Feature/ChatMemoryGraphTest.php
app/tests/Feature/ConsolidationServiceTest.php
app/tests/Feature/ConversationRetrievalTest.php
app/tests/Feature/DocumentControllerTest.php
app/tests/Feature/DocumentIngestionTest.php
app/tests/Feature/ExampleTest.php
app/tests/Unit/EvidenceFactExtractionServiceTest.php
app/tests/Unit/GraphExtractionServiceTest.php
app/tests/Unit/LlmServiceTaskRoutingTest.php
~~~

Existing optional-runtime, CLI, and documentation files complete the audited boundary:

~~~text
agent/.env.example
agent/src/index.js
agent/src/core/agent.js
agent/src/core/llm.js
agent/src/core/memory.js
agent/src/core/tools.js
agent/src/connectors/discord.js
icp/adapter/server.js
icp/mcp-server/server.js
icp/mcp-server/identity.js
icp/mcp-server/setup-clients.js
bin/openmemory.js
tests/openmemory-cli.test.js
package.json
README.md
CONTRIBUTING.md
SECURITY.md
DEVLOG.md
docs/adr/README.md
docs/adr/0006-authorization.md
~~~
