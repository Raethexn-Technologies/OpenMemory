# Proposed architecture decision records

These records accompany the [federated context architecture report](../architecture/FEDERATED_CONTEXT_REPORT.md), prepared on 2026-09-22. The owner adopted these records as the working direction on 2026-09-22. Decisions remain proposed until their implementation and consequences have been validated. None changes the repository's existing privacy boundaries or grants permission to disclose imported history.

| Record | Question addressed |
|---|---|
| [0001](0001-hybrid-source-retention.md) | The retention strategy must balance selective retrieval with preservation. |
| [0002](0002-context-evidence-contract.md) | Context needs a response contract without a universal storage ontology. |
| [0003](0003-provider-capabilities.md) | Connectors need bounded operations and explicit capability declarations. |
| [0004](0004-native-and-external-state.md) | Native state must remain distinct from imported and external evidence. |
| [0005](0005-provenance.md) | Evidence must preserve attribution, location, and temporal meaning. |
| [0006](0006-authorization.md) | Provider authority and application disclosure require separate authorization. |
| [0007](0007-derived-persistence.md) | Persistent inferences require intentional saving and dependency management. |
| [0008](0008-execution-boundaries.md) | Local and remote execution need explicit trust boundaries. |
| [0009](0009-core-cloud-boundary.md) | Core must provide useful independent operation without commercial dependencies. |
| [0010](0010-disclosure-boundary.md) | Retrieval authority must not implicitly authorize external disclosure or publication. |

Acceptance requires evidence that the change strengthens user control, preserves portability, simplifies integration, permits optional managed hosting, and introduces only necessary complexity. Each record identifies its particular tradeoffs and validation gate. New decisions should supersede earlier records explicitly rather than rewriting their historical reasoning.
