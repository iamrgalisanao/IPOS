# ADR-010: Checkout Boundary

## Status

Accepted

## Date

2026-07-14

## Decision

Dining checkout prepares immutable checkout data and coordinates ticket settlement. `SaleCreationService` remains the sole sales authority. Existing payment services remain tender authority.

## Context

Dining tickets are mutable operational state. Sales, receipts, tax/fiscal records, inventory effects, and payments already have established POS ownership. Recreating those responsibilities in dining checkout would create duplicate logic and compliance risk.

## Alternatives Considered

1. Let dining checkout create sales and payments directly.
2. Convert dining tickets into generic cart payloads and ignore split snapshots.
3. Use an immutable `DiningCheckoutSnapshot` handoff to `SaleCreationService`.

## Decision

Dining checkout validates payable ticket state, creates a deterministic `DiningCheckoutSnapshot`, and calls `SaleCreationService`. Payment remains owned by `PaymentController` and `PaymentRecordingService`. Dining closes tickets only after the linked sale/payment flow succeeds.

## Consequences

1. Dining does not become a second sales engine.
2. Sales, inventory, receipts, taxation, accounting, and payment behavior stay centralized.
3. Idempotency and drift detection are required at checkout.
4. Payment failure or unknown payment result must not close the dining ticket.

## Related Stories

1. Story 38.7

## Related ADRs

1. ADR-004 Immutable Pricing and Sales
2. ADR-008 Promotion Allocation Preservation
3. ADR-009 Parent/Child Ticket Model
