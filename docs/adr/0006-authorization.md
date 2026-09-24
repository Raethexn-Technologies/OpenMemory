# ADR 0006: Separate ownership, provider access, and application grants

This decision is proposed as of 2026-09-22.

## Context

The current configured history owner is not an authenticated requester. Provider credentials also authorize more upstream access than an individual consuming application should receive. Federation would amplify both problems without a separate disclosure policy.

## Proposed decision

Authenticate Laravel owners before exposing private corpus routes. Explicitly bind legacy corpus identities through an owner-run migration; never infer ownership from a supplied principal or matching email.

Use revocable, hashed application tokens with database-backed grants for initial integrations. Effective authority intersects owner, application, source, capability, resource constraints, and record restrictions. Check before retrieval and again before disclosure. Treat cross-source query disclosure as a separately approved operation.

Imported history remains confined to authenticated owner inspection. Raw records remain reachable only through the existing owner raw route. This decision does not authorize private-history disclosure through MCP, chat recall, or the public graph.

## Alternatives and consequences

A shared deployment key cannot express application-specific access. Distributed capability tokens add verification and revocation complexity without an immediate deployment need. Database grants are simpler for one server and remain compatible with future OAuth transport authentication.

Explicit grants strengthen user control and integration safety. Export carries inactive permission intent, not reusable authority. Hosted deployments can add identity integrations without changing these checks.

## Acceptance evidence

Tests must exercise unauthenticated access, two authenticated owners, forged principals, denied provider calls, in-flight revocation, and raw-route isolation. Migration must fail closed for unbound corpora. Existing private and sensitive classifications cannot be broadened without a separate owner decision.

## Implementation checkpoint on 2026-09-23

The first increment implements Laravel browser authentication, generated owner UUIDs, explicit corpus bindings, stale-session isolation, and public-only global memory inspection. The [ownership setup guide](../architecture/OWNERSHIP_SETUP.md) documents the migration and its tests.

Application grants, provider authorization, and in-flight revocation remain unimplemented. This ADR therefore remains proposed, and imported-history disclosure boundaries remain unchanged.

## Disclosure checkpoint on 2026-09-23

A separate operation allowlist now controls model disclosure, with request-level choices for History Ask and documents. Public chat proposals require explicit approval rather than relying on model classification. This is transitional deployment configuration, not the proposed per-application grant system. See [ADR 0010](0010-disclosure-boundary.md) and the [trust boundary](../architecture/DISCLOSURE_BOUNDARY.md) for its tested limits.

## Phase 4 application-grant checkpoint

The 2026-09-23 local resolver adds hashed bearer registrations, source-specific retrieval and disclosure capabilities, grant revision checks, and metadata-only access events. This explicitly authorized application transport can receive bounded redacted history excerpts, while raw history remains owner-only and MCP remains excluded. Resource-level restrictions, provider connections, and onward-disclosure grants remain proposed. See [ADR 0012](0012-local-context-and-application-grants.md) for tested limits.
