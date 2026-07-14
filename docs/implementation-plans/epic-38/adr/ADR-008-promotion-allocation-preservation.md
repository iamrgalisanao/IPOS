# ADR-008: Promotion Allocation Preservation

## Status

Accepted

## Date

2026-07-14

## Decision

Promotion allocation is preserved through split and checkout. Checkout consumes immutable split allocation snapshots and must not rerun promotion eligibility for already-split child tickets.

## Context

Promotions can depend on basket composition. After a ticket is split, each child bill may not independently satisfy the original promotion conditions. Recalculating promotions at child checkout would change the financial basis of the split and make reconciliation unstable.

## Alternatives Considered

1. Rerun promotions separately for each child ticket.
2. Drop promotion details at split and only preserve final totals.
3. Preserve promotion allocation snapshots in allocation and checkout records.

## Decision

Preserve promotion allocation snapshots and promotion discount centavos when splitting bills. Child checkout uses the stored allocation snapshot through `DiningCheckoutSnapshot`.

## Consequences

1. Split child sale totals remain deterministic.
2. Promotion reconciliation can trace from parent item to child sale item.
3. Checkout must use a snapshot handoff instead of current promotion eligibility.
4. Promotion policy changes do not alter already-split tickets.

## Related Stories

1. Story 38.6
2. Story 38.7

## Related ADRs

1. ADR-003 Bill Split Model
2. ADR-004 Immutable Pricing and Sales
3. ADR-010 Checkout Boundary
