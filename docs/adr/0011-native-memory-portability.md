# ADR 0011: Owner-scoped native SQL memory and paged portability

This decision is proposed as of 2026-09-23. Phase 3 validates local behavior without finalizing the wider context architecture.

## Context

Graph pruning, expiring mock records, and external canister identity cannot provide an independently durable local native-memory authority. Imported conversations and extracted facts have different provenance and lifecycle semantics from intentional assertions.

## Proposed decision

Introduce a separate SQL table owned by authenticated Laravel accounts. Use owner-scoped portable UUIDs, explicit correction and supersession, reversible archival, and hard deletion. Keep native records private and unavailable to existing MCP, agent, graph, and model paths.

Support only owner assertions in this phase. Preserve attribution during import without treating exported claims as authenticated authorship. Defer arbitrary metadata and external evidence until their security semantics are defined.

Use openmemory-export-v1 JSON pages with strict validation and atomic insert-or-identical-skip import. Conflicts fail without replacing local data. Preserve unresolved replacement UUIDs for page transfer while rejecting self-links and cycles. Require a quiet write period instead of promising a distributed snapshot.

## Alternatives and consequences

Reusing graph tables would expose canonical statements to pruning and conflate artifacts with assertions. Requiring ICP would tie local ownership to an external runtime and provider identity. Event sourcing would preserve every correction but complicate meaningful deletion before a demonstrated need.

A separate table provides a bounded persistence surface while preserving legacy experiments. Explicit revisions prevent routine lost updates but do not constitute synchronization. Strict version handling rejects unfamiliar extensions instead of silently losing fields.

Owner-only routes postpone application grants without treating retrieval as write or disclosure authority. JSON transfer provides an exit path independent of commercial services. Managed hosting can operate this same storage model and format.

## Validation gate

Tests must verify isolation, CSRF, lifecycle semantics, graph independence, absence of external calls, protected-content rejection, round trips, atomic conflicts, and adversarial imports. The inspection UI must render records as data and require intentional mutations.

The implementation report records measured results. Application grants, source-converted attribution, consistent snapshots, non-SQLite concurrency, and future deletion propagation remain unvalidated.
