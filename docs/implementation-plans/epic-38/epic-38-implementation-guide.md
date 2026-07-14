# Epic 38 F&B Table and Bill Operations Story Implementation Guide

## 1. Status

Approved for Story Implementation

Date: 2026-07-14

This guide contains the story execution slices for Epic 38. It must follow the architectural contract in:

```text
docs/implementation-plans/epic-38/epic-38-architecture-lock.md
```

If this guide conflicts with the architecture lock, the architecture lock wins.

## 2. Implementation Order

Recommended order:

1. Story 38.1
2. Story 38.2
3. Story 38.5
4. Story 38.3
5. Story 38.4
6. Story 38.6
7. Story 38.8
8. Story 38.7

Reason:

1. Layout and ticket foundations must exist before UI state.
2. Audit should be introduced before complex mutations multiply.
3. Split allocation should be implemented before payment integration.
4. Offline enforcement should be explicit before checkout paths are finalized.

## 3. Story Status

| Story | Status | Owner | Sprint |
| --- | --- | --- | --- |
| 38.1 | Done | - | - |
| 38.2 | Done | - | - |
| 38.5 | Implemented - Pending Review | - | - |
| 38.3 | Blocked by 38.1, 38.2, 38.5 | - | - |
| 38.4 | Blocked by 38.2, 38.5 | - | - |
| 38.6 | Blocked by 38.2, 38.4, 38.5 | - | - |
| 38.8 | Blocked by 38.3, 38.4, 38.6 | - | - |
| 38.7 | Blocked by 38.6, 38.8 | - | - |

## 4. Story Dependencies and Complexity

| Story | Depends On | Complexity |
| --- | --- | --- |
| 38.1 | Architecture lock, layout metadata confirmation | Medium |
| 38.2 | Story 38.1 database, model, and branch-scoping foundation | Large |
| 38.5 | 38.2 | Medium |
| 38.3 | 38.1, 38.2, 38.5 | Medium |
| 38.4 | 38.2, 38.5 | Large |
| 38.6 | 38.2, 38.4, 38.5 | Very Large |
| 38.8 | 38.3, 38.4, 38.6 | Small |
| 38.7 | 38.6, 38.8, existing `SaleCreationService` flow | Large |

## 5. Common Definition of Done

Every story is done when:

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass, where the story touches UI.
4. Required audit events are verified, where the story mutates dining state.
5. Required timeline/revision behavior is verified, where the story mutates dining state.
6. No architecture constraints are violated.
7. Code review is approved.
8. Relevant documentation or story notes are updated.
9. Database migrations include indexes, foreign-key behavior, and rollback verification.
10. Mutation endpoints enforce tenant, branch, terminal, active-shift, online-state, and expected-revision guards as applicable.
11. Multi-record operations are transactionally atomic.
12. New tables and endpoints have authorization and isolation tests.
13. Domain events are dispatched only after successful database commit where required by the architecture lock.

Any story introducing a dining mutation endpoint must include a backend online-only guard immediately. Story 38.8 completes the shared frontend behavior, cached floor-map behavior, standardized errors, and regression coverage.

## 6. Story 38.1: Service Areas and Visual Floor Plan Configuration

Objective:

Create the admin-side foundation for branch dining layouts.

Dependencies:

1. Architecture lock.
2. Layout metadata minimum schema confirmation.
3. Inactive/deleted table history behavior confirmation.

Deliverables:

1. `service_areas` and `dining_tables` schema.
2. Models, factories, and tenant/branch scoped relationships.
3. Admin controller/routes for service area and table CRUD.
4. Basic Inertia admin layout editor using existing admin UI patterns.
5. Validation for table numbers, capacity, and layout metadata.

Out of scope:

1. Active ticket operations.
2. POS terminal floor map.
3. Table occupancy status beyond static admin state.

Acceptance checks:

1. Authorized admin can create service area and tables.
2. Branch manager cannot edit another branch's layout.
3. Invalid layout metadata is rejected.
4. Table number uniqueness is enforced within service area.

## 7. Story 38.2: Dining Ticket and Table Mapping Foundation

Objective:

Introduce server-side dining tickets and table attachment history.

Dependencies:

1. Story 38.1 database, model, and branch-scoping foundation.

Deliverables:

1. `dining_tickets`, `dining_ticket_tables`, and `dining_ticket_items` schema.
2. Models and relationships.
3. `DiningTicketService` for opening tickets.
4. Attach primary table to ticket.
5. Prevent duplicate active primary ticket per table.
6. Initial `ticket_revision` support.
7. Nullable reservation reference for future reservation integration.
8. Ticket totals snapshot fields.
9. Ticket state machine validation.

Out of scope:

1. Split bills.
2. Ticket checkout.
3. Seat assignment UI.

Acceptance checks:

1. Cashier can open an online dining ticket for a vacant table.
2. Opening another active ticket for the same table is blocked.
3. Ticket/table mapping is auditable and tenant/branch scoped.
4. Ticket revision starts at the expected initial value and increments on mutation.
5. Illegal ticket status transitions are rejected.
6. Reservation deletion cannot cascade-delete dining history.

## 8. Story 38.3: Table Status Resolver and POS Floor Map Read Model

Objective:

Render accurate table state in the POS terminal.

Dependencies:

1. Story 38.1.
2. Story 38.2.
3. Story 38.5.

Deliverables:

1. `DiningTableStatusResolver`.
2. POS endpoint returning service areas, table coordinates, active ticket summaries, and status.
3. Floor map UI in terminal.
4. Cached read-only layout support for offline view.

Out of scope:

1. Offline mutations.
2. Drag/drop admin editing in POS.
3. Ticket item manipulation.

Acceptance checks:

1. Vacant tables show as vacant.
2. Tables with active tickets show as occupied.
3. Cleaning/reserved states display when configured.
4. Offline floor view is read-only.

## 9. Story 38.4: Seat Mapping and Ticket Item Assignment

Objective:

Allow dine-in tickets to carry item rows assigned to seats.

Dependencies:

1. Story 38.2.
2. Story 38.5.

Deliverables:

1. Add item to dining ticket from product catalog.
2. Assign or change seat number.
3. Move item between seats on same ticket.
4. Ticket item status transitions for open/voided/moved.
5. Optional course/fire sequencing fields.
6. Nullable preparation station reference for future kitchen routing.
7. Audit and timeline events for item and seat changes.

Out of scope:

1. KDS dispatch.
2. Inventory deduction before checkout.
3. Partial payment.

Acceptance checks:

1. Item pricing is snapshotted when added.
2. Seat assignment is optional but validated if present.
3. Voided/moved item rows remain traceable.
4. Course/fire fields validate when present without requiring KDS dispatch.
5. Mutation requests include the last known `ticket_revision`; stale requests are rejected without partially applying the operation.

## 10. Story 38.5: Dining Operation Audit Logs and Event Tracker

Objective:

Make structural dining operations reviewable.

Dependencies:

1. Story 38.2.

Deliverables:

1. Standard audit event names:
   - `TABLE_ASSIGNED`
   - `TABLE_MOVED`
   - `TABLE_MERGED`
   - `TABLE_UNMERGED`
   - `BILL_SPLIT_CREATED`
   - `BILL_SPLIT_REVERSED`
   - `ITEM_MOVED`
   - `SEAT_ASSIGNED`
   - `GUEST_COUNT_CHANGED`
   - `PARTIAL_PAYMENT_APPLIED`
   - `TICKET_CLOSED`
2. `dining_ticket_events` timeline table.
3. `dining_ticket_versions` revision history table.
4. Reusable audit payload builder.
5. Reusable timeline event writer.
6. Tests verifying before/after payload shape, timeline summaries, and revision increments.

Out of scope:

1. New audit UI.
2. Export/reporting screens.

Acceptance checks:

1. Every ticket/table structural mutation creates an audit log.
2. Audit logs include tenant, branch, terminal, user, source/target references, before/after payload, and reason when required.
3. Timeline events produce manager-readable summaries without duplicating sensitive payment data.
4. Revision records are append-only and monotonic per ticket.

## 11. Story 38.6: Bill Split Allocator Engine

Objective:

Split tickets while preserving exact centavos totals.

Dependencies:

1. Story 38.2.
2. Story 38.4.
3. Story 38.5.

Deliverables:

1. `bill_split_allocations` schema.
2. `BillSplitAllocatorService`.
3. Split by seat.
4. Split by item/quantity.
5. Promotion allocation movement.
6. Deterministic rounding adjustment to final child ticket.

Out of scope:

1. Payment capture on split child tickets.
2. Statutory discount application before split.

Acceptance checks:

1. Sum of child allocated amounts equals parent total.
2. Sum of promotion allocation equals parent promotion allocation.
3. Rounding remainder is deterministic.
4. Splitting a ticket with pre-applied statutory discount is blocked.
5. Mutation requests include the last known `ticket_revision`; stale requests are rejected without partially applying the operation.
6. Child tickets, item allocations, promotion allocations, rounding adjustment, revisions, timeline events, audit records, and parent state changes commit atomically in one database transaction.

## 12. Story 38.7: Partial Payments and Ticket Split Checkout Integration

Objective:

Convert split tickets into sale/payment flows without bypassing existing POS controls.

Dependencies:

1. Story 38.6.
2. Story 38.8.
3. Existing `SaleCreationService` flow.
4. Existing payment and shift guard behavior.

Deliverables:

1. Dining ticket checkout handoff to `SaleCreationService`.
2. Split child ticket checkout path.
3. Existing `PaymentController::storeSplit` remains tender authority.
4. Shift and timecard guards apply.
5. Checkout idempotency uses `checkout_request_uuid`.
6. Ticket closes only after sale/payment success.

Out of scope:

1. Store credit/loyalty redemption.
2. Offline dining checkout.

Acceptance checks:

1. Paid child ticket links to `source_sale_id`.
2. Parent ticket derives and exposes aggregate settlement progress from its child tickets until all payable child tickets are closed.
3. Failed payment does not close ticket.
4. Inventory deduction happens once through existing sale/payment flow.
5. Same ticket, same UUID, same checkout request returns the original result.
6. Same ticket, same UUID, materially different checkout request is rejected as idempotency drift.
7. The UUID cannot produce sales for two different dining tickets.
8. An unknown payment result does not automatically retry sale creation.

## 13. Story 38.8: Offline Restrictions and Online-Only Error Flags

Objective:

Enforce the Epic 38 offline policy.

Dependencies:

1. Story 38.3.
2. Story 38.4.
3. Story 38.6.

Deliverables:

1. Frontend guards for dining mutations while offline.
2. Backend fail-closed checks for dining mutation endpoints.
3. Read-only cached floor map behavior.
4. Clear cashier-facing error copy.

Out of scope:

1. Offline dining ticket queueing.
2. Offline merge/split replay.

Acceptance checks:

1. Direct walk-in offline cash sale remains available.
2. Open ticket/add item/move/merge/split/partial payment are blocked offline.
3. Cached layout can be viewed offline without mutation controls.

## 14. Story 38.1 Definition of Ready

Story 38.1 is approved for implementation. The layout-specific confirmations are closed in:

```text
docs/implementation-plans/epic-38/stories/story-38.1.md
```
