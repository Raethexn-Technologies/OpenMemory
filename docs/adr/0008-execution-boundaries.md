# ADR 0008: Keep one runtime while exposing execution location

This decision is proposed as of 2026-09-22.

## Context

The existing Laravel deployment supports local and self-hosted operation. A separate device runtime could later protect local credentials and indexes, but would also introduce pairing, replay, synchronization, and revocation problems.

## Proposed decision

Keep the MVP in one Laravel application with trusted bundled adapters. Mark each source's execution location and network disclosure behavior. Keep request and evidence contracts serializable so a later device worker can implement them without exposing database objects or filesystem paths.

Do not introduce a daemon, microservices, arbitrary remote tools, or executable third-party plugins. A future device must verify authority locally rather than treating hosted routing as permission. Credentials remain separate from context metadata and portable exports.

## Alternatives and consequences

A cloud-only resolver excludes sensitive offline workflows. A distributed runtime from the start adds substantial security and operational work before product validation. One runtime provides the simplest testable foundation but does not isolate an in-process connector from application secrets.

Execution metadata improves informed user control and keeps later hosting optional. It does not provide encryption or protect plaintext against the operator of the executing host.

## Acceptance evidence

Native storage and local retrieval must work without network access. External calls must use bounded destinations, timeouts, and response sizes. Documentation must distinguish local processing from a user-managed remote server. Untrusted connector execution requires a separate isolation decision before implementation.
