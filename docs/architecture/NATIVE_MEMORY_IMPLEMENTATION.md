# Native-memory implementation report

This report records Phase 3 verification on 2026-09-23. The owner committed the initial implementation while work was paused; subsequent corrections, tests, and documentation remain uncommitted for review.

## Delivered behavior

Authenticated owners can create, inspect, search, correct, supersede, archive, restore, delete, export, and import native assertions through the local SQL store. Native records do not depend on ICP, models, external providers, graph retention, or OpenMemory Cloud.

The [native-memory guide](NATIVE_MEMORY.md) contains the original memory-system audit, field rationale, lifecycle rules, endpoint reference, export example, and import semantics. [ADR 0011](../adr/0011-native-memory-portability.md) remains proposed, with local implementation evidence recorded separately from future application and evidence-grant decisions.

## Verification

The before column records checks run before Phase 3 implementation. The resumed committed baseline additionally passed 512 backend tests with 2,573 assertions before the final portability corrections.

| Check | Before Phase 3 | Completed Phase 3 |
|---|---|---|
| Backend suite | All 464 tests passed with 2,322 assertions. | All 514 tests passed with 2,588 assertions. |
| Native-memory backend tests | No dedicated native-memory suite existed. | All 50 new tests passed with 266 assertions. |
| Frontend suite | All 19 tests passed across two files. | All 29 tests passed across three files. |
| CLI suite | All five tests passed without external calls. | All five tests continue to pass. |
| Node security suite | All six tests passed using isolated dependencies. | All six tests continue to pass. |
| Frontend production build | The prior accepted phase recorded a successful build. | The build passes with the existing large ThreeD chunk warning. |
| PHP syntax checks | Prior phase syntax checks were already passing. | All ten PHP files touched by this phase pass syntax checks. |
| Targeted Pint checks | The new files did not yet exist. | All seven new native-memory PHP files pass formatting checks. |
| Git whitespace checks | The accepted worktree had no whitespace failures. | The completed diff passes whitespace checks. |
| Composer metadata validation | The accepted phase recorded stale lock-file metadata. | composer.json remains valid, but the existing lock-file mismatch remains unresolved. |

No backend, frontend, CLI, or Node security regressions remain. Composer validation is still a failing repository check; no dependency update was attempted. Frontend bundle splitting remains outside this phase.

Two portability issues were corrected during completion. Duplicate comparison now uses exact typed field equality, preventing different numeric-looking strings from being skipped as equal. Export pages also stop at an eight-MiB encoded-record budget so JSON escaping cannot make a normal generated page exceed the ten-MiB import limit.

## New test coverage

The native backend suite exercises every endpoint's unauthenticated denial, cross-owner reads and mutations, forged owner fields, MCP-key rejection, CSRF, revision conflicts, correction, archival, restoration, supersession, and hard deletion.

Portability tests cover all lifecycle states, timestamp and whitespace preservation, import into an empty store, owner-scoped duplicate identities, strict duplicate comparison, atomic conflicts, unsupported versions, malformed JSON, malformed records, duplicate IDs, replacement cycles, unresolved cross-page links, and escaped-content page sizes.

Additional tests cover graph pruning, cache loss, unavailable ICP, absent model grants and credentials, no external HTTP transmission, protected-content rejection, payload-free normal logging, and native exclusion from MCP results. Existing ownership and disclosure suites continue covering imported-history exclusion.

The ten new frontend tests verify text-only adversarial rendering, intentional creation, inspected revisions, correction, supersession, confirmation before deletion, archive controls, safe error reporting, bounded file import, pagination, POST search, and export continuation.

Tests use only fabricated statements and in-memory SQLite databases. No real archives were opened, no production database migration was run, and no canister or external model was contacted. Browser interaction is covered through component tests rather than a manual end-to-end session.

## Files affected during Phase 3

The following inventory includes the initial implementation already committed by the owner and the finishing changes left in the working tree.

### Schema and backend implementation

- app/database/migrations/2026_09_23_000001_create_native_memories_table.php creates the independent canonical table and indexes.
- app/app/Models/NativeMemory.php defines the portable record whitelist.
- app/app/Services/NativeMemory/MemoryInput.php implements strict validation and local protected-content checks.
- app/app/Services/NativeMemory/NativeMemoryService.php implements owner-scoped lifecycle, retrieval, conflicts, and portability.
- app/app/Http/Controllers/NativeMemoryController.php supplies authenticated API and inspection entry points.
- app/app/Http/Middleware/NativeMemoryRequest.php bounds request size, requires JSON writes, and prevents sensitive form redirects.
- app/routes/web.php registers native owner routes without adding MCP access.
- app/app/Services/RedactionService.php permits explicitly enforced local inspection when general redaction is disabled.
- app/app/Exceptions/SafeExceptionHandler.php excludes native import fields from validation-session flash data.

### Interface and tests

- app/resources/js/Pages/NativeMemory/Index.vue implements the minimal owner control and transfer page.
- app/resources/js/Pages/NativeMemory/Index.spec.js supplies ten frontend regression tests.
- app/resources/js/Components/AppLayout.vue separates native navigation from legacy memory and identifies local SQL mode.
- app/resources/js/Pages/Memory/Index.vue labels the legacy inspector and its public-only mock results accurately.
- app/tests/Feature/NativeMemoryTest.php supplies fifty backend regression cases.

### Documentation and repository safeguards

- docs/schemas/native-memory-v1.schema.json defines the inspectable export structure.
- docs/architecture/NATIVE_MEMORY.md documents the audit, API, lifecycle, transfer contract, and limitations.
- docs/architecture/NATIVE_MEMORY_IMPLEMENTATION.md records the completed verification and handoff.
- docs/adr/0011-native-memory-portability.md records the proposed implemented direction and outstanding validation.
- docs/adr/0004-native-and-external-state.md adds a dated Phase 3 checkpoint.
- docs/adr/README.md indexes the additional proposed decision.
- README.md links the implemented native functionality and its documentation.
- CONTRIBUTING.md separates native persistence from legacy sensitivity classifications.
- SECURITY.md explains native privacy and the sensitivity of portable files.
- DEVLOG.md appends the dated implementation record without rewriting earlier entries.
- .gitignore excludes standard native export filenames containing private content.

## Migration and compatibility

One additive migration creates native_memories, its owner foreign key, and owner-scoped UUID uniqueness. Existing graph, conversation, canister, cache, and agent records are neither migrated nor promoted.

Owner-session authentication remains required, and writes retain CSRF protection. Native records use the authenticated account rather than a configured corpus-owner string. Existing APIs remain available; the new endpoints are under /api/native-memories, with the browser page at /native-memory.

The export format is openmemory-export-v1 JSON. It preserves portable UUIDs, accepted content, assertion attribution, lifecycle, replacement links, revision, and UTC timestamps. external_references must be empty in this version. The guide includes a complete fabricated export and the endpoint table.

Correction intentionally replaces content without revision history. Supersession retains prior statements, archival is reversible, and deletion removes the live row while clearing only that owner's incoming replacement links. Import refuses silent overwrite, while explicit import can restore an earlier deleted UUID.

## Security properties and limitations

Ownership isolation, default-deny disclosure, imported-history privacy, local-only archive processing, and MCP exclusion remain intact. Native text never becomes a system instruction or automatically enters graph, model, provider, or MCP paths.

Credentials and application configuration are absent from the export whitelist. Local pattern checks reject recognizable secrets and configured redaction-floor content, but cannot promise detection of every arbitrary secret. Exports remain unencrypted personal files that need appropriate handling.

Application read/write/delete grants, source-converted memory, derived observations, external evidence references, automatic extraction, vector retrieval, federation, and the Context Resolver remain unimplemented. No commercial dependency or artificial paid-feature limitation was introduced.

Paged export requires a quiet write period and is not a cross-request snapshot. SQLite is the tested persistence engine; production concurrency and collation on other databases remain unverified. Deletion does not erase backups, database journals, browser state, or previously exported files.

Outstanding architectural questions concern reviewed source provenance, distinct application grants, consistent large-store transfer, and deletion propagation into future projections. This phase stops for owner review before any resolver work.
