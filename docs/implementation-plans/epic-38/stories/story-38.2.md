# Story 38.2: Dining Ticket and Table Mapping Foundation

## Status

Approved for Implementation

This specification is approved for engineering implementation. Implementation must stay within this story boundary unless the Epic 38 architecture lock is formally revised.

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.1.md`
4. Story 38.1 merged implementation:
   - `service_areas`
   - `dining_tables`
   - `ServiceArea`
   - `DiningTable`
   - `DiningLayoutService`
   - `ServiceAreaController`

## Objective

Introduce server-side dining tickets and durable table attachment history so an online cashier can open a dining ticket for an active table without creating an official sale yet.

This story creates the dining aggregate foundation only. It does not add item ordering UI, split bills, POS floor-map rendering, or checkout.

## Locked Decisions

1. `DiningTicket` is the aggregate root for active dining operations.
2. A dining ticket is mutable operational state until checkout; sales remain immutable after checkout.
3. Story 38.2 opens tickets only. It does not convert tickets into sales.
4. A table may have only one active primary ticket at a time.
5. Active ticket statuses are `open` and `settling`.
6. Terminal, tenant, branch, cashier, and online context are required for opening a ticket.
7. Ticket mutations use integer centavos for all money fields.
8. `ticket_revision` starts at `1` when a ticket is opened.
9. Every successful post-open mutation increments `ticket_revision`.
10. Story 38.2 implements state transition validation, but only the open-ticket flow is exposed to POS users.
11. Reservation linkage is nullable and future-facing. A reservation delete must not delete dining history.
12. Story 38.2 replaces the Story 38.1 placeholder active-ticket reference checks in `DiningLayoutService`.
13. `dining_ticket_tables` is append-preserving history. Detached rows remain; they are not overwritten or deleted during later move/merge stories.
14. Story 38.2 does not persist `occupied` on `dining_tables.operational_state`. Occupancy remains derived from active ticket mappings.
15. Story 38.2 may create an initial lightweight timeline event for opening a ticket, but the full audit/version framework remains Story 38.5.
16. Ticket opening uses `client_request_uuid` idempotency so retrying the same open request returns the original ticket instead of creating a duplicate or surfacing a confusing conflict.
17. Closing or voiding a ticket does not automatically modify `dining_tables.operational_state`. Cleaning and reset workflows belong to later stories.
18. `ticket_number` is immutable after creation. It must not change when a ticket is reopened, moved, merged, split, settled, closed, or voided.
19. `DiningTicketService` never creates sales and never captures payments. Checkout remains owned by the existing `SaleCreationService` flow in Story 38.7.

## Dependencies

1. Story 38.1 database, model, branch-scoping, and layout foundation.
2. Existing tenant and branch context middleware.
3. Existing terminal identity middleware.
4. Existing `create_sale` permission for cashier POS operations.
5. Existing active-shift/timecard enforcement for POS checkout paths.
6. Existing `AuditLogger` for formal audit entries available in this slice.

## Out of Scope

1. Adding dining ticket items from the product catalog.
2. Seat assignment UI.
3. POS floor-map read model.
4. Split bills.
5. Ticket checkout and sale creation.
6. Partial payments.
7. Kitchen display dispatch.
8. Reservations feature behavior beyond nullable references.
9. Full dining timeline/version UI.
10. Offline dining mutations.

## User Stories

1. As a cashier, I can open an online dining ticket for an active vacant table so that guests can start dine-in service.
2. As a cashier, I am blocked from opening another active primary ticket for the same table so that two terminals cannot accidentally own the same table.
3. As a manager, I can trust that service-area and table deactivation/delete guards recognize active and historical dining tickets.
4. As support, I can inspect ticket/table mappings and identify who opened a ticket, when, from which branch, and on which terminal.

## Technical Approach

1. Add database tables for dining tickets, ticket/table mappings, and initial ticket item rows.
2. Add Eloquent models, factories, and relationships following the existing UUID and tenant conventions.
3. Add `DiningTicketService` as the only writer for ticket opening and status transitions in this story.
4. Open tickets inside a database transaction with row locking on the selected dining table.
5. Validate tenant and branch scope before mutation.
6. Require active service area and active dining table for ticket opening.
7. Reject opening against inactive, reserved, cleaning, deleted, cross-tenant, or cross-branch tables.
8. Reject duplicate active primary tickets for the same table.
9. Create the ticket, primary table mapping, initial audit entry, and optional initial timeline event atomically.
10. Keep ticket total snapshots at zero in this story because no item rows are added yet.
11. Implement explicit status transition validation on the service/model layer.
12. Add real active-ticket and historical-reference checks to `DiningLayoutService` so Story 38.1 delete/deactivation policies become enforceable.
13. Expose only a minimal JSON POS endpoint for opening tickets.
14. Keep admin UI unchanged except for delete/deactivation behavior now responding to real ticket references.
15. Use a dedicated `DiningTicketNumberService` for branch-scoped ticket number generation instead of embedding numbering logic inside `DiningTicketService`.
16. Use database transactions with row locks for open-ticket and status-transition mutations. Expected semantics are `READ COMMITTED` plus `SELECT ... FOR UPDATE` or the closest supported equivalent for the project database.

## Aggregate Invariants

1. Only `DiningTicketService` mutates ticket state, ticket table mappings, and ticket item rows in this story.
2. Only one active primary ticket may exist for a dining table.
3. An active dining ticket must have exactly one active primary table mapping.
4. Ticket totals are derived snapshots and must not be manually edited outside aggregate services.
5. `ticket_revision` is monotonic and increases after every successful post-open mutation.
6. Closed and voided tickets are immutable for operational edits.
7. Tenant, branch, terminal, and actor context must be present for every dining ticket mutation.
8. Dining ticket mutations are online-only and never enter the offline sales queue.
9. Failed mutations do not increment `ticket_revision`.
10. Ticket numbers are immutable once assigned.

## Domain Model

### `DiningTicket`

Represents the mutable pre-checkout dining aggregate.

Initial status values:

```text
open
settling
closed
voided
```

Legal transitions:

```text
open -> settling
open -> voided
settling -> closed
settling -> open
```

The `settling -> open` transition is allowed only when checkout/payment is cancelled or fails before sale finalization. Story 38.2 should implement the transition rule but does not expose checkout behavior.

Illegal transitions must return a validation/domain conflict response. Examples:

```text
closed -> open
closed -> settling
voided -> open
voided -> settling
```

### `DiningTicketTable`

Represents table attachment history.

Initial role values:

```text
primary
joined
moved_from
moved_to
```

Story 38.2 creates only active `primary` rows. The other roles are reserved for later move/merge stories and should be accepted in model constants or validators only if needed by future-safe tests.

Active mapping definition:

```text
detached_at IS NULL
AND role = primary
AND dining ticket status IN (open, settling)
```

### `DiningTicketItem`

Represents future mutable dining item rows before conversion to `sale_items`.

Story 38.2 creates the table and model but does not expose item mutation endpoints.

Initial item status values:

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

Default item transition path:

```text
open -> confirmed -> sent_to_kitchen -> preparing -> ready -> served
```

Story 38.2 should define constants and validation helpers only if they are needed by the item model. Full item mutation enforcement belongs to Story 38.4.

## Database Migrations

### `dining_tickets`

Recommended columns:

```text
id UUID primary
tenant_id UUID foreign key -> tenants.id cascade on delete
branch_id UUID foreign key -> branches.id restrict on delete
ticket_number string
status string default open
guest_count unsigned small integer default 1
subtotal_centavos unsigned big integer default 0
discount_centavos unsigned big integer default 0
service_charge_centavos unsigned big integer default 0
tax_centavos unsigned big integer default 0
grand_total_centavos unsigned big integer default 0
opened_by UUID nullable foreign key -> users.id null on delete
opened_at timestamp
closed_at timestamp nullable
parent_ticket_id UUID nullable foreign key -> dining_tickets.id null on delete
source_sale_id UUID nullable foreign key -> sales.id null on delete
terminal_id UUID nullable foreign key -> sales_machine_profiles.id null on delete
ticket_revision unsigned integer default 1
reservation_id UUID nullable
checkout_request_uuid UUID nullable
client_request_uuid UUID nullable
pricing_engine_version string nullable
tax_engine_version string nullable
discount_engine_version string nullable
notes text nullable
created_at
updated_at
```

Indexes and constraints:

```text
INDEX tenant_id, branch_id
INDEX tenant_id, branch_id, status
INDEX branch_id, ticket_number
INDEX terminal_id
INDEX opened_by
INDEX parent_ticket_id
INDEX source_sale_id
UNIQUE tenant_id, branch_id, ticket_number
UNIQUE tenant_id, branch_id, client_request_uuid nullable where supported, or normal nullable unique if project database supports desired semantics
UNIQUE checkout_request_uuid nullable where supported, or normal nullable unique if project database supports desired semantics
```

Implementation notes:

1. Use integer centavos fields only.
2. Ticket number generation must be branch-scoped and deterministic enough for support lookup.
3. Use `DiningTicketNumberService` with a conservative branch-scoped POS business-date sequence format such as `DT-YYYYMMDD-000001` unless an existing sequence helper is available.
4. `reservation_id` remains nullable without a concrete foreign key unless a reservations table already exists during implementation. If a reservations table exists, use `nullOnDelete`, never cascade.
5. `source_sale_id` is nullable and remains unset until Story 38.7 checkout.
6. `checkout_request_uuid` is nullable and reserved for checkout idempotency in Story 38.7.
7. `client_request_uuid` is nullable but required by the open-ticket request. It supports idempotent ticket opening and is separate from checkout idempotency.
8. Store enough request fingerprint metadata, either directly or through a deterministic hash, to detect idempotency drift for reused `client_request_uuid` values.

### `dining_ticket_tables`

Recommended columns:

```text
id UUID primary
tenant_id UUID foreign key -> tenants.id cascade on delete
branch_id UUID foreign key -> branches.id restrict on delete
dining_ticket_id UUID foreign key -> dining_tickets.id cascade on delete
dining_table_id UUID foreign key -> dining_tables.id restrict on delete
role string
attached_at timestamp
detached_at timestamp nullable
created_at
updated_at
```

Indexes and constraints:

```text
INDEX tenant_id, branch_id
INDEX dining_ticket_id
INDEX dining_table_id
INDEX dining_table_id, role, detached_at
```

Duplicate active primary guard:

1. Enforce in `DiningTicketService` inside a transaction with row locks.
2. Add the strongest database constraint available for the target database if it is portable. If not portable, document the service-level lock and add concurrency tests.
3. The rule must check active ticket status, not only `detached_at`, so historical closed-ticket mappings do not block reuse.

### `dining_ticket_items`

Recommended columns:

```text
id UUID primary
tenant_id UUID foreign key -> tenants.id cascade on delete
branch_id UUID foreign key -> branches.id restrict on delete
dining_ticket_id UUID foreign key -> dining_tickets.id cascade on delete
product_id UUID nullable foreign key -> products.id null on delete
seat_number unsigned small integer nullable
quantity decimal(12, 3) default 1
unit_price_centavos unsigned big integer default 0
line_total_centavos unsigned big integer default 0
status string default open
source_item_id UUID nullable foreign key -> dining_ticket_items.id null on delete
course_no unsigned small integer nullable
fire_group string nullable
hold_until timestamp nullable
preparation_station_id UUID nullable
promotion_allocation_snapshot JSON nullable
created_at
updated_at
```

Indexes and constraints:

```text
INDEX tenant_id, branch_id
INDEX dining_ticket_id
INDEX product_id
INDEX source_item_id
INDEX status
```

Implementation notes:

1. Story 38.2 should not add item rows from API requests.
2. Item rows exist now to avoid a migration redesign before Story 38.4.
3. `preparation_station_id` stays nullable without a foreign key until kitchen routing exists.
4. `promotion_allocation_snapshot` stays nullable until item and promotion allocation stories populate it.

## Model and Relationship Requirements

### New models

1. `App\Models\DiningTicket`
2. `App\Models\DiningTicketTable`
3. `App\Models\DiningTicketItem`

Each model should use:

1. `HasFactory`
2. `HasUuids`
3. `BelongsToTenant`
4. Existing fillable/cast conventions

### Required relationships

`DiningTicket`:

```text
tenant
branch
openedBy
terminal
sourceSale
parentTicket
childTickets
tableMappings
activeTableMappings
primaryTableMapping
tables through mappings, if practical
items
```

`DiningTicketTable`:

```text
ticket
table
tenant
branch
```

`DiningTicketItem`:

```text
ticket
product
sourceItem
derivedItems
tenant
branch
```

Existing models should gain safe read relationships:

1. `DiningTable::ticketMappings()`
2. `DiningTable::activeTicketMappings()`
3. `ServiceArea::activeDiningTickets()` or an equivalent query helper if useful for guard implementation.

## Service Requirements

### `DiningTicketNumberService`

Required method:

```php
nextForBranch(string $tenantId, string $branchId, ?CarbonInterface $businessDate = null): string
```

Responsibilities:

1. Generate branch-scoped ticket numbers.
2. Use the POS business date, not raw server calendar date, unless an explicit date is provided.
3. Produce a readable format such as `DT-20260714-000001`.
4. Avoid reusing numbers within the same tenant and branch.
5. Keep numbering rules isolated from `DiningTicketService`.

### `DiningTicketService`

Required methods:

```php
openTicket(DiningTable $table, array $data, User $actor, ?SalesMachineProfile $terminal): DiningTicket
transitionStatus(DiningTicket $ticket, string $targetStatus, User $actor, ?int $expectedRevision = null): DiningTicket
assertCanTransition(DiningTicket $ticket, string $targetStatus): void
hasActivePrimaryTicket(DiningTable $table): bool
```

`openTicket` behavior:

1. Resolve and lock the dining table row.
2. Confirm the actor can access the table branch.
3. Confirm the service area is active.
4. Confirm the table is active and not soft-deleted.
5. Confirm `operational_state = available`.
6. Confirm the terminal exists and belongs to the same tenant and branch.
7. Confirm the request is online/server-side. Do not create an offline queue path.
8. If `client_request_uuid` already exists for the same tenant and branch with the same material request payload, return the original ticket without creating a duplicate audit or mapping row.
9. If `client_request_uuid` already exists with a materially different request payload, reject with `409 IDEMPOTENCY_DRIFT`.
10. Confirm no active primary ticket exists for the table.
11. Generate `ticket_number` through `DiningTicketNumberService`.
12. Create `dining_tickets` with status `open`.
13. Set `guest_count` from request or default to `1`.
14. Set all total snapshot centavos to `0`.
15. Set `ticket_revision = 1`.
16. Set `opened_by`, `opened_at`, `terminal_id`, and `client_request_uuid`.
17. Store the idempotency request fingerprint.
18. Create a `dining_ticket_tables` row with `role = primary`, `attached_at = now()`, `detached_at = null`.
19. Write formal audit entry `DINING_TICKET_OPENED`.
20. Optionally write an initial timeline event if the lightweight table exists in this story.
21. Return the ticket with primary table mapping loaded.

`transitionStatus` behavior:

1. Lock the ticket row.
2. Validate tenant and branch scope.
3. Authorize the transition independently from state validation.
4. If `expectedRevision` is provided, reject stale requests with `409`.
5. Validate legal transition.
6. Apply status change.
7. Increment `ticket_revision`.
8. Set `closed_at` only when target status is `closed`.
9. Do not change the table `operational_state` when closing or voiding the ticket.
10. Write audit entry with before/after status and revision.
11. Commit the transaction.
12. Return the refreshed ticket.

All future status transition implementations must follow this pipeline:

```text
lock ticket
validate scope
authorize transition
check expected revision when provided
assertCanTransition
change state
increment revision
write audit/timeline records required by the story
commit
return refreshed aggregate
```

### Replace 38.1 placeholder guards

`DiningLayoutService` currently has placeholder methods for ticket references. Story 38.2 must replace them with real checks:

```text
serviceAreaHasActiveTicket(ServiceArea $area)
tableHasActiveTicket(DiningTable $table)
tableHasHistoricalReference(DiningTable $table)
```

Required behavior:

1. A service area cannot be deactivated if any contained dining table has an active primary ticket.
2. A dining table cannot be deactivated if it has an active primary ticket.
3. A dining table cannot be physically deleted if it has any `dining_ticket_tables` history.
4. Closed or voided historical tickets should block hard delete but should not block future table reuse.

## API Contracts

### Open dining ticket

Recommended route:

```text
POST /pos/dining/tickets
```

Recommended route name:

```text
pos.dining.tickets.store
```

Required middleware:

```text
auth
tenant
branch
terminal
permission:create_sale
subscription.feature:sales.pos
timecard.clocked_in
```

Request body:

```json
{
  "dining_table_id": "uuid",
  "client_request_uuid": "uuid",
  "guest_count": 2,
  "reservation_id": null,
  "notes": "Guest prefers window side"
}
```

Validation:

```text
dining_table_id required UUID visible in current tenant and branch
client_request_uuid required UUID
guest_count nullable integer min 1 max 999
reservation_id nullable UUID
notes nullable string max 2000
```

Successful response: `201`

```json
{
  "dining_ticket": {
    "id": "uuid",
    "ticket_number": "DT-20260714-000001",
    "status": "open",
    "guest_count": 2,
    "ticket_revision": 1,
    "subtotal_centavos": 0,
    "discount_centavos": 0,
    "service_charge_centavos": 0,
    "tax_centavos": 0,
    "grand_total_centavos": 0,
    "opened_at": "2026-07-14T09:00:00+08:00",
    "primary_table": {
      "id": "uuid",
      "table_number": "T1",
      "service_area_id": "uuid"
    }
  }
}
```

Domain conflict responses: `409`

Duplicate active primary ticket:

```json
{
  "code": "DINING_TABLE_ALREADY_HAS_ACTIVE_TICKET",
  "message": "This table already has an active dining ticket.",
  "dining_table_id": "uuid",
  "active_ticket_id": "uuid"
}
```

Idempotent retry with the same `client_request_uuid`: `200`

```json
{
  "dining_ticket": {
    "id": "uuid",
    "ticket_number": "DT-20260714-000001",
    "status": "open",
    "guest_count": 2,
    "ticket_revision": 1,
    "idempotent_replay": true
  }
}
```

Idempotency drift with the same `client_request_uuid`: `409`

```json
{
  "code": "IDEMPOTENCY_DRIFT",
  "message": "This request UUID was already used with different dining ticket details."
}
```

Inactive service area:

```json
{
  "code": "SERVICE_AREA_INACTIVE",
  "message": "Dining tickets cannot be opened in an inactive service area."
}
```

Inactive table:

```json
{
  "code": "DINING_TABLE_INACTIVE",
  "message": "Dining tickets cannot be opened for an inactive table."
}
```

Unavailable operational state:

```json
{
  "code": "DINING_TABLE_NOT_AVAILABLE",
  "message": "Only available tables can open a dining ticket.",
  "operational_state": "reserved"
}
```

Stale revision response for future status mutations: `409`

```json
{
  "code": "DINING_TICKET_REVISION_CONFLICT",
  "message": "The dining ticket was updated by another user.",
  "current_ticket_revision": 3
}
```

Recommended response codes:

| Condition | HTTP status |
| --- | ---: |
| Successful open | `201` |
| Idempotent replay for same open request | `200` |
| Same idempotency key with different payload | `409` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant or cross-branch hidden resource | `404` |
| Missing or invalid terminal context | `403` |
| No active timecard/shift | `403` |
| Duplicate active primary ticket | `409` |
| Inactive service area/table | `409` |
| Table not operationally available | `409` |
| Stale ticket revision | `409` |
| Illegal status transition | `422` |

## Authorization and Scoping

1. Opening a dining ticket requires `create_sale`.
2. The actor must be assigned to the current branch through existing branch access rules.
3. The dining table must belong to the active tenant and branch context.
4. The terminal profile must belong to the active tenant and branch context.
5. Cross-tenant and cross-branch table IDs must return `404`, not `403`.
6. Admin layout mutation guards should continue using `pos-layouts.manage`.

## Online-Only and Terminal Requirements

1. The open-ticket endpoint must not be callable without terminal middleware.
2. The endpoint must not create or enqueue offline dining mutations.
3. If the browser is offline, frontend code in later stories must block before request; backend remains the final authority.
4. If terminal context is missing or invalid, the existing terminal middleware should return `403`.
5. The terminal id saved to `dining_tickets.terminal_id` must be the server-resolved terminal profile id, not a raw untrusted request body value.

## Audit, Timeline, and Revision Behavior

Story 38.2 must include enough accountability to satisfy opening and mapping traceability while leaving full timeline/version infrastructure to Story 38.5.

Required:

1. Audit event `DINING_TICKET_OPENED`.
2. Audit payload includes `schema_version = 1`, tenant, branch, ticket, primary table, service area, terminal, guest count, opened by, and opened at.
3. `ticket_revision` starts at `1`.
4. Any implemented status mutation increments revision after a valid transition.
5. Duplicate/open failures must not create partial ticket, mapping, or audit rows.
6. Idempotent replays must not create duplicate audit rows.

Optional in this story:

1. Create a minimal `dining_ticket_events` table now if it materially reduces Story 38.5 migration churn.
2. Write initial `opened` event with compact payload.

If optional timeline storage is not implemented in Story 38.2, Story 38.5 remains responsible for adding it and backfilling no historical events.

## UI Notes

1. No full POS floor-map UI is required in this story.
2. No seat assignment UI is required.
3. No ticket item UI is required.
4. Existing admin service-area UI should receive real conflict responses when active or historical ticket references block deactivation/delete.
5. If a minimal cashier entry point is added for manual testability, it must be hidden behind existing POS terminal context and must not imply floor-map readiness.

## Test Cases

### Migration and model tests

1. Creates `dining_tickets`, `dining_ticket_tables`, and `dining_ticket_items`.
2. Models use UUID keys and tenant conventions.
3. Relationships load ticket, primary table mapping, table, terminal, user, and items.
4. Reservation deletion cannot cascade-delete dining tickets if a reservation table exists.
5. Deleting a dining table with ticket mapping is restricted or blocked by service guard.

### Open ticket feature tests

1. Cashier with `create_sale`, active terminal, and active timecard can open a ticket for an active available table.
2. Response is `201` and includes ticket id, ticket number, status `open`, primary table, and `ticket_revision = 1`.
3. Ticket totals are all zero centavos on open.
4. Ticket stores `opened_by`, `opened_at`, branch, tenant, and server-resolved terminal id.
5. Primary `dining_ticket_tables` row is created with `role = primary` and `detached_at = null`.
6. Audit log `DINING_TICKET_OPENED` is created.
7. Opening another active primary ticket for the same table returns `409`.
8. Duplicate failure leaves only the original ticket and original mapping.
9. Retrying the same `client_request_uuid` returns the original ticket with `200` and does not create duplicate audit or mapping rows.
10. Reusing the same `client_request_uuid` with a materially different table, guest count, reservation, or notes returns `409 IDEMPOTENCY_DRIFT`.
11. Cross-branch table id returns `404`.
12. Inactive service area returns `409`.
13. Inactive table returns `409`.
14. Reserved table returns `409`.
15. Cleaning table returns `409`.
16. Missing terminal context returns `403`.
17. Missing active timecard/shift returns `403` if enforced by existing middleware.
18. User without `create_sale` returns `403`.
19. Audit payload includes `schema_version = 1`.

### Concurrency tests

1. Two open-ticket requests for the same table cannot both succeed.
2. If the first transaction opens the ticket, the second receives `409`.
3. Service-level locking prevents duplicate active primary rows even without a portable partial unique index.
4. Simultaneous retries with the same `client_request_uuid` result in one create, one replay, and one audit row.

### State transition tests

1. `open -> settling` succeeds and increments revision.
2. `open -> voided` succeeds and increments revision.
3. `settling -> closed` succeeds and sets `closed_at`.
4. `settling -> closed` does not change `dining_tables.operational_state`.
5. `settling -> open` succeeds only for cancellation/failure context when implemented.
6. `closed -> open` is rejected.
7. `voided -> open` is rejected.
8. Stale expected revision is rejected without status change.
9. Failed status mutations do not increment `ticket_revision`.

### Layout guard regression tests

1. Service area with active ticket cannot be deactivated.
2. Table with active ticket cannot be deactivated.
3. Table with any historical ticket mapping cannot be physically deleted.
4. Closed/voided historical mapping does not block opening a new ticket for the same active available table.

## Rollout Plan

1. Ship migrations and backend service behind the normal POS authentication, branch, terminal, permission, subscription, and timecard gates.
2. Run migration in CI and staging.
3. Confirm existing Story 38.1 service-area tests still pass.
4. Confirm direct POS checkout and offline cash-sale flows are unaffected.
5. Pilot by opening a ticket through API/test harness only until Story 38.3 floor map exists.

## Rollback Considerations

1. This story adds new tables and relationships but should not mutate existing sale records.
2. Rollback drops `dining_ticket_items`, then `dining_ticket_tables`, then `dining_tickets`.
3. Rollback must not drop or modify `service_areas` or `dining_tables`.
4. If any live pilot dining tickets exist, rollback requires operational signoff because ticket state would be lost.

## Implementation Checklist

1. Add migrations for `dining_tickets`, `dining_ticket_tables`, and `dining_ticket_items`.
2. Add models, constants, casts, factories, and relationships.
3. Add request validator for opening dining tickets.
4. Add `DiningTicketService`.
5. Add `DiningTicketNumberService`.
6. Add open-ticket `client_request_uuid` idempotency.
7. Add POS JSON controller for opening dining tickets.
8. Add route with terminal, branch, permission, subscription, and timecard middleware.
9. Replace active-ticket/historical-reference placeholders in `DiningLayoutService`.
10. Add versioned audit payload for opening ticket.
11. Add feature tests for open-ticket flow and conflicts.
12. Add idempotency replay tests.
13. Add idempotency drift tests.
14. Add concurrency or transaction tests for duplicate primary ticket prevention and simultaneous idempotent retries.
15. Add state transition unit/feature tests.
16. Add layout guard regression tests.
17. Run targeted dining tests.
18. Run full Laravel test suite if feasible.
19. Run frontend build only if touched frontend assets require it. No frontend build should be required for a backend-only implementation.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. New migrations migrate and roll back cleanly.
4. Ticket opening is tenant, branch, terminal, permission, and active-timecard scoped.
5. Duplicate active primary tickets are blocked.
6. Ticket revision initializes and increments according to implemented mutations.
7. Illegal ticket status transitions are rejected.
8. Service-area/table delete and deactivation guards use real dining ticket references.
9. Open-ticket idempotency is verified.
10. Idempotency drift is rejected with `409 IDEMPOTENCY_DRIFT`.
11. Audit entry for ticket opening is verified with `schema_version = 1`.
12. No offline dining mutation path is introduced.
13. Existing direct POS checkout path remains unchanged.
14. No architecture constraints are violated.
15. Documentation is updated if implementation names differ from this spec.

## Next Story Hook

Story 38.5 extends this foundation with durable dining operation audit helpers, timeline events, and revision history. It should not change the aggregate ownership boundary introduced here. Future domain events such as `DiningTicketOpened` must be published after successful transaction commit and must not alter aggregate ownership.

## Review Notes

This specification is intentionally narrower than the Epic 38 architecture lock. It only prepares the dining aggregate foundation and the first server mutation. The main implementation risks are:

1. Duplicate active primary tickets under concurrent requests.
2. Accidentally treating `dining_tables.operational_state` as occupancy.
3. Letting layout delete/deactivation placeholders remain false negatives.
4. Adding a second checkout or payment path too early.
5. Creating an offline queue path for dining mutations.

The implementation should be reviewed especially carefully around those five points.
