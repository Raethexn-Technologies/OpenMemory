# ADR 0010: Separate retrieval from disclosure

This decision is proposed as of 2026-09-23. The security-foundation implementation provides initial evidence, while owner review and future destination-aware grants remain outstanding.

## Context

Authenticated access establishes who can inspect stored context. It does not establish permission to transmit that context to a model, publish it through public memory endpoints, or retain it in diagnostics. Earlier paths combined these decisions and placed retrieved evidence inside system prompts.

## Proposed decision

Keep the authenticated ownership boundary and add a separate default-deny operation allowlist at the existing model invocation layer. Require explicit owner choices for History Ask generation and public document model processing. Reuse the memory approval flow for public chat proposals instead of trusting a model's public classification.

Keep static application policy, user intent, and serialized untrusted evidence separate. Reject supplemental trusted-role messages. Apply redaction and a bounded input budget before provider invocation. Log operational metadata rather than payloads, and suppress payload-bearing exception rendering.

Use these controls as a transitional deployment policy, not a complete application authorization system. Retain ADR 0006's proposed direction for scoped, revocable application grants.

## Alternatives and consequences

Disabling all model-assisted functionality would remove disclosure paths but prevent testing the existing product under explicit authorization. Treating public sensitivity as model consent would preserve the implicit coupling this phase must remove. A full policy engine would add migrations and integration protocols before their consumers exist.

Local imports and private document ingestion remain useful without a provider credential or Cloud dependency. Existing model workflows require deliberate configuration, and public chat proposals require an additional approval action. Generic error rendering sacrifices detailed production stack traces to reduce sensitive-data retention.

## Validation and limits

Synthetic tests must prove denial before transmission, invariant system instructions under adversarial evidence, private document isolation, metadata-only logging, and preservation of owner/MCP isolation. Optional-runtime tests must use stubbed providers without real credentials or personal archives.

The [trust-boundary document](../architecture/DISCLOSURE_BOUNDARY.md) records data flows, transmission destinations, configuration, retention, and limitations. The [implementation report](../architecture/DISCLOSURE_IMPLEMENTATION.md) records verification results. Passing placement tests does not prove prompt-injection immunity or provider-side deletion.

