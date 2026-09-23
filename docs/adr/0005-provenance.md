# ADR 0005: Preserve source-specific evidence provenance

This decision is proposed as of 2026-09-22.

## Context

Imported conversations already preserve useful source structure. Other memory paths use less consistent source strings and metadata. A resolver needs common provenance without implying that a citation proves a claim true.

## Proposed decision

Require source connection, resource kind, resource identifier, retrieval method, adapter version, retrieval time, and attribution on every fragment. Preserve source versions and locators when available. Keep event, creation, modification, and observation times distinct, including unknown values and limited precision.

An imported locator identifies the redacted projection, not an offset into raw source. A generated statement identifies its generating actor and supporting references. Store evidence references only when intentional persistence requires them.

Use the entity, activity, and agent distinctions from W3C PROV without adopting RDF storage. The [architecture report](../architecture/FEDERATED_CONTEXT_REPORT.md#15-provenance-and-portability-model) defines the draft export boundary.

## Alternatives and consequences

Free-form source labels are insufficient for corrections and deletion. A complete provenance ontology introduces unnecessary implementation obligations. A typed envelope provides useful interoperability while keeping source adapters responsible for accurate mappings.

References improve inspectability and portability but may become unavailable. Hashes detect changes to retained representations; they neither reconstruct lost evidence nor authenticate authorship by themselves.

## Acceptance evidence

Tests must preserve attribution and timestamp semantics across all three MVP sources and native export/import. Deduplication must retain distinct provenance paths. Deleted or disconnected evidence must produce an explicit availability state rather than a fabricated citation.
