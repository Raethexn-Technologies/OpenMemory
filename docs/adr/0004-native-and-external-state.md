# ADR 0004: Separate canonical native state from source evidence

This decision is proposed as of 2026-09-22.

## Context

Existing graph nodes combine extracted artifacts, entity anchors, generated summaries, and maintenance heuristics. Canister or mock records have separate identifiers and persistence. Neither currently supplies one durable local canonical memory lifecycle.

## Proposed decision

Add a small native memory table for explicitly saved statements. Store authenticated ownership, attribution, content, timestamps, state, and an optional replacement link. Keep old versions until an explicit deletion request removes the governed content. Scheduled graph pruning must not affect this table.

Imported conversations remain sources in their existing schema. External fragments remain transient unless the owner chooses retention. Do not automatically convert graph nodes, imported messages, or federated results into native assertions.

## Alternatives and consequences

Reusing every graph node as canonical memory would couple user saves to destructive maintenance. Replacing the graph and canister immediately would break working experiments. A separate native table introduces another model but establishes a narrow authority that can later supply rebuildable projections.

This choice provides durable ownership, straightforward export, and a provider-independent API. Local and managed deployments use the same storage semantics. Legacy behavior must remain visibly distinct during migration.

## Acceptance evidence

Native statements must survive restarts and export/import without ICP, Cloud, or an LLM. Tests must reject cross-owner replacement links and verify deletion, archival exclusion, and version history. Existing imports and graph behavior must remain compatible until an explicit migration is approved.

## Phase 3 implementation checkpoint

The 2026-09-23 implementation adds owner-scoped native SQL statements without promoting graph or imported records. Correction deliberately replaces current content, while explicit supersession retains the previous statement. The [native-memory guide](../architecture/NATIVE_MEMORY.md) defines lifecycle and portability, and [ADR 0011](0011-native-memory-portability.md) records the narrower implemented decisions. Broader source evidence and application grants remain proposed.
