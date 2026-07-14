# Epic 38 Architecture Decision Records

## Status

Complete

## Purpose

These ADRs are the close-out record for Epic 38 architectural decisions. They do not introduce new design decisions. They summarize the rationale already established by:

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/`

## Document Relationship

```text
Architecture Lock
        |
        v
Defines permanent architectural constraints

Implementation Guide
        |
        v
Defines implementation sequencing

Story Specifications
        |
        v
Define implementation contracts

ADRs
        |
        v
Explain why major architectural decisions were made
```

## Index

1. [ADR-001 Aggregate Ownership](ADR-001-aggregate-ownership.md)
2. [ADR-002 Online-Only Dining Policy](ADR-002-online-only-dining-policy.md)
3. [ADR-003 Bill Split Model](ADR-003-bill-split-model.md)
4. [ADR-004 Immutable Pricing and Sales](ADR-004-immutable-pricing-and-sales.md)
5. [ADR-005 Ticket Revision Model](ADR-005-ticket-revision-model.md)
6. [ADR-006 Timeline vs Audit](ADR-006-timeline-vs-audit.md)
7. [ADR-007 Read Model and Status Resolution](ADR-007-read-model-and-status-resolution.md)
8. [ADR-008 Promotion Allocation Preservation](ADR-008-promotion-allocation-preservation.md)
9. [ADR-009 Parent/Child Ticket Model](ADR-009-parent-child-ticket-model.md)
10. [ADR-010 Checkout Boundary](ADR-010-checkout-boundary.md)

## Governance

Future stories may reference these ADRs for rationale. They should not edit these ADRs to change architecture. Any material architectural change should revise the Architecture Lock first, then add a new ADR explaining the change.
