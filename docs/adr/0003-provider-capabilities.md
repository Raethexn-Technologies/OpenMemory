# ADR 0003: Declare bounded provider capabilities

This decision is proposed as of 2026-09-22.

## Context

Archive parsers, remote APIs, and model providers solve different problems. Provider search capabilities also differ in filters, ordering, pagination, and evidence returned. A generic natural-language invocation would conceal those differences.

## Proposed decision

Keep archive and LLM contracts separate from a new context source contract. Trusted adapters declare supported operations and constraints through static capability metadata. Search accepts a server-created AuthorizedSourceQuery and returns validated evidence plus coverage information.

Start with native search, owner-only conversation search, and selected-repository commit listing. Add reference fetching only when a source requires it. The Core supplies owner and connection identity, checks grants before each call, validates returned selectors, and enforces output limits independently.

## Alternatives and consequences

Vendor conditionals inside the resolver obstruct replacement. An arbitrary tool registry makes the permitted operation surface difficult to audit. Precise capabilities require explicit mappings but make failures and unsupported queries visible.

This boundary improves integration while preserving provider choice and local operation. Hosted connectors can implement the same contract without becoming mandatory. Capability declarations do not sandbox code or prove upstream authority.

## Acceptance evidence

Denied adapters must receive zero calls. Unsupported constraints must never silently widen retrieval. Contract tests must cover malformed results, pagination limits, timeouts, and source failures. Only trusted bundled adapters execute in process; third-party plugin execution is deferred.
