# ADR 0012: Local context resolution with separate application disclosure grants

This decision is proposed as of 2026-09-23. Local implementation tests validate the narrow behavior without establishing a federation protocol or a complete policy engine.

## Context

Native memory and imported history now have authenticated ownership and distinct storage semantics. A common retrieval interface must not conflate readable source data with permission to disclose it to a consuming application.

Shared MCP authority cannot express this boundary, and adding private history to that transport would weaken accepted guarantees. A local proof also does not require external connectors, OAuth infrastructure, vector retrieval, or model-driven planning.

## Proposed decision

Implement transient, versioned ContextRequest, ContextFragment, and ContextBundle contracts with deterministic selection across two local sources. Require explicit source requests, bounded evidence, original provenance, and honest per-source coverage and failure outcomes.

Keep owner-session and application-bearer routes separate. Application registrations hold hashed, expiring, revocable credentials and explicit source retrieval and disclosure capabilities. Grant revisions invalidate authority captured before a change. No application capability permits native writes, raw-history access, or grant management.

Check retrieval before source invocation and disclosure both before unnecessary reads and after retrieval. Revalidate local resource identity and lifecycle before returning evidence. Persist only access metadata, with a scheduled retention command, and fail closed if that audit write fails.

Authorize delivery only to the verified recipient. Do not add onward model/provider disclosure grants in this phase or claim control over plaintext after an application receives it.

## Alternatives and consequences

Reusing owner sessions for applications would transfer excessive authority. A shared application key would prevent individual revocation and source grants. OAuth authorization-server infrastructure would add consent-flow and deployment complexity before proving local context behavior.

One permission per source would hide the retrieval/disclosure distinction. Separate capabilities make that distinction inspectable, though source-wide grants still permit accumulation through repeated authorized requests. Per-request budgets and throttling are not substitutes for narrower future policies.

Source-local ranking and deterministic interleaving avoid inventing cross-source confidence. Candidate caps bound local processing but cannot make SQL substring searches index-efficient or establish lifetime coverage.

In-process source adapters remain trusted implementation code, not a security sandbox. External source connections, per-resource grants, forwarding destinations, audit export, and consistent snapshots require later decisions.

## Validation gate

Tests must cover two owners, bearer/session separation, default-deny grants, separate source authority, late revocation and disclosure denial, resource changes, partial failure, provenance, limits, redaction, payload-free auditing, CSRF, and unchanged MCP exclusion.

The [local context guide](../architecture/LOCAL_CONTEXT.md) defines the implemented contracts and threat model. The [implementation report](../architecture/LOCAL_CONTEXT_IMPLEMENTATION.md) records test results and outstanding limitations.
