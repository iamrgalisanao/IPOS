# Story 38.4: Seat Mapping and Ticket Item Assignment

## Status

Implemented - Pending Review

This story has a local implementation ready for review. Any implementation feedback must stay within this story boundary unless the Epic 38 architecture lock is formally revised.

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.2.md`
4. `docs/implementation-plans/epic-38/stories/story-38.5.md`
5. `app/Models/DiningTicket.php`
6. `app/Models/DiningTicketItem.php`
7. `app/Services/Dining/DiningTicketService.php`
8. `app/Services/Dining/DiningOperationAuditService.php`
9. `app/Services/Dining/DiningTicketRevisionService.php`
10. `app/Services/Dining/DiningTicketTimelineService.php`

## Objective

Allow active dine-in tickets to carry mutable item rows assigned to optional seat numbers, while preserving pricing snapshots, auditability, revision safety, and future split-bill readiness.

This story gives cashiers the ability to add items to a dining ticket, update quantities for open items, assign or change seat numbers, move items between seats on the same ticket, and void item rows without deleting historical item records.

## Dependencies

1. Story 38.2 dining ticket foundation.
2. Story 38.5 audit, revision, and timeline foundation.
3. Existing product catalog and `Product::getSaleSnapshotBase()`.
4. Existing POS terminal identity middleware.
5. Existing POS product search/catalog UI surface.

## Out of Scope

1. Checkout and payment.
2. Sale creation or `sale_items` conversion.
3. Inventory deduction or stock reservation.
4. Split bill allocation.
5. Promotion recalculation.
6. Statutory discount application.
7. Kitchen Display System dispatch.
8. Preparation station routing execution.
9. Table move, merge, or unmerge.
10. Offline dining item mutation replay.
11. Manager approval framework changes beyond using existing authorization where required.

## Locked Decisions

1. `DiningTicket` remains the aggregate root.
2. `DiningTicketItemService` is the sole mutation service for dining ticket item rows.
3. Controllers must not write `DiningTicketItem` records directly.
4. All item mutations require an active dining ticket with status `open` or `settling`.
5. All item mutations require the last known `ticket_revision`.
6. A stale `ticket_revision` returns `409` and does not partially apply the mutation.
7. Item rows are never physically deleted by cashier operations.
8. Voided item rows remain in `dining_ticket_items` with status `voided`.
9. Moved item rows remain traceable with status `moved` and a replacement row linked through `source_item_id`.
10. Product price is snapshotted when an item is added.
11. Existing item price snapshots are not recalculated when product prices change.
12. Seat assignment is optional.
13. Seat assignment belongs to item rows, not to separate seat entities in this story.
14. `promotion_allocation_snapshot` remains nullable and is not calculated in this story.
15. Item subtotal fields on `dining_tickets` may be updated from active item rows, but checkout totals remain owned by later checkout stories.
16. `preparation_station_id`, `course_no`, `fire_group`, and `hold_until` may be stored and validated, but do not trigger kitchen dispatch.
17. Moved item lineage must not branch. A moved source item can have only one active replacement row.
18. Totals must be derived from active rows during each recalculation, not incrementally adjusted from deltas.
19. Quantity mutations remain allowed for `open` items only.
20. Voiding confirmed or kitchen-progress items is out of scope and reserved for a future approval-backed workflow.
21. The initial UI entry point is the active dining ticket. The floor map may navigate into the ticket, but checkout cart integration must not become a competing mutation workflow.

## User Stories

1. As a cashier, I can add a catalog item to an open dining ticket so the guest order is captured before checkout.
2. As a cashier, I can assign an item to a seat number so later split-by-seat workflows have reliable source data.
3. As a cashier, I can change the seat number for an item while preserving an audit trail.
4. As a cashier, I can move an item between seats on the same ticket without losing the original row history.
5. As a cashier, I can void an item with a reason so mistakes remain traceable.
6. As support, I can inspect item changes through audit logs, timeline events, and ticket revision history.

## Domain Model

### Existing Table

Story 38.2 already created `dining_ticket_items`.

Required fields already exist:

1. `tenant_id`
2. `branch_id`
3. `dining_ticket_id`
4. `product_id`
5. `seat_number`
6. `quantity`
7. `unit_price_centavos`
8. `line_total_centavos`
9. `status`
10. `source_item_id`
11. `course_no`
12. `fire_group`
13. `hold_until`
14. `preparation_station_id`
15. `promotion_allocation_snapshot`

No schema migration is expected unless implementation discovers a missing index or constraint required for acceptable query performance.

### Item Statuses

Initial statuses remain:

```text
open
confirmed
sent_to_kitchen
preparing
ready
served
voided
moved
```

Story 38.4 implements only cashier item-management status changes:

```text
open -> voided
open -> moved
```

Kitchen progress statuses are reserved for future stories and must not be exposed as cashier mutation endpoints in this story.

## Command Value Objects

Implementation should avoid passing raw request arrays deep into the mutation service.

Create explicit command/value objects for each mutation:

```text
AddDiningTicketItemCommand
ChangeDiningTicketItemQuantityCommand
AssignDiningTicketItemSeatCommand
MoveDiningTicketItemSeatCommand
VoidDiningTicketItemCommand
```

Each command should contain only validated, material business fields. Transport details, headers, request timestamps, and controller-only metadata must stay outside the command.

Command objects should make mutation intent clear and keep `DiningTicketItemService` contracts stable as the story grows.

## Mutation Rules

### Add Item

Allowed when:

1. Ticket belongs to the active tenant and branch.
2. Ticket status is `open` or `settling`.
3. Terminal belongs to the same tenant and branch.
4. Actor can access the branch.
5. Actor has `create_sale`.
6. `expected_ticket_revision` equals the current ticket revision.
7. Product exists in the same tenant and is active/sellable.
8. Quantity is greater than zero.
9. Seat number is null or within the allowed range.

Behavior:

1. Lock the ticket row with `lockForUpdate()`.
2. Validate ticket status and revision.
3. Resolve the product server-side.
4. Snapshot product price using existing product snapshot logic.
5. Store `unit_price_centavos` and `line_total_centavos` as integers.
6. Create one `DiningTicketItem` row with status `open`.
7. Increment `ticket_revision`.
8. Recalculate dining ticket item totals from active item rows.
9. Record audit, version, and timeline entries.
10. Return the updated ticket item and current ticket revision.

### Update Quantity

Allowed only for item rows with status `open`.

Behavior:

1. Lock the ticket row.
2. Lock the item row through the ticket aggregate.
3. Validate revision.
4. Recalculate `line_total_centavos = unit_price_centavos * quantity`.
5. Increment `ticket_revision`.
6. Recalculate dining ticket item totals.
7. Record audit, version, and timeline entries.

Quantity updates are not allowed for `voided`, `moved`, or future kitchen-progress rows.

If the requested quantity equals the current quantity, return the current representation without incrementing `ticket_revision`, recalculating totals, or writing audit, version, or timeline rows.

### Assign Seat

Allowed for item rows with status `open`.

Behavior:

1. Lock the ticket row.
2. Validate revision.
3. Update `seat_number`.
4. Increment `ticket_revision`.
5. Record audit, version, and timeline entries.

Seat assignment does not change item pricing.

If the requested seat equals the current seat, return the current representation without incrementing `ticket_revision`, recalculating totals, or writing audit, version, or timeline rows.

### Move Item Between Seats

Moving an item means preserving the original row and creating a replacement row.

Behavior:

1. Lock the ticket row.
2. Lock the source item row.
3. Validate revision.
4. Mark the source item status as `moved`.
5. Create a replacement item row with:
   - same `product_id`
   - same `quantity`
   - same `unit_price_centavos`
   - same `line_total_centavos`
   - new `seat_number`
   - status `open`
   - `source_item_id` referencing the moved source row
   - same course/fire/prep metadata
   - same `promotion_allocation_snapshot`
6. Increment `ticket_revision`.
7. Record audit, version, and timeline entries.

This rule preserves item lineage for Story 38.6 split allocation.

If the requested seat equals the current seat, reject the request with `422` instead of creating a replacement row or incrementing `ticket_revision`.

Before moving, verify the source item has no existing non-voided replacement row. A moved source item can have only one active replacement so item lineage remains linear and unambiguous.

### Void Item

Allowed for item rows with status `open`.

Behavior:

1. Lock the ticket row.
2. Lock the item row.
3. Validate revision.
4. Require a void reason.
5. Mark the item status as `voided`.
6. Keep price and quantity snapshots unchanged.
7. Increment `ticket_revision`.
8. Recalculate dining ticket item totals excluding voided and moved rows.
9. Record audit, version, and timeline entries.

No manager approval is required in this story for voiding an unsent `open` item. Voiding sent or prepared items is reserved for a future approval-backed workflow.

## Validation Rules

### Common Payload Fields

Every mutation request includes:

```json
{
  "expected_ticket_revision": 3
}
```

If the revision is stale:

```json
{
  "code": "DINING_TICKET_REVISION_CONFLICT",
  "message": "The dining ticket was updated by another terminal.",
  "current_ticket_revision": 4
}
```

### Add Item Request

```json
{
  "product_id": "uuid",
  "quantity": "2.000",
  "seat_number": 1,
  "course_no": 1,
  "fire_group": "mains",
  "hold_until": null,
  "preparation_station_id": null,
  "expected_ticket_revision": 3
}
```

Validation:

1. `product_id` required UUID.
2. `quantity` required numeric, min `0.001`, max `999.999`, up to 3 decimals.
3. `seat_number` nullable integer, min `1`, max `99`.
4. `course_no` nullable integer, min `1`, max `20`.
5. `fire_group` nullable string, one of `starter`, `main`, `dessert`, `drinks`, or `custom`.
6. `hold_until` nullable ISO datetime, must not be in the past.
7. `preparation_station_id` nullable UUID.
8. `expected_ticket_revision` required integer, min `1`.

Quantity precision supports up to three decimals for platform consistency and weighted products. Restaurant menu items typically use whole quantities, but the service must preserve decimal quantities where the catalog/product workflow supports them.

Implementation must use fixed-point decimal handling for quantity arithmetic and integer centavos for money. Do not use binary floating-point arithmetic to calculate persisted `line_total_centavos` or ticket totals.

Seat range `1-99` is the platform default for Story 38.4. Future tenant-level seat-limit configuration may narrow or expand the validation range without changing the item data model.

### Seat Request

```json
{
  "seat_number": 2,
  "expected_ticket_revision": 4
}
```

`seat_number` may be `null` to unassign the item from a seat.

### Move Request

```json
{
  "seat_number": 3,
  "expected_ticket_revision": 5
}
```

`seat_number` is required for move operations.

### Void Request

```json
{
  "reason": "Wrong item selected",
  "expected_ticket_revision": 6
}
```

Validation:

1. `reason` required string, min `3`, max `255`.
2. `expected_ticket_revision` required integer.

## API Contracts

All routes are authenticated, tenant scoped, branch scoped, terminal scoped, and require `permission:create_sale`.

Recommended route names:

```text
POST   /pos/dining/tickets/{ticket}/items
PATCH  /pos/dining/tickets/{ticket}/items/{item}/quantity
PATCH  /pos/dining/tickets/{ticket}/items/{item}/seat
POST   /pos/dining/tickets/{ticket}/items/{item}/move-seat
POST   /pos/dining/tickets/{ticket}/items/{item}/void
```

Recommended route names:

```text
pos.dining.tickets.items.store
pos.dining.tickets.items.quantity
pos.dining.tickets.items.seat
pos.dining.tickets.items.move-seat
pos.dining.tickets.items.void
```

Response shape:

```json
{
  "dining_ticket": {
    "id": "uuid",
    "ticket_number": "DT-20260714-000001",
    "status": "open",
    "ticket_revision": 4,
    "subtotal_centavos": 25000,
    "grand_total_centavos": 25000
  },
  "item": {
    "id": "uuid",
    "product_id": "uuid",
    "product_name": "Latte",
    "seat_number": 1,
    "quantity": "2.000",
    "unit_price_centavos": 12500,
    "line_total_centavos": 25000,
    "status": "open",
    "source_item_id": null,
    "course_no": 1,
    "fire_group": "mains",
    "hold_until": null,
    "preparation_station_id": null
  }
}
```

For move operations, return both rows:

```json
{
  "dining_ticket": {
    "id": "uuid",
    "ticket_revision": 7
  },
  "source_item": {
    "id": "uuid",
    "status": "moved"
  },
  "item": {
    "id": "uuid",
    "source_item_id": "uuid",
    "seat_number": 3,
    "status": "open"
  }
}
```

## HTTP Status Codes

| Condition | Status |
| --- | ---: |
| Successful add | `201` |
| Successful update, seat assignment, move, or void | `200` |
| Validation failure | `422` |
| Unauthorized or missing permission | `403` |
| Cross-tenant or cross-branch hidden resource | `404` |
| Missing or invalid terminal context | `403` |
| Stale ticket revision | `409` |
| Mutation not allowed for current ticket or item status | `409` |

## Service Design

Create `DiningTicketItemService`.

Responsibilities:

1. `addItem(DiningTicket $ticket, array $data, User $actor, SalesMachineProfile $terminal): DiningTicketItem`
2. `changeQuantity(DiningTicket $ticket, DiningTicketItem $item, array $data, User $actor, SalesMachineProfile $terminal): DiningTicketItem`
3. `assignSeat(DiningTicket $ticket, DiningTicketItem $item, array $data, User $actor, SalesMachineProfile $terminal): DiningTicketItem`
4. `moveToSeat(DiningTicket $ticket, DiningTicketItem $item, array $data, User $actor, SalesMachineProfile $terminal): DiningTicketItem`
5. `voidItem(DiningTicket $ticket, DiningTicketItem $item, array $data, User $actor, SalesMachineProfile $terminal): DiningTicketItem`

Every method must:

1. Run inside a database transaction.
2. Lock the ticket row.
3. Lock the item row where applicable.
4. Accept a validated command object rather than a raw request payload.
5. Validate tenant, branch, actor, terminal, ticket status, item status, and revision.
6. Reject move-seat requests that target the current seat.
7. Treat unchanged quantity requests as no-op reads.
8. Treat unchanged seat assignment requests as no-op reads.
9. Enforce linear move lineage.
10. Use a shared active-row predicate for totals, such as an `activeForTotals()` scope or equivalent helper.
11. Update ticket revision only when a real mutation occurs.
12. Recalculate item totals from active rows.
13. Record audit, revision, and timeline only when a real mutation occurs.
14. Return a freshly loaded item/ticket payload.

## Product Snapshot Boundary

When adding an item, capture the immutable dining item snapshot from the product catalog.

At minimum, the mutation must preserve:

1. Product ID.
2. SKU or barcode where available.
3. Product display name.
4. Unit of measure.
5. Unit price in centavos.
6. Tax category ID and tax type/rate context where available.
7. Discountability flag.

The current `dining_ticket_items` table stores the financial fields directly and references `product_id`. If implementation needs immutable display fields that are not yet represented as columns, capture them in the audit/revision snapshot first and defer schema expansion unless required for UI correctness.

Future product catalog changes must not alter existing dining item price, quantity, or line total snapshots.

Product activity, sellability, and branch/catalog availability are validated at add time only. Later product deactivation, archival, or price changes must not invalidate, remove, or recalculate an existing dining ticket item.

## Totals Policy

Story 38.4 updates dining ticket operational item totals only.

For this story:

1. `subtotal_centavos` equals the sum of active item `line_total_centavos`.
2. `discount_centavos` remains `0`.
3. `service_charge_centavos` remains `0`.
4. `tax_centavos` remains `0`.
5. `grand_total_centavos` equals `subtotal_centavos`.

Checkout and compliance totals are finalized later by `SaleCreationService`.

Active rows for total recalculation are rows whose status is not `voided` and not `moved`.

Centralize this predicate in implementation so Story 38.4 and later stories use the same definition. A model scope such as `DiningTicketItem::activeForTotals()` is preferred if it fits the local code style.

Recalculation must always derive from persisted active rows:

```text
subtotal_centavos = SUM(active dining_ticket_items.line_total_centavos)
```

Do not update cached ticket totals by applying an arithmetic delta to the previous ticket subtotal.

## Audit, Revision, and Timeline

Extend existing services rather than creating new audit tables.

### Audit Actions

Recommended audit actions:

```text
DINING_ITEM_ADDED
DINING_ITEM_QUANTITY_CHANGED
DINING_ITEM_SEAT_ASSIGNED
DINING_ITEM_MOVED
DINING_ITEM_VOIDED
```

Audit payloads must include:

1. `schema_version`
2. `ticket_id`
3. `ticket_number`
4. `ticket_revision`
5. `item_id`
6. `product_id`
7. `product_name`
8. `seat_number`
9. `quantity`
10. `unit_price_centavos`
11. `line_total_centavos`
12. `status`
13. `source_item_id`
14. `actor_user_id`
15. `terminal_id`

Audit payload shape should be owned by a serializer or audit payload helper rather than assembled independently inside each mutation method. This keeps audit schemas versionable and consistent as later stories add optional item metadata.

### Revision Operations

Recommended revision operations:

```text
item_added
item_quantity_changed
seat_assigned
item_moved
item_voided
```

### Timeline Events

Recommended timeline event types:

```text
item_added
seat_assigned
item_moved
item_voided
```

Quantity changes may use `item_updated` or `item_quantity_changed`; choose one stable identifier during implementation and test it.

Timeline summaries are generated by `DiningTicketTimelineService`, not supplied by controllers.

## UI Scope

Add item/seat controls to the POS dining ticket surface only after the backend API exists.

Expected UI capabilities:

1. Open active ticket detail from the floor-map occupied table or ticket context.
2. Add catalog item to active ticket.
3. Choose optional seat number before adding.
4. Show ticket item list grouped by seat.
5. Change seat number for open items.
6. Move item to another seat.
7. Void open item with reason.
8. Display stale revision conflict and refresh the ticket state.
9. Disable item mutation controls while offline.
10. Hide kitchen-progress controls because KDS is out of scope.

The UI must not expose checkout, split, payment, table move, or merge behavior in this story.

## Concurrency

All mutations require optimistic revision and row locks.

Required behavior:

1. Two terminals adding items at the same revision cannot both succeed.
2. First successful mutation increments `ticket_revision`.
3. Second stale mutation returns `409`.
4. Stale requests do not create partial item rows.
5. Stale requests do not write audit, version, or timeline rows.

## Offline Policy

Offline dining mutations remain prohibited.

Frontend behavior:

1. If offline, item mutation buttons are disabled.
2. Cached floor map or ticket state may be displayed read-only.
3. No item mutation is queued for later replay.
4. Returning online requires refreshing the ticket before mutation.

Backend behavior:

1. Requires online request with valid terminal context.
2. Does not accept local/offline mutation envelopes.

## Test Plan

### Backend Feature Tests

1. Cashier can add item to open ticket.
2. Add item snapshots product price in integer centavos.
3. Later product price changes do not alter existing item rows.
4. Add item increments `ticket_revision`.
5. Add item recalculates ticket item totals.
6. Add item records audit, version, and timeline.
7. Seat number may be null.
8. Seat number must be between 1 and 99 when provided.
9. Quantity must be positive and max 3 decimals.
10. Cashier can change quantity for open item.
11. Quantity change recalculates line and ticket totals.
12. Cashier can assign, change, and clear seat for open item.
13. Cashier can move item to another seat and original row becomes `moved`.
14. Move creates replacement row linked by `source_item_id`.
15. Cashier can void open item with reason.
16. Voided item remains in the database.
17. Voided item is excluded from ticket totals.
18. Closed ticket rejects item mutation.
19. Voided or moved item rejects quantity/seat changes.
20. Cross-branch ticket access returns `404`.
21. Missing permission returns `403`.
22. Missing terminal context returns `403`.
23. Stale revision returns `409`.
24. Stale revision does not write item, audit, version, or timeline rows.

### Frontend Tests

1. Item panel renders active ticket items grouped by seat.
2. Add item sends `expected_ticket_revision`.
3. Seat assignment sends nullable `seat_number`.
4. Void action requires a reason.
5. Offline mode disables all item mutation actions.
6. Revision conflict shows refresh-required state.

## Rollout Plan

1. Implement backend service, requests, controller, routes, and tests.
2. Verify backend item mutation behavior with feature tests.
3. Add POS ticket item UI behind the existing terminal and permission gates.
4. Verify frontend build and targeted frontend tests.
5. Run full dining feature suite.
6. Pilot with simple add, seat, move, and void workflows before starting Story 38.6.

## Rollback Considerations

1. No expected schema rollback if no migration is added.
2. If a small index migration is added, rollback must drop only the new index.
3. Disable UI entry points first if item mutations need to be paused.
4. Backend routes can remain protected by permission and terminal gates while UI is disabled.
5. Item rows created during pilot remain historical dining records and should not be deleted.

## Review Decisions

1. Quantity changes are allowed only while the item status is `open`.
2. Voiding confirmed or kitchen-progress items remains out of scope for Story 38.4.
3. `fire_group` uses the constrained values `starter`, `main`, `dessert`, `drinks`, and `custom`.
4. The initial UI starts from the active dining ticket; the floor map may navigate into the ticket.

## Definition of Done Checklist

1. Specification approved before implementation begins.
2. Acceptance checks pass.
3. Backend feature tests pass.
4. Frontend tests pass where UI is added.
5. Audit actions are verified.
6. Timeline events are verified.
7. Revision snapshots are verified.
8. Stale mutation conflicts are verified.
9. Offline mutation prohibition is verified.
10. No architecture constraints are violated.
11. Code review is approved.
12. Documentation status is updated.
