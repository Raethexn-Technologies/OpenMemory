# ADR 0001: Use intentional hybrid retention

This decision is proposed as of 2026-09-22.

## Context

Live retrieval avoids unnecessary copies but cannot preserve a record after its provider removes access. Existing conversation imports already provide valuable offline evidence. A federation-only architecture would weaken that capability.

## Proposed decision

Represent origin, retention, and execution as separate source properties. Support reference-only external retrieval first, while retaining existing local imports and durable native records. Introduce expiring caches, indexes, or mirrors only with explicit retention choices and tested deletion behavior.

The resolver must report unavailable sources, freshness, and bounded coverage. It cannot silently replace a failed live query with stale retained content. An explicitly retained archival mirror is distinct from a cache and needs its own access policy.

## Alternatives and consequences

Centralized mirroring simplifies some searches but maximizes collection. Exclusive live federation sacrifices offline use and historical reproducibility. Hybrid retention adds policy metadata while allowing both preservation and minimization.

This choice strengthens ownership through deliberate retention, keeps exports honest about references versus stored content, and permits the same retrieval contract on local or hosted deployments. It does not promise that references constitute a backup.

## Acceptance evidence

Tests must distinguish empty results from unavailable sources, prevent cache fallback after revocation, and demonstrate native retrieval without a network. The first external adapter must leave no persistent response payloads. Indexing remains deferred until repeated queries establish a measurable need.
