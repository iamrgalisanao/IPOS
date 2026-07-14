# Epic 38 F&B Table and Bill Operations Planning Lock

## 1. Status

Architecture Accepted for Implementation Planning

Date: 2026-07-14

No product code is approved by this document yet. This plan is the architectural contract for Epic 38 implementation and should be used to create/review the story implementation slices.

## 2. Purpose

Epic 38 introduces dine-in F&B operations on top of the existing IPOS POS foundation:

1. Visual service-area and table layouts.
2. Active dining tickets linked to one or more tables.
3. Seat/item assignment.
4. Move, merge, split, and checkout workflows.
5. Strict online-only enforcement for shared dining state mutations.

The goal is to support restaurant floor operations without weakening the existing POS guarantees around tenant isolation, branch scoping, payment recording, statutory discounts, commercial promotions, shift accountability, receipt/Z-read reporting, and offline safety.

## 3. Architecture Principles

1. Sales remain immutable after checkout.
2. Dining tickets remain mutable operational state before checkout.
3. Existing `SaleCreationService` remains the checkout authority.
4. Dining services coordinate but never own payment posting.
5. Money calculations use integer centavos.
6. Promotion allocations are preserved and never recalculated on split child tickets.
7. Offline dining mutations are prohibited.
8. Restricted dining operations use the existing approval/override framework.

## 4. Current Baseline

Relevant existing foundations:

1. POS checkout and sale posting already flow through `SaleCreationService`.
2. Split payment recording already exists through `/pos/sales/{sale_id}/payments/split`.
3. Offline payment and sale queueing already exists for direct cash-sale workflows.
4. Local sync table/cart locks already exist through `LocalSyncService` and `LocalSyncController`.
5. Promotion snapshots and promotion reversal behavior are now available through Epic 37.
6. Statutory discounts are handled as a separate post-eligibility discount concern.
7. Shift and drawer workflows already bind payments to active cashier shifts.

Implication:

Epic 38 should not create a second checkout/payment system. Dining tickets should stage mutable table/order state, then convert to the existing sale/payment flow at checkout.

## 5. Architectural Decisions for Review

### 5.1 Naming Decision

The roadmap uses a generic `tables` table name. The implementation should use:

```text
dining_tables
```

Reason:

1. Avoids an overly generic model/table name in Laravel.
2. Keeps F&B table concepts clearly separate from database tables and future non-F&B resource tables.
3. Makes routes, models, factories, tests, and policy names clearer.

### 5.2 Source of Truth

Dining tickets are mutable operational state before checkout.

Sales remain immutable commercial/compliance records after checkout.

Therefore:

1. `dining_tickets` and `dining_ticket_items` may change while open.
2. `sales`, `sale_items`, `sale_promotions`, `sale_payments`, Z-read, receipts, and audit/compliance records remain the source of truth after checkout.
3. A closed dining ticket links to `source_sale_id`.

### 5.3 Online-Only Mutations

All dining ticket mutations must require online/server access:

1. Open table ticket.
2. Add/remove/move ticket items.
3. Assign seats.
4. Move tables.
5. Merge tickets.
6. Split bills.
7. Record partial ticket payments.

Offline mode may show cached floor layout/status only. Direct walk-in cash sale remains allowed through the existing offline-sale path.

### 5.4 Split Discount Rule

Commercial promotions must not be recalculated independently on child split tickets.

Instead:

1. Promotion allocations move with the split source items.
2. Any centavo remainder is assigned deterministically to the final child ticket.
3. Statutory discounts are blocked on parent dining tickets before split completion and must be applied only after the final payer/ticket is established.

### 5.5 Lifecycle and Future Kitchen Readiness

Kitchen Display System execution remains out of scope for Epic 38, but the dining data model should not require a migration redesign when kitchen routing is introduced.

Therefore:

1. Dining ticket status should represent the high-level ticket lifecycle.
2. Dining ticket item status should represent item/kitchen progress.
3. Ticket items should include course/fire sequencing fields even if Epic 38 only stores and displays them.
4. Product-to-kitchen-station routing should be treated as a future integration point, not implemented as part of the first Epic 38 slice.

### 5.6 Ticket Revision and Timeline

Every dining ticket mutation should increment a server-side revision number.

This supports:

1. Optimistic concurrency in the POS UI.
2. Safer multi-terminal editing.
3. Clear rejection of stale mutation requests.
4. Easier sync diagnostics.

Dining tickets should also emit lightweight timeline events separate from compliance/audit logs. Audit logs remain the formal accountability record; timeline events power cashier, manager, and support views such as opened, guest count changed, moved, split, payment failed, and closed.

### 5.7 Ticket State Machine

Ticket status transitions must be explicit and validated by service methods.

Ticket status values:

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

The `settling -> open` transition is only allowed when payment/checkout fails or is cancelled before a sale is finalized.

Illegal transitions must return validation errors. Examples:

```text
closed -> open
closed -> settling
voided -> open
voided -> settling
```

Item/kitchen progress should be represented on `dining_ticket_items.status`, not on `dining_tickets.status`.

Item status values:

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

Valid item exceptions:

```text
open -> voided
confirmed -> voided
open -> moved
confirmed -> moved
```

Later KDS stories may add station-specific transitions, but Epic 38 should reject backwards transitions such as:

```text
ready -> preparing
served -> open
voided -> ready
```

## 6. Target Data Model

### 6.1 `service_areas`

Purpose:

Branch-scoped layout containers such as Dining Room, Patio, Bar, Function Room.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `name`
5. `layout_metadata`
6. `is_active`
7. timestamps

Constraints:

1. Tenant/branch scoped.
2. `name` unique per branch among active areas where practical.
3. Layout metadata validates bounded coordinates, dimensions, and optional labels.

### 6.2 `dining_tables`

Purpose:

Physical or logical tables rendered in a service area.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `service_area_id`
5. `table_number`
6. `capacity`
7. `status`
8. `position_metadata`
9. `is_active`
10. timestamps

Status values:

```text
vacant
occupied
reserved
cleaning
inactive
```

Constraints:

1. Unique `table_number` per service area.
2. Status may be derived by resolver in early slices instead of manually persisted in every operation.
3. Do not allow deletion if linked to active dining tickets.

### 6.3 `dining_tickets`

Purpose:

Mutable dine-in order/check state before official sale checkout.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `ticket_number`
5. `status`
6. `guest_count`
7. `subtotal_centavos`
8. `discount_centavos`
9. `service_charge_centavos`
10. `tax_centavos`
11. `grand_total_centavos`
12. `opened_by`
13. `opened_at`
14. `closed_at`
15. `parent_ticket_id`
16. `source_sale_id`
17. `terminal_id`
18. `ticket_revision`
19. `reservation_id`
20. `checkout_request_uuid`
21. `pricing_engine_version`
22. `tax_engine_version`
23. `discount_engine_version`
24. `notes`
25. timestamps

Status values:

```text
open
settling
closed
voided
```

Constraints:

1. Active ticket operations are tenant/branch scoped.
2. Tickets cannot be edited after `closed` or `voided`.
3. A table cannot have two active primary tickets unless explicitly configured later for shared-table mode.
4. Every successful mutation increments `ticket_revision`.
5. `reservation_id` is nullable and reserved for future reservation integration.
6. Reservation foreign key behavior should be `SET NULL`, not cascade delete.
7. `guest_count` stores the current value; history comes from `guest_count_changed` timeline events.
8. Total fields are derived caches and must be recalculated from ticket items/allocation snapshots during mutation.
9. `checkout_request_uuid` supports idempotent checkout and duplicate-submit protection.
10. Engine version fields preserve reproducibility if pricing, tax, or discount engines change later.

### 6.4 `dining_ticket_tables`

Purpose:

Join model between tickets and physical tables.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `dining_ticket_id`
5. `dining_table_id`
6. `role`
7. `attached_at`
8. `detached_at`

Role values:

```text
primary
joined
moved_from
moved_to
```

Constraints:

1. Only one active primary ticket per table.
2. Historical detached rows remain for traceability.

### 6.5 `dining_ticket_items`

Purpose:

Mutable order item rows before conversion to `sale_items`.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `dining_ticket_id`
5. `product_id`
6. `seat_number`
7. `quantity`
8. `unit_price_centavos`
9. `line_total_centavos`
10. `status`
11. `source_item_id`
12. `course_no`
13. `fire_group`
14. `hold_until`
15. `preparation_station_id`
16. `promotion_allocation_snapshot`
17. timestamps

Status values:

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

Constraints:

1. Quantity must be positive for active rows.
2. Item movement creates new rows linked by `source_item_id`; original rows should not disappear.
3. Product pricing snapshots are copied when the item is added.
4. Course/fire fields are optional in the first slice but must be validated when present.
5. `preparation_station_id` is nullable and reserved for future kitchen routing.
6. `promotion_allocation_snapshot` should include `promotion_snapshot_version` for future promotion-engine reproducibility.

### 6.6 `bill_split_allocations`

Purpose:

Immutable allocation ledger linking parent ticket items to child tickets.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `parent_ticket_id`
5. `child_ticket_id`
6. `source_ticket_item_id`
7. `allocated_quantity`
8. `allocated_amount_centavos`
9. `promotion_discount_centavos`
10. `rounding_adjustment_centavos`
11. `created_by`
12. `created_at`

Constraints:

1. Sum of child allocations equals parent ticket total exactly.
2. Sum of promotion allocations equals source promotion allocation exactly.
3. Rounding remainder goes to the final child ticket deterministically.
4. Split ledger rows are immutable.

### 6.7 `dining_ticket_versions`

Purpose:

Lightweight revision history for ticket concurrency and support diagnostics.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `dining_ticket_id`
5. `version`
6. `created_by`
7. `created_at`

Constraints:

1. Version numbers increase monotonically per ticket.
2. Version records are append-only.
3. Mutations must compare the expected revision when the UI sends one.

### 6.8 `dining_ticket_events`

Purpose:

Operational timeline for managers, cashiers, and support. This does not replace compliance/audit logs.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `dining_ticket_id`
5. `event_uuid`
6. `event_sequence`
7. `event_type`
8. `summary`
9. `payload`
10. `created_by`
11. `created_at`

Example event types:

```text
opened
guest_count_changed
item_added
seat_assigned
table_moved
ticket_merged
bill_split_created
payment_started
payment_failed
ticket_closed
```

Constraints:

1. Timeline events are append-only.
2. Payloads should be compact and UI-friendly.
3. Sensitive payment detail must stay in the existing payment records, not duplicated here.
4. `event_uuid` provides external/support reference stability.
5. `event_sequence` increases monotonically per ticket for readable timelines.

### 6.9 Future Kitchen Routing Tables

Epic 38 should not implement Kitchen Display System dispatch, but it should leave room for future routing tables such as:

1. `kitchen_stations`
2. `product_kitchen_station_assignments`
3. `kitchen_order_tickets`

These remain future expansion items unless a later story explicitly pulls them into scope.

## 7. Service Boundaries

### 7.1 Aggregate Boundary

`DiningTicket` is the aggregate root for dine-in operational state.

It owns:

1. Ticket items.
2. Seat assignments.
3. Table attachments.
4. Split allocations.
5. Revision records.
6. Timeline events.

Nothing outside the aggregate should mutate these records directly. Every structural change must go through dining ticket services so tenant/branch scope, state machines, revisions, audit, timeline, locks, approvals, and totals snapshots stay consistent.

### 7.2 Admin Layout Services

Planned services:

1. `ServiceAreaLayoutService`
2. `DiningTableLayoutService`
3. `DiningLayoutValidationService`

Responsibilities:

1. Create/update service areas and table layout metadata.
2. Validate layout bounds.
3. Prevent unsafe deletes or deactivation with active tickets.

### 7.3 Ticket Operation Services

Planned services:

1. `DiningTicketService`
2. `DiningTicketItemService`
3. `DiningTableStatusResolver`
4. `DiningOperationAuditService`
5. `DiningTicketTimelineService`
6. `DiningTicketRevisionService`

Responsibilities:

1. Open ticket.
2. Attach/detach/move/join tables.
3. Add/move/void ticket items.
4. Resolve active table status.
5. Emit audit events for every structural operation.
6. Increment ticket revision on every successful mutation.
7. Emit timeline events for manager/support readability.

### 7.4 Split and Checkout Services

Planned services:

1. `BillSplitAllocatorService`
2. `DiningTicketCheckoutService`
3. `DiningTicketPaymentCoordinator`

Responsibilities:

1. Split by seat, item, or custom quantity.
2. Preserve centavos balance exactly.
3. Preserve promotion allocation balance exactly.
4. Convert a dining ticket or split child ticket into the existing sale creation flow.
5. Enforce checkout idempotency with `checkout_request_uuid`.
6. Defer to existing split payment recording for tenders.

Boundary:

These services coordinate dining ticket closure only. They must not duplicate `SaleCreationService`, payment posting, receipt issuance, inventory deduction, or Z-read/reporting behavior.

### 7.5 Approval and Override Integration

Restricted dining operations should reuse the existing approval/override framework instead of embedding manager checks directly in dining services.

Proposed approval contexts:

1. `TABLE_MERGE`
2. `TABLE_MOVE`
3. `VOID_AFTER_SENT`
4. `FORCE_CLOSE`
5. `REMOVE_PAYMENT`
6. `REOPEN_TICKET`

Approval payloads should include tenant, branch, terminal, user, ticket, table, reason, and before/after references where applicable.

Approvals should return short-lived approval tokens. Tokens must be single-use or operation-scoped, expire after the configured window, and be recorded in audit/timeline payloads when consumed.

### 7.6 Domain Events

Dining services should publish domain events after successful transactional mutations.

Initial event names:

1. `DiningTicketOpened`
2. `DiningItemAdded`
3. `DiningItemVoided`
4. `DiningTableMoved`
5. `DiningBillSplit`
6. `DiningCheckoutStarted`
7. `DiningCheckoutCompleted`
8. `DiningCheckoutFailed`

Purpose:

1. Keep future kitchen, analytics, notification, customer-display, and reporting integrations decoupled.
2. Avoid direct service-to-service calls from dining operations into future modules.
3. Allow projections/read models to update consistently after mutations.

Domain events are not a replacement for audit logs or timeline events. They are integration signals emitted after the aggregate mutation succeeds.

### 7.7 Business Rule Classes

Complex dining rules should be expressed as focused rule classes instead of being buried inside services.

Initial rule candidates:

1. `CanSplitTicketRule`
2. `CanMoveTableRule`
3. `CanCheckoutRule`
4. `CanMergeRule`
5. `CanApplyDiscountRule`
6. `CanVoidDiningItemRule`

This keeps service methods smaller and makes edge cases easier to test directly.

### 7.8 Read Models

Epic 38 should allow CQRS-lite read models for expensive floor, ticket, kitchen, and payment views.

Initial read model candidates:

1. `DiningFloorReadModel`
2. `TicketSummaryReadModel`
3. `KitchenReadModel`
4. `PaymentStatusReadModel`

These are query projections, not event sourcing. They may be introduced incrementally when direct aggregate queries become expensive or UI-specific.

## 8. Route and Surface Plan

### 8.1 Admin Routes

Proposed route group:

```text
/admin/service-areas
/admin/service-areas/{serviceArea}/tables
```

Purpose:

1. Manage service areas.
2. Configure floor plan coordinates and table metadata.
3. Activate/deactivate tables.

Permissions:

```text
manage_pos_layouts
```

or a new permission if review prefers:

```text
manage_dining_layouts
```

### 8.2 POS Terminal Routes

Proposed route group:

```text
/pos/dining
/pos/dining/service-areas
/pos/dining/tickets
/pos/dining/tickets/{ticket}
/pos/dining/tickets/{ticket}/items
/pos/dining/tickets/{ticket}/split
/pos/dining/tickets/{ticket}/checkout
```

Permissions:

```text
create_sale
open_shift
```

Manager overrides may later use existing manager authorization patterns for restricted actions.

### 8.3 UI Surfaces

Admin:

1. Service area list.
2. Service area editor.
3. Table position/capacity/status configuration.

POS:

1. Floor map view.
2. Active ticket drawer/panel.
3. Table action menu.
4. Seat assignment panel.
5. Bill split wizard.
6. Checkout handoff to existing payment wizard.

## 9. Story Implementation Guide

Detailed implementation slices have been moved to:

```text
docs/implementation-plans/epic-38/epic-38-implementation-guide.md
```

The architecture lock remains the controlling contract. The story guide may evolve during delivery, but it must not violate the architectural constraints in this document.

## 10. Test Strategy

### 10.1 Backend Feature Tests

Required coverage:

1. Tenant/branch isolation for all dining resources.
2. Admin layout CRUD permissions.
3. Duplicate active ticket prevention.
4. Table status resolver.
5. Ticket item snapshots.
6. Seat movement and item movement audit.
7. Bill split centavos balance.
8. Promotion allocation split balance.
9. Statutory discount split block.
10. Checkout handoff does not bypass sale/payment guards.
11. Offline dining mutation endpoints fail closed.
12. Ticket revision increments and stale revision rejection.
13. Timeline events for open, guest change, move, split, payment failure, and close.
14. Transactional row-lock behavior for move, merge, split, and checkout.
15. Course/fire fields validate when present and remain optional when KDS is disabled.
16. Ticket and item state machines reject illegal transitions.
17. Checkout idempotency prevents duplicate sale creation.
18. Timeline events include stable UUIDs and readable per-ticket sequence numbers.
19. Ticket totals snapshots recalculate after add, void, move, split, and payment failure.
20. Restricted operations request approval through the shared approval framework.
21. Approval tokens are single-use or operation-scoped and expire correctly.
22. Domain events publish only after successful transactions.
23. Business rule classes reject split, move, merge, checkout, discount, and void edge cases.
24. Recovery tests cover interrupted checkout and `settling` ticket resume behavior.
25. Version stamps persist pricing, tax, discount, and promotion snapshot versions.

### 10.2 Frontend Tests

Required coverage:

1. Floor map status rendering.
2. Offline read-only state.
3. Bill split wizard allocation math.
4. Split validation error states.
5. Payment handoff success/failure states.
6. Stale ticket revision refresh prompt.
7. Timeline display for manager/support context when surfaced.

### 10.3 Manual UAT

Scenarios:

1. Admin creates floor layout.
2. Cashier opens a table.
3. Cashier adds items and seat numbers.
4. Cashier moves a party to another table.
5. Cashier merges two tables.
6. Cashier splits by seat.
7. Cashier pays one split child ticket.
8. Cashier completes all child tickets.
9. Terminal goes offline and dining mutation buttons are disabled.

## 11. Risks and Guardrails

### 11.1 Double Checkout Risk

Guardrail:

Ticket checkout must use transaction locks and status transitions:

```text
open -> settling -> closed
```

Only one active checkout transaction may settle a ticket.

Critical mutation paths must use database row locking within transactions, especially:

1. Move table.
2. Merge ticket.
3. Split bill.
4. Checkout.

The expected implementation pattern is a tenant/branch-scoped locked read of the active ticket row before mutation.

### 11.1.1 Stale Terminal Mutation Risk

Guardrail:

The POS UI should send the last known `ticket_revision` with mutation requests where practical. The backend rejects stale revisions and returns the latest ticket state for refresh.

### 11.1.2 Duplicate Checkout Request Risk

Guardrail:

Dining checkout must require or generate a `checkout_request_uuid`. Repeating checkout with the same UUID must return the original sale/payment result instead of creating a second sale, receipt, or payment record.

### 11.2 Split Imbalance Risk

Guardrail:

All split math uses integer centavos. Decimal money math is not allowed in allocator internals.

### 11.3 Promotion Recalculation Risk

Guardrail:

Promotion allocations are copied/moved from parent item snapshots. Child tickets do not independently rerun the promotion engine for already-split parent items.

### 11.4 Statutory Discount Basis Risk

Guardrail:

Parent-ticket statutory discounts are blocked before split. Statutory discount eligibility is evaluated only after the final payer/check is known.

### 11.5 Offline Conflict Risk

Guardrail:

All mutations require online state. Existing direct offline cash sale path remains separate and must not create dining tickets.

### 11.6 Local Lock Confusion

Guardrail:

Existing `LocalSyncService` table lock names should either be migrated to dining terminology or wrapped by dining-specific services. Avoid exposing raw lock APIs as business authority.

Local lock records may improve cashier UX, but database row locking remains the authoritative concurrency guard for merge, split, move, and checkout.

Lock ownership metadata should be visible to support and manager workflows where practical:

1. Locked by terminal.
2. Locked by cashier/user.
3. Locked at timestamp.
4. Lock reason.
5. Lock expiry.

### 11.7 Approval Bypass Risk

Guardrail:

Manager-only dining actions must go through the shared approval framework. Dining services should request approval context decisions instead of embedding ad hoc manager-role checks.

### 11.8 Recovery and Resume Risk

Guardrail:

Epic 38 must define safe recovery behavior for interrupted operations:

1. Server restart leaves `open` tickets open.
2. `settling` tickets are detected on restart or next access.
3. Incomplete checkout attempts use `checkout_request_uuid` to resume or return the prior result.
4. Payment timeout with unknown gateway result must not create duplicate sales or receipts.
5. Support views should expose enough ticket, lock, revision, and timeline context to recover safely.

### 11.9 Analytics Readiness

Guardrail:

The model should preserve enough timestamps, guest counts, table links, totals, and service-area context to derive manager KPIs later:

1. Table turn time.
2. Open time.
3. Seat time.
4. Average dining duration.
5. Revenue per guest.
6. Revenue per seat.
7. Revenue per table.
8. Revenue per service area.

## 12. Implementation Order

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

## 13. Explicit Non-Goals

Epic 38 does not implement:

1. Kitchen Display System dispatch, kitchen queue screens, or prep station routing execution.
2. QR customer ordering.
3. Loyalty or store credit redemption.
4. Offline dining ticket mutation replay.
5. A new payment engine.
6. A new receipt/Z-read compliance path.
7. Recalculation of commercial promotions on split child tickets.
8. Statutory discount application on unsplit parent dining tickets.

Epic 38 may still reserve fields for course/fire sequencing and future preparation station assignment.

## 14. Locked Review Decisions

1. Use `dining_tables`, not generic `tables`.
2. Reuse `manage_pos_layouts` for dining layout management unless separate dining administrators are introduced later.
3. Defer table merge until item movement exists.
4. Wrap `LocalTableLock` in a dining-specific abstraction; do not expose the generic lock as business authority.
5. Allow partial payment on split child tickets only. Keep the parent as an orchestration container.
6. Cleaning/reserved states are manager or host controlled; resolver derives only occupied/vacant.
7. First UAT target is cafe counter-service with simple table assignment.
8. Ticket status stays limited to `open`, `settling`, `closed`, and `voided`; item status owns kitchen progress.
9. Timeline is manager/support visible first; cashier visibility can be added later if operationally useful.
10. Add `preparation_station_id` now as nullable.
11. Accept client-generated `checkout_request_uuid`, validate server-side, and generate one only if missing.
12. First approval contexts are `TABLE_MERGE`, `TABLE_MOVE`, `VOID_AFTER_SENT`, `FORCE_CLOSE`, `REMOVE_PAYMENT`, and `REOPEN_TICKET`.

## 15. Remaining Confirmation Before Implementation

1. Layout metadata minimum schema.
2. Whether POS layout tooling should share components with dining layout tooling.
3. Exact inactive/deleted table behavior for historical references.
4. Approval token expiry window.
5. Whether domain events and read models start in 38.2/38.5 or remain extension points until later slices.

## 16. Definition of Ready for Story 38.1

Story 38.1 is ready to implement after the remaining layout-specific confirmations are closed:

1. Layout metadata minimum schema.
2. Whether POS layout tooling should share components with dining layout tooling.
3. Exact inactive/deleted table behavior for historical references.

The broader architectural decisions for ticket lifecycle, revisions, timeline, state machines, checkout idempotency, approval contexts, totals snapshots, aggregate boundary, domain events, rule classes, recovery, and analytics readiness are locked by this plan.

## 17. Architecture Constraints

The following architectural constraints may not be violated by future stories unless this document is formally revised through architectural review:

1. Sales remain immutable.
2. `SaleCreationService` remains the sole checkout authority.
3. `DiningTicket` is the aggregate root.
4. Financial calculations use integer centavos.
5. Promotion allocations are never recalculated after split.
6. Timeline does not replace audit.
7. Audit does not replace domain events.
8. Domain events are integration notifications only.
9. Read models are query projections only.
10. Offline dining mutations remain prohibited.
11. Manager authorization always uses the shared approval framework.
12. New restaurant modules such as KDS, reservations, QR ordering, loyalty, handheld waiter devices, and customer display integrations must integrate through domain events or aggregate services and must not bypass `DiningTicketService`.
13. No story may introduce direct writes to dining ticket tables, ticket items, table attachments, split allocations, revisions, or timeline records outside the aggregate boundary.
14. No story may duplicate payment posting, receipt issuance, inventory deduction, Z-read reporting, or sale creation behavior already owned by the existing POS sale/payment flow.
15. Checkout must remain idempotent and protected against duplicate receipt or duplicate payment creation.
16. Restricted dining actions must consume auditable approval tokens when manager authorization is required.
