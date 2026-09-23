# ADR 0002: Treat context as an evidence response

This decision is proposed as of 2026-09-22.

## Context

A conversation, calendar event, and commit have different storage semantics. Converting them all into memory records would discard useful structure and make existing imports harder to preserve.

## Proposed decision

Introduce typed ContextRequest, ContextFragment, and ContextBundle value objects. A fragment contains bounded evidence, source identity, attribution, timestamps, freshness, and disclosure metadata. A bundle reports source outcomes and coverage as well as selected fragments.

Keep existing conversation tables and introduce native storage only for intentionally saved statements. Do not create a universal Context table or general entity ontology. Relationships in a response describe returned evidence, not an automatically maintained personal knowledge graph.

## Alternatives and consequences

A generic memory table offers superficial uniformity but hides source-specific meaning. Returning arbitrary provider JSON avoids modeling but transfers integration and authorization complexity to every consumer.

A narrow evidence envelope improves integration and portable representation without requiring one database design. It remains serializable for future local or hosted execution. Schema versions and bounded extensions are necessary to prevent incompatible connector-specific interpretations.

## Acceptance evidence

The same envelope must represent native statements, redacted imported excerpts, and commit metadata without invented timestamps or lost provenance. Unknown required fields must fail validation. Raw conversation records must never be reachable through these contracts, and imported excerpts must remain unavailable through third-party transports.
