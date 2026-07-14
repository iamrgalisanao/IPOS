# ADR-004: Immutable Pricing and Sales

## Status

Accepted

## Date

2026-07-14

## Decision

Dining ticket pricing is snapshotted when items are added. Sale records remain immutable after creation. Checkout must not rewrite historical dining or sale pricing.

## Context

POS systems require stable financial records for receipts, reports, tax handling, discounts, and audit review. Catalog changes after an item is added must not change an existing ticket. Split allocation must also preserve the financial basis used at split time.

## Alternatives Considered

1. Recalculate ticket pricing from current catalog prices at checkout.
2. Recalculate split child pricing after parent changes.
3. Snapshot pricing and preserve immutable financial records.

## Decision

Snapshot item pricing on dining item creation. Preserve split allocation snapshots. Create immutable sale and sale item records through `SaleCreationService`.

## Consequences

1. Catalog updates do not alter active or historical dining tickets.
2. Split child checkout remains deterministic.
3. Financial reconciliation uses stored snapshots rather than changing product state.
4. Corrections must use explicit operational flows, not direct mutation of immutable records.

## Related Stories

1. Story 38.4
2. Story 38.6
3. Story 38.7

## Related ADRs

1. ADR-003 Bill Split Model
2. ADR-008 Promotion Allocation Preservation
3. ADR-010 Checkout Boundary
