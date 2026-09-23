# ADR 0007: Keep derived observations transient by default

This decision is proposed as of 2026-09-22.

## Context

Cross-source retrieval can suggest relationships without proving them. Automatically saving these suggestions creates opaque personal claims and makes source deletion difficult to honor.

## Proposed decision

The initial resolver returns evidence without generating or saving conclusions. A later explicit observation-saving operation must retain evidence dependencies, generating method and version, attribution, review status, and an expiry or review policy.

Corrections create replacements without rewriting source artifacts. Conflicting observations can coexist with their temporal meaning and attribution. Missing or revoked dependencies disable disclosure until review or recomputation. Converting an observation into an independent user assertion requires explicit action.

## Alternatives and consequences

Saving every generated conclusion maximizes apparent recall while accumulating uncertain claims. Forbidding all persistence would prevent useful reviewed observations. Explicit saving provides a narrower path that can be tested before automated inference is considered.

This choice gives users control over retained claims and keeps portability auditable. It avoids a dependency engine in the MVP while leaving a clear later schema boundary. Local and hosted deployments must follow the same retention rules.

## Acceptance evidence

Resolution must create no observation rows or hidden content-bearing logs. Before persistence is accepted, tests must cover deletion propagation, disconnection, corrections, expiry, and export/import of dependencies. Confidence values must not be presented as calibrated probabilities without evaluation evidence.
