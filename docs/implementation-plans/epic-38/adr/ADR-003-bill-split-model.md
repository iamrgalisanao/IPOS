# ADR-003: Bill Split Model

## Status

Accepted

## Date

2026-07-14

## Decision

Bill split creates child tickets and an allocation ledger. The parent ticket becomes an orchestration container. Split allocation is atomic and append-only.

## Context

Restaurant split bills must preserve financial history, promotion allocation, rounding adjustments, and item lineage. A partial split write would make settlement and audit unreliable.

## Alternatives Considered

1. Store split groups only as transient checkout UI state.
2. Mutate the parent ticket in place without child tickets.
3. Create child tickets with allocation ledger rows.

## Decision

Create child tickets as independently payable units. Record allocation rows that link source parent items, child ticket items, allocated quantity, allocated amount, promotion discount, and rounding adjustment. The parent remains the historical/orchestration ticket.

## Consequences

1. Child bills can be paid independently.
2. Split math can be audited after checkout.
3. Parent ticket mutation is restricted after split.
4. Checkout consumes the child ticket and allocation snapshot rather than recalculating split state.

## Related Stories

1. Story 38.6
2. Story 38.7

## Related ADRs

1. ADR-004 Immutable Pricing and Sales
2. ADR-008 Promotion Allocation Preservation
3. ADR-009 Parent/Child Ticket Model
