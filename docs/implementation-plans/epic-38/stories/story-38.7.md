# Story 38.7: Partial Payments and Ticket Split Checkout Integration

## Status

Implemented - Pending Review

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.2.md`
4. `docs/implementation-plans/epic-38/stories/story-38.4.md`
5. `docs/implementation-plans/epic-38/stories/story-38.6.md`
6. `docs/implementation-plans/epic-38/stories/story-38.8.md`

## Objective

Convert dining tickets and split child tickets into existing POS sale and payment flows without bypassing checkout, payment, shift, terminal, inventory, receipt, accounting, or compliance controls.

Story 38.7 is the final Epic 38 integration point. It does not create a second checkout engine. It orchestrates dining-ticket settlement around the existing `SaleCreationService` and `PaymentController::storeSplit`/`PaymentRecordingService` authority boundaries.

## Dependencies

1. Story 38.6 split allocation and child-ticket generation.
2. Story 38.8 online-only dining mutation guard.
3. Existing `SaleCreationService::createFromPayload` idempotent sale creation flow.
4. Existing `PaymentController::storeSplit` and `PaymentRecordingService::recordSplit` tender authority.
5. Existing terminal middleware.
6. Existing timecard and active shift guards.
7. Existing receipt, inventory deduction, accounting outbox, and sale immutability behavior.

## Scope

This story implements checkout integration for:

1. Ordinary dining tickets that were not split.
2. Split child tickets produced by Story 38.6.
3. Parent split tickets as settlement progress containers only.

The parent ticket in a split flow must not be paid directly. It derives settlement state from its child tickets.

## Out of Scope

1. Store credit redemption.
2. Loyalty redemption.
3. Offline dining checkout.
4. Payment gateway integration changes.
5. Refunds and voids after payment.
6. Split reversal or reopening a paid child ticket.
7. Table merge.
8. Kitchen routing.
9. Reservation deposits.
10. Rewriting the existing POS checkout screen.
11. Replacing `SaleCreationService`.
12. Replacing `PaymentController::storeSplit`.

## Locked Decisions

1. `SaleCreationService` remains the sole authority for creating immutable `sales` and `sale_items`.
2. `PaymentController::storeSplit` remains the tender authority for mixed-payment capture.
3. `PaymentRecordingService` remains the place where sale-paid state, inventory deduction dispatch, and accounting outbox behavior are triggered.
4. Dining checkout may orchestrate those services, but it must not duplicate sale posting, payment posting, receipt issuance, inventory deduction, or Z-read/reporting behavior.
5. Dining checkout is online-only and must use the shared `dining.online` guard.
6. Dining checkout requires `checkout_request_uuid`.
7. Ticket-level concurrency uses `expected_ticket_revision`.
8. Checkout mutations must use tenant/branch-scoped locked ticket reads inside database transactions.
9. Same ticket, same `checkout_request_uuid`, same material request returns the original checkout result.
10. Same ticket, same `checkout_request_uuid`, different material request is rejected as idempotency drift.
11. Same `checkout_request_uuid` cannot be used to create sales for two different dining tickets.
12. A split parent ticket cannot be checked out directly.
13. A split child ticket can be checked out independently.
14. A split parent closes only when every payable child ticket is closed.
15. Failed payment does not close a dining ticket.
16. Unknown payment result does not automatically retry sale creation.
17. Promotion allocations copied during split are preserved. Child checkout must not rerun promotion allocation in a way that changes allocated child totals.
18. Financial math for dining settlement uses integer centavos until crossing into existing sale/payment decimal fields.
19. Payment details remain in existing sale payment records. Dining timeline payloads may reference payment completion status, but must not store full tender details.

## Approval Gates

Story 38.7 is approved for implementation only when these three contracts are accepted.

### Gate 1: Checkout Boundary

Dining checkout coordinates ticket settlement only.

It may:

1. Validate the dining ticket is payable.
2. Lock the dining ticket for checkout.
3. Build the sale-creation input from the ticket snapshot.
4. Call `SaleCreationService`.
5. Link `source_sale_id`.
6. Move the ticket to `settling`.
7. Finalize ticket closure after existing payment recording succeeds.

It must not:

1. Create `sales` directly.
2. Create `sale_items` directly.
3. Create payment records directly.
4. Deduct inventory directly.
5. Generate receipt/compliance records directly.
6. Mutate paid sale financial totals.

`SaleCreationService` remains the sale and sale-item creation authority. Existing sale/payment services remain responsible for inventory effects, receipt data, accounting events, and compliance records.

### Gate 2: Split Snapshot Preservation

Child-ticket checkout must preserve the already-allocated split snapshots.

The sale-creation input for a split child ticket must include:

1. Child ticket item identity.
2. Source parent item identity.
3. Allocated quantity.
4. Allocated amount in centavos.
5. Promotion allocation snapshot.
6. Promotion discount centavos.
7. Rounding adjustment centavos.
8. Ticket-level totals snapshot.

It must not:

1. Rerun split allocation.
2. Re-evaluate promotion eligibility for already-split items.
3. Recalculate child allocated totals from current parent state.
4. Drop rounding adjustments.
5. Allow a replay to change the sale payload.

The implementation may extend `SaleCreationService`, but the extension must be explicit and test-covered so split child sales preserve allocated financial history while still producing normal immutable `sales` and `sale_items`.

### Gate 3: Sale Snapshot Ownership

Dining checkout must hand off an immutable checkout snapshot to the sales layer.

Introduce an explicit handoff contract:

```text
DiningCheckoutSnapshot
```

The snapshot should contain:

1. `ticket_id`
2. `child_ticket_id`, when checking out a split child ticket.
3. `parent_ticket_id`, when applicable.
4. `checkout_request_uuid`
5. `ticket_revision`
6. Ticket status at checkout start.
7. Active payable dining ticket item snapshots.
8. Product snapshots required for sale-item construction.
9. Pricing snapshot.
10. Tax snapshot.
11. Promotion allocation snapshot.
12. Split allocation snapshot.
13. Rounding adjustment snapshot.
14. Totals snapshot in integer centavos.
15. Actor, terminal, tenant, and branch identifiers.

Ownership rule:

1. Dining owns preparing the `DiningCheckoutSnapshot`.
2. `SaleCreationService` owns turning that snapshot into immutable sale records.
3. Payment services own tender capture and paid-sale side effects.

This prevents Dining from depending on Sales internals and prevents Sales from reaching back into mutable dining state during checkout.

The snapshot must be deterministic. Rebuilding it for the same ticket revision and same `checkout_request_uuid` must produce the same material sale payload.

## Existing Code Context

The current POS flow has an intentional two-step boundary:

1. `CheckoutController::createSale` calls `SaleCreationService::createFromPayload`.
2. `SaleCreationService::createFromPayload` creates an idempotent sale but does not create payments, deduct inventory, create accounting outbox records, or generate receipts.
3. `PaymentController::storeSplit` calls `PaymentRecordingService::recordSplit`.
4. `PaymentRecordingService::recordSplit` validates active shift, records payments, marks the sale paid, dispatches inventory deduction after commit, and records accounting outbox events.

Story 38.7 must preserve that boundary.

The existing dining schema already includes:

1. `dining_tickets.source_sale_id`
2. `dining_tickets.checkout_request_uuid`
3. `dining_tickets.parent_ticket_id`
4. `dining_tickets.ticket_revision`
5. `dining_ticket_items.promotion_allocation_snapshot`
6. `bill_split_allocations` allocated amounts and promotion allocations

No new checkout authority table should be introduced unless implementation proves that the existing `checkout_requests` table cannot safely express the needed idempotency contract.

## Technical Approach

### Backend Services

Introduce a dining checkout orchestration layer:

```text
App\Services\Dining\DiningTicketCheckoutService
```

Responsibilities:

1. Load the ticket by tenant, branch, and id.
2. Lock the ticket row before mutation.
3. Validate `expected_ticket_revision`.
4. Validate online, terminal, permission, and timecard requirements through route middleware.
5. Reject closed, voided, or already-paid tickets unless the request is a valid idempotent replay.
6. Reject parent tickets that have child tickets.
7. Allow checkout for ordinary unsplit tickets.
8. Allow checkout for split child tickets.
9. Build the sale-creation payload from dining-ticket item snapshots.
10. Call the existing `SaleCreationService` or an approved extension of it.
11. Store `checkout_request_uuid` on the dining ticket.
12. Move the ticket to `settling` after sale creation succeeds.
13. Link the created sale through `source_sale_id`.
14. Leave the ticket in `settling` until payment success.
15. Close the ticket only after existing payment recording succeeds.
16. Derive parent settlement progress from child tickets.

### Sale Creation Integration

The implementation must use one of these approved patterns:

1. Preferred: extend `SaleCreationService` with a `DiningCheckoutSnapshot` input mode that preserves dining item and split allocation snapshots while still creating standard `sales` and `sale_items`.
2. Acceptable: add a small adapter service used only by `SaleCreationService`, where a `DiningCheckoutSnapshot` is converted into the existing validated checkout payload and then passed through the same sale creation code path.

The implementation must not:

1. Create `sales` directly from a dining controller.
2. Create `sale_items` directly from a dining controller.
3. Recalculate child-ticket promotion allocations after split.
4. Create payment records during sale creation.
5. Deduct inventory during sale creation.

Important implementation note:

The current `SaleCreationService::createFromPayload` recalculates promotions from current product snapshots. For split child tickets, this can violate the Epic 38 allocation-preservation rule. The implementation must explicitly address this by preserving split allocation snapshots through the sale item creation path.

### Payment Integration

Existing payment routes remain the tender authority:

```text
POST /pos/sales/{sale_id}/payments/split
```

Story 38.7 may add a post-payment dining finalization hook in the payment layer, but the hook must be small and explicit:

```text
DiningTicketCheckoutService::finalizeSuccessfulPayment(Sale $sale, User $actor)
```

The hook should:

1. Run after `PaymentRecordingService::recordSplit` successfully records payment and marks the sale paid.
2. Find any dining ticket linked by `source_sale_id`.
3. Lock the dining ticket.
4. Confirm the sale is paid.
5. Transition the ticket from `settling` to `closed`.
6. Increment `ticket_revision`.
7. Record audit, revision, and timeline events.
8. If the ticket is a child ticket, recalculate parent settlement progress.
9. Close the parent only when all payable child tickets are closed.

Payment failure handling:

1. Payment validation failure leaves the ticket in `settling` when a sale already exists.
2. Payment validation failure must not close the ticket.
3. The cashier can retry payment against the same linked sale.
4. If sale creation fails before `source_sale_id` is set, the ticket may return to `open` with reason `checkout_failed`.
5. Unknown payment result must surface a status that asks the frontend to refresh sale/payment state instead of creating another sale.

### Transaction Boundary

Dining checkout must be transactionally safe.

Expected sale-creation sequence:

```text
Lock dining ticket
Validate payable state
Validate expected revision
Prepare DiningCheckoutSnapshot
Call SaleCreationService
Sale creation succeeds
Link source_sale_id
Move ticket to settling
Record audit/revision/timeline
Commit
```

If `SaleCreationService` fails:

```text
Rollback
Child ticket remains payable
Parent settlement progress is unchanged
No source_sale_id is linked
No ticket is closed
```

The implementation must not leave a dining ticket in a partially linked state where the ticket references a failed or missing sale.

### Settlement Invariants

Child ticket settlement:

```text
Child ticket may close only after the linked sale/payment flow has completed successfully.
```

Forbidden states:

1. Payment pending -> child ticket closed.
2. Sale creation failed -> child ticket closed.
3. Unknown payment result -> child ticket closed.
4. Idempotency drift -> child ticket closed.

Parent settlement:

```text
Parent settlement progress is derived from child ticket state.
```

The parent must not store mutable paid counters that can drift from children. Any parent settlement payload should be calculated from child tickets and linked paid sales at read/finalization time.

The parent ticket closes only when all payable child tickets are closed.

### Idempotency

Dining checkout uses both dining ticket state and existing checkout request idempotency.

Material checkout fingerprint fields:

1. `tenant_id`
2. `branch_id`
3. `dining_ticket_id`
4. `ticket_revision`
5. `checkout_request_uuid`
6. Payable active item ids
7. Payable active item quantities
8. Payable active item product ids
9. Payable active item allocated centavo amounts
10. Statutory discount payload, if submitted at checkout time
11. Training mode flag

Excluded from fingerprint:

1. Browser timestamps.
2. Request headers.
3. UI-only labels.
4. Client-estimated display totals.

Replay behavior:

1. If the same ticket and same UUID already produced a sale with the same fingerprint, return the existing sale and current ticket settlement state.
2. If the same ticket and same UUID are reused with a different fingerprint, return `409`.
3. If the same UUID is found on another ticket in the same tenant/branch, return `409`.
4. If the sale exists and is unpaid, return the existing sale with `payment_status: pending`.
5. If the sale exists and is paid, return the existing sale with `payment_status: paid` and the closed ticket payload.

Recommended drift response:

```json
{
  "code": "DINING_CHECKOUT_IDEMPOTENCY_DRIFT",
  "message": "This checkout request was already used with different dining ticket contents.",
  "current_ticket_revision": 8
}
```

## Database Migrations

Expected migration requirement:

```text
None, unless implementation discovers that checkout_request linkage cannot be represented safely with existing columns.
```

Existing columns reserved for this story:

1. `dining_tickets.source_sale_id`
2. `dining_tickets.checkout_request_uuid`
3. `dining_tickets.ticket_revision`
4. `dining_tickets.parent_ticket_id`

If a migration becomes necessary, it must be limited to idempotency/linkage metadata and must not introduce a second sale/payment model.

## API Contracts

### Create Dining Sale

```text
POST /pos/dining/tickets/{ticket}/checkout/create-sale
```

Middleware:

1. `auth`
2. `tenant`
3. `branch`
4. `permission:create_sale`
5. `subscription.feature:sales.pos`
6. `terminal`
7. `timecard.clocked_in`
8. `dining.online`

Request:

```json
{
  "checkout_request_uuid": "5ee46483-6c60-4723-a246-d2b35503a932",
  "expected_ticket_revision": 7,
  "is_training_mode": false,
  "statutory_discount": null
}
```

Successful new sale response:

```json
{
  "status": "created",
  "dining_ticket": {
    "id": "ticket-id",
    "status": "settling",
    "ticket_revision": 8,
    "source_sale_id": "sale-id"
  },
  "sale": {
    "id": "sale-id",
    "status": "created",
    "total": "1200.0000"
  },
  "payment_status": "pending"
}
```

Successful replay response:

```json
{
  "status": "duplicate_seen",
  "dining_ticket": {
    "id": "ticket-id",
    "status": "settling",
    "ticket_revision": 8,
    "source_sale_id": "sale-id"
  },
  "sale": {
    "id": "sale-id",
    "status": "created",
    "total": "1200.0000"
  },
  "payment_status": "pending"
}
```

Conflict responses:

1. `409 DINING_CHECKOUT_IDEMPOTENCY_DRIFT`
2. `409 DINING_CHECKOUT_PARENT_NOT_PAYABLE`
3. `409 DINING_CHECKOUT_ALREADY_CLOSED`
4. `409 DINING_CHECKOUT_REVISION_CONFLICT`
5. `409 DINING_CHECKOUT_OFFLINE`

### Record Payment

Use the existing endpoint:

```text
POST /pos/sales/{sale_id}/payments/split
```

Request:

```json
{
  "payments": [
    {
      "payment_method_id": "payment-method-id",
      "amount": "1200.0000",
      "reference_number": null
    }
  ]
}
```

Successful response remains compatible with the existing POS payment response and may include an optional dining block:

```json
{
  "status": "recorded",
  "sale_id": "sale-id",
  "sale_status": "paid",
  "payment_count": 1,
  "amount_paid": "1200.0000",
  "remaining_balance": "0.0000",
  "dining_ticket": {
    "id": "ticket-id",
    "status": "closed",
    "ticket_revision": 9
  },
  "parent_settlement": {
    "parent_ticket_id": "parent-ticket-id",
    "closed_child_count": 2,
    "payable_child_count": 3,
    "paid_centavos": 240000,
    "total_centavos": 360000,
    "status": "partially_paid"
  }
}
```

The dining block is optional for non-dining sales.

### Ticket Show Settlement Fields

`GET /pos/dining/tickets/{ticket}` should expose settlement fields when relevant:

```json
{
  "dining_ticket": {
    "id": "parent-ticket-id",
    "status": "settling",
    "settlement": {
      "payable_child_count": 3,
      "closed_child_count": 2,
      "paid_centavos": 240000,
      "total_centavos": 360000,
      "remaining_centavos": 120000,
      "status": "partially_paid"
    }
  }
}
```

## UI Notes

The frontend should keep the existing POS payment interaction model and add dining-aware entry points:

1. Ordinary dining ticket detail shows `Checkout`.
2. Split parent ticket shows settlement progress and child bills, not a direct payment button.
3. Split child ticket shows `Pay bill`.
4. Checkout creates or resumes the linked sale.
5. Payment collection uses the existing split-payment UI.
6. After payment success, the UI refreshes the ticket and floor map state.
7. If the app is offline, checkout and payment actions are disabled with the Story 38.8 online-only message.
8. If `409 DINING_CHECKOUT_REVISION_CONFLICT` occurs, refresh the ticket before retry.
9. If payment result is unknown, show a refresh/retry-status state, not a second sale-creation attempt.

## Audit, Revision, and Timeline Requirements

Story 38.7 must record:

1. Checkout started / sale linked.
2. Checkout idempotent replay, if useful for support diagnostics.
3. Checkout failed before sale creation, when relevant.
4. Payment completed / ticket closed.
5. Parent settlement completed, when the last child closes.

Audit payloads must be compact and must not store full payment tender details. Payment details remain in `sale_payments`.

Every ticket status mutation must:

1. Increment `ticket_revision`.
2. Create a dining ticket version.
3. Create a dining timeline event.
4. Preserve tenant, branch, actor, terminal, and source sale references where available.

## Authorization and Guard Requirements

All new checkout endpoints must enforce:

1. Authenticated user.
2. Tenant context.
3. Branch context.
4. `permission:create_sale`.
5. Active POS entitlement.
6. Terminal context.
7. Clocked-in timecard.
8. Online-only dining state.
9. Tenant/branch scoped route binding or explicit scoped lookup.

Payment recording must continue to enforce active shift through `PaymentRecordingService`.

## Test Cases

### Backend Feature Tests

1. Cashier creates a sale from an ordinary dining ticket.
2. Cashier creates a sale from a split child ticket.
3. Split parent direct checkout is rejected with `409`.
4. Checkout requires online state.
5. Checkout requires terminal context.
6. Checkout requires clocked-in timecard.
7. Checkout requires `permission:create_sale`.
8. Checkout rejects stale `expected_ticket_revision`.
9. Checkout locks the ticket and prevents concurrent double sale creation.
10. Same ticket, same UUID, same request returns the same sale.
11. Same ticket, same UUID, different request returns idempotency drift.
12. Same UUID cannot be reused across two dining tickets.
13. Created sale links to the dining ticket through `source_sale_id`.
14. Sale creation moves ticket to `settling`.
15. Failed sale creation does not link `source_sale_id`.
16. Failed payment does not close the ticket.
17. Successful `storeSplit` payment closes the ticket.
18. Successful child payment updates parent settlement progress.
19. Last payable child payment closes the parent ticket.
20. Inventory deduction is dispatched once through the existing payment flow.
21. Payment retry against the same linked sale does not create another sale.
22. Unknown payment status returns a refreshable pending state and does not retry sale creation.
23. Split child sale preserves allocated promotion/discount totals.
24. Cross-tenant and cross-branch ticket checkout attempts return hidden/not-found responses.

### Frontend Tests

1. Ordinary ticket checkout opens existing payment flow.
2. Split parent renders settlement progress and no direct payment command.
3. Split child renders payment command.
4. Offline checkout action is disabled.
5. Revision conflict refresh prompt appears.
6. Unknown payment result shows refresh/status recovery UI.
7. Payment success refreshes floor map/table status.

## Rollout Plan

1. Implement backend orchestration behind the existing POS/dining route group.
2. Add focused backend feature tests before wiring frontend payment entry points.
3. Add frontend entry points for ordinary tickets and split child tickets.
4. Verify the existing non-dining POS checkout still passes.
5. Pilot with one branch and one terminal profile.
6. Validate a manual UAT script:
   1. Open table.
   2. Add items.
   3. Split by seat or item quantity.
   4. Pay one child bill.
   5. Confirm parent remains partially settled.
   6. Pay remaining child bills.
   7. Confirm parent closes.
   8. Confirm receipt, inventory, and sales reports behave like normal POS sales.

## Rollback Considerations

1. New dining checkout routes can be disabled without affecting ordinary POS checkout.
2. Existing sales created through the dining path remain immutable POS sales.
3. Tickets already linked to sales must not be deleted or relinked during rollback.
4. If frontend dining checkout is disabled, staff can continue using ordinary POS checkout for non-dining sales.
5. Any failed or unknown dining checkout should remain support-recoverable through `checkout_request_uuid`, `source_sale_id`, and ticket timeline.

## Implementation Slices

1. Backend checkout request validation, route, controller, and `DiningTicketCheckoutService`.
2. Sale creation adapter/extension that preserves dining snapshots and split allocations.
3. Payment finalization hook and parent settlement derivation.
4. Ticket show payload settlement fields.
5. Backend feature tests for idempotency, concurrency, payment success/failure, and split allocation preservation.
6. Frontend dining checkout entry points and settlement progress UI.
7. Frontend tests and manual UAT notes.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass.
4. Checkout idempotency is verified.
5. Existing sale/payment authority is not bypassed.
6. Split allocation totals are preserved.
7. Inventory deduction still happens only through the existing payment flow.
8. Ticket closes only after payment success.
9. Parent settlement progress is accurate.
10. No architecture constraints are violated.
11. Code review is approved.
12. Relevant documentation or story notes are updated.
