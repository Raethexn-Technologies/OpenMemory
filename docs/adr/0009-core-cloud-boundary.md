# ADR 0009: Keep independently useful context infrastructure in Core

This decision is proposed as of 2026-09-22.

## Context

The ownership promise depends on continued access when a hosted service disappears. Artificial export restrictions or mandatory hosted identity would undermine that promise even if some application code remained open source.

## Proposed decision

Core includes durable native memory, local imports, the resolver, provenance, permission primitives, standard connector contracts, export/import, and a self-hosted reference API. Safe access receipts and basic credential management cannot depend on a commercial entitlement.

Commercial services may operate these capabilities through managed hosting, backups, synchronization, integrations, organizational administration, and support. Users may supply their own provider credentials or registered applications where supported. Hosted conveniences must not become format or identity dependencies.

The current source license remains unchanged. Provider eligibility and terms remain separate constraints that open-source availability cannot remove.

## Alternatives and consequences

A proprietary control plane simplifies centralized product administration but prevents independent operation. Keeping every operational service in this repository would expand scope before a business need exists. A shared Core with optional operational services preserves both paths.

This boundary directly supports ownership and portability while retaining commercial room in operations. It requires discipline when adding administrative features so fundamental authorization and export remain independently usable.

## Acceptance evidence

A clean self-hosted instance must create, retrieve, export, and restore native memory without a Cloud account, model key, or ICP dependency. Connector contracts and export specifications must be available with Core. No paid-only flag may control access to an owner's existing data.
