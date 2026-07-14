# Story 38.6: Bill Split Allocator Engine

## Status

Implemented - Pending Review

This story has a backend implementation ready for review. Application code was limited to the bill split allocation engine, ledger, API endpoints, parent mutation guard, and feature tests. The optional full cashier split organizer UI remains outside this implementation slice.

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.2.md`
4. `docs/implementation-plans/epic-38/stories/story-38.4.md`
5. `docs/implementation-plans/epic-38/stories/story-38.5.md`
6. `app/Models/DiningTicket.php`
7. `app/Models/DiningTicketItem.php`
8. `app/Services/Dining/DiningTicketItemService.php`
9. `app/Services/Dining/DiningOperationAuditService.php`
10. `app/Services/Dining/DiningTicketRevisionService.php`
11. `app/Services/Dining/DiningTicketTimelineService.php`

## Objective

Allow an active dining ticket to be split into child dining tickets by seat or by item quantity while preserving exact centavos totals, promotion allocation snapshots, revision safety, auditability, and checkout boundaries.

Story 38.6 creates the allocation engine and ledger that later Story 38.7 will use for child-ticket checkout. It must not capture payments, create sales, or apply statutory discounts.

## Dependencies

1. Story 38.2 dining ticket foundation.
2. Story 38.4 seat mapping and ticket item assignment.
3. Story 38.5 audit, timeline, and revision foundation.
4. Existing POS terminal context middleware.
5. Existing authorization roles for dining ticket mutations.
6. Existing product and item pricing snapshots.

## Out of Scope

1. Payment capture.
2. `SaleCreationService` integration.
3. Statutory discount application.
4. Partial payment allocation.
5. Table merge.
6. Split reversal or unsplit.
7. Kitchen routing changes.
8. Reservation changes.
9. Offline split mutation replay.
10. Promotion recalculation.
11. Manual price overrides.
12. New audit viewer or reporting screens.

## Locked Decisions

1. `DiningTicket` remains the aggregate root.
2. `BillSplitAllocatorService` is the sole service allowed to create split child tickets and split allocation ledger rows.
3. Controllers must not write `bill_split_allocations`, split child tickets, or copied child item rows directly.
4. Split operations require the parent ticket's last known `ticket_revision`.
5. A stale `ticket_revision` returns `409` and does not partially apply the split.
6. Split requests require `client_request_uuid` and must be idempotent.
7. Idempotency drift returns `409` and must not create additional child tickets or allocation rows.
8. Story 38.6 supports full-ticket partition splits only. Every active parent item quantity must be allocated exactly once across child tickets.
9. Parent item rows remain immutable source records after a split.
10. Child tickets receive copied item rows that reference the parent source item through `source_item_id`.
11. Child ticket item rows are checkout-ready snapshots, but checkout itself remains out of scope.
12. The parent ticket becomes a split orchestration container after a successful split.
13. Parent tickets with active split children are protected from further item mutations unless a future approved story implements reversal.
14. Parent tickets with active split children cannot be checked out directly.
15. Split child tickets are the only tickets eligible for partial payment in Story 38.7.
16. Ledger rows are immutable after creation.
17. Existing promotion allocation snapshots are preserved and allocated; they are never recalculated during split.
18. Rounding remainders are assigned deterministically to the final child allocation for each source item and total bucket.
19. Splitting a ticket with a pre-applied statutory discount is blocked.
20. All split child tickets, child item rows, allocation ledger rows, parent state changes, revision records, timeline events, and audit records commit in one database transaction.
21. Child ticket numbers must be issued through `DiningTicketNumberService`.
22. Child ticket numbers must not be derived from the parent ticket number unless a later product decision formally changes the numbering convention.
23. A parent ticket cannot gain additional split children after the first successful split.
24. Request group labels are presentation metadata only and must not influence numbering, financial calculations, ledger ordering, reconciliation, or audit identity.

## User Stories

1. As a cashier, I can split a table ticket by seat so each guest group can receive a separate child bill later.
2. As a cashier, I can split a table ticket by item quantity so shared or reassigned items can be divided before checkout.
3. As a cashier, I can safely retry a split request without creating duplicate child bills.
4. As a manager, I can see that a split occurred, who performed it, and how the parent totals were allocated.
5. As support, I can inspect allocation ledger rows to prove child ticket totals reconcile exactly to the parent ticket.

## Domain Model

### Parent Ticket

The parent ticket remains the canonical source of the original order and item history.

After a successful split:

1. Parent item rows remain unchanged.
2. Parent totals remain equal to the original active item total at split time.
3. Parent totals become historical reference totals and are no longer considered payable amounts.
4. Parent ticket status moves to `settling`.
5. Parent ticket is treated as a split orchestration container.
6. Parent ticket item mutations are blocked while active split children exist.
7. Parent ticket direct checkout is blocked by Story 38.7.
8. Parent ticket cannot receive additional child tickets after the first successful split.

### Child Tickets

Each split group creates one child `DiningTicket`.

Required child ticket behavior:

1. `parent_ticket_id` references the parent ticket.
2. `tenant_id`, `branch_id`, `dining_table_id`, `service_area_id`, `ticket_type`, and reservation context are copied from the parent.
3. `status` starts as `open`.
4. `ticket_number` is assigned by `DiningTicketNumberService`.
5. `ticket_revision` starts at the normal initial revision created by the ticket creation operation.
6. Child ticket creation establishes the first revision; allocation copying must not increment the child from initial revision to initial-plus-one.
7. Totals equal the allocated child item totals.
8. Child tickets can be rendered in POS read models as split bills.
9. Child tickets must not be detached from the parent.
10. Child tickets are otherwise governed by the same aggregate rules as ordinary dining tickets.
11. Child tickets differ from ordinary dining tickets only by parent reference, future checkout eligibility, and future reporting needs.

### Child Ticket Items

Each child ticket receives copied `DiningTicketItem` rows from allocated parent item portions.

Required child item behavior:

1. `source_item_id` references the parent source item.
2. Product, pricing, course, fire group, preparation station, and seat snapshots are copied from the parent source item.
3. `quantity` equals the allocated portion.
4. `line_total_centavos` equals the allocated line amount for that child portion.
5. `promotion_allocation_snapshot` equals the allocated promotion snapshot for that child portion.
6. Child item rows must not mutate parent item rows.

### Allocation Ledger

`bill_split_allocations` is the immutable reconciliation ledger between parent source items and child tickets.

Each allocation row represents one source item portion assigned to one child ticket.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `split_request_uuid`
5. `request_fingerprint`
6. `parent_ticket_id`
7. `child_ticket_id`
8. `child_ticket_item_id`
9. `source_ticket_item_id`
10. `allocation_method`
11. `allocation_sequence`
12. `allocated_quantity`
13. `allocated_amount_centavos`
14. `promotion_discount_centavos`
15. `rounding_adjustment_centavos`
16. `promotion_allocation_snapshot`
17. `created_by`
18. `created_at`

Allowed `allocation_method` values:

```text
seat
item_quantity
```

Required constraints:

1. Ledger rows are append-only.
2. `(tenant_id, branch_id, parent_ticket_id, split_request_uuid)` is indexed for idempotency replay lookup.
3. `(tenant_id, branch_id, child_ticket_item_id)` is unique to prevent duplicate ledger assignment for a child row.
4. `allocated_quantity` must be positive.
5. `allocated_amount_centavos` may be zero only if the source item amount is zero.
6. `rounding_adjustment_centavos` defaults to `0`.
7. Index parent ticket, child ticket, and source item lookups.
8. Allocation rows are written in deterministic allocation order.
9. `promotion_allocation_snapshot` must include a `promotion_snapshot_version` key even when its value is `null`.

## Split Rules

### Active Source Items

The source set is the parent ticket's active item rows as defined by the same predicate used for ticket totals.

Excluded source items:

1. `voided`
2. `moved`
3. Any status excluded by the shared active-for-totals scope.

The split request is invalid if the parent has no active source items.

### Full Partition Requirement

Story 38.6 requires every active source item quantity to be fully allocated.

For split by seat:

1. Every active item with a seat number must belong to exactly one submitted seat group.
2. Active items without a seat number must belong to exactly one submitted unassigned group or the request is rejected.
3. Seat groups must not overlap.

For split by item quantity:

1. Every active item must appear across one or more child groups.
2. The sum of allocated quantities for each source item must equal the source item quantity.
3. Allocated quantity precision must match the existing dining item quantity precision.
4. A source item cannot be overallocated or underallocated.

Partial split of only selected items is out of scope for this story.

### Promotion Allocation Preservation

Promotion allocation snapshots are moved proportionally from parent source items to child item portions.

Rules:

1. Do not call the promotion engine during split.
2. Do not recalculate eligibility, bundles, thresholds, or statutory discount amounts.
3. Use the source item `promotion_allocation_snapshot` as the only source of promotion data.
4. Allocate integer centavos from the source snapshot across child portions.
5. Preserve `promotion_snapshot_version`; include the key with `null` when no version is available.
6. The sum of child promotion allocations for each source item must equal the parent source item promotion allocation exactly.
7. If the source item has no promotion snapshot, child rows store `null` or the existing empty local convention.

### Statutory Discount Guard

Splitting is blocked if the parent ticket or any active source item indicates a pre-applied statutory discount.

Implementation should detect statutory discounts through explicit statutory discount metadata when available. If the current data cannot distinguish statutory discounts from other discount allocations, the implementation must fail closed and block the split rather than risk recalculating protected discounts.

Conflict response:

```json
{
  "code": "DINING_TICKET_STATUTORY_DISCOUNT_SPLIT_BLOCKED",
  "message": "Tickets with pre-applied statutory discounts cannot be split."
}
```

### Parent Mutation Guard

After a successful split, later item mutations against the parent ticket must return `409`.

This guard explicitly applies to:

1. Add item.
2. Quantity change.
3. Seat assignment.
4. Item move.
5. Item void.

Conflict response:

```json
{
  "code": "DINING_TICKET_ALREADY_SPLIT",
  "message": "This ticket has split child bills and can no longer be changed directly."
}
```

## Technical Approach

Create a `BillSplitAllocatorService` under the existing dining service namespace.

Primary service methods:

```text
splitBySeat(parentTicket, payload, actorContext)
splitByItemQuantity(parentTicket, payload, actorContext)
```

Introduce an immutable `BillSplitAllocationPlan` value object before persistence. It should contain:

1. Source ticket item reference.
2. Target child group reference.
3. Allocated quantity.
4. Allocated amount centavos.
5. Promotion allocation payload.
6. Rounding adjustment centavos.
7. Deterministic allocation sequence.

`BillSplitAllocatorService` should validate and persist the allocation plan rather than mutating database records while still calculating the split.

Recommended implementation flow:

1. Authorize the actor for dining split mutation.
2. Resolve the parent ticket using tenant and branch scope.
3. Validate terminal context.
4. Validate `expected_ticket_revision`.
5. Validate `client_request_uuid`.
6. Check idempotent replay or drift.
7. Lock the parent ticket row for update.
8. Lock active source item rows for update.
9. Reject stale revision after lock if the parent changed.
10. Reject statutory-discounted tickets.
11. Reject tickets already split.
12. Build the allocation plan in memory.
13. Verify full partition coverage.
14. Allocate item amounts and promotion snapshots using integer centavos.
15. Create child tickets.
16. Create child item rows.
17. Create immutable allocation ledger rows.
18. Recompute child ticket totals from child rows.
19. Mark the parent ticket as `settling`.
20. Increment and record revisions for parent and child tickets.
21. Append timeline events.
22. Write audit records.
23. Commit the transaction.
24. Return the parent and child split summary.

### Idempotency

Each split request requires `client_request_uuid`.

The request fingerprint must include only material business fields:

1. `tenant_id`
2. `branch_id`
3. `parent_ticket_id`
4. `allocation_method`
5. Submitted groups
6. Source item ids
7. Allocated quantities
8. Seat numbers
9. Expected parent revision used by the first accepted request

The fingerprint must exclude transport fields such as headers, request timestamps, and non-business metadata.

Replay behavior:

1. Same `client_request_uuid` and same fingerprint returns the original split result.
2. Same `client_request_uuid` and different fingerprint returns `409`.
3. Idempotent replay must not create child tickets, child item rows, allocation rows, revisions, timeline events, or audit records.

Drift response:

```json
{
  "code": "DINING_SPLIT_IDEMPOTENCY_DRIFT",
  "message": "The split request uuid was already used with different split details."
}
```

### Concurrency

Split is both optimistic and pessimistic:

1. The request must submit `expected_ticket_revision`.
2. The service must lock the parent ticket row before applying changes.
3. The service must lock active source item rows before building final allocations.
4. A stale revision returns `409`.
5. No child or ledger rows are created on conflict.

Revision conflict response:

```json
{
  "code": "DINING_TICKET_REVISION_CONFLICT",
  "message": "The ticket was updated by another user.",
  "current_ticket_revision": 7
}
```

### Rounding

All financial math uses integer centavos.

When a source item or promotion allocation cannot divide evenly across child portions:

1. Compute all non-final allocations by deterministic proportional floor.
2. Assign the remaining centavos to the final allocation for that source item.
3. Determine final allocation by submitted child group order, then source item id, then allocation sequence.
4. Store the applied remainder in `rounding_adjustment_centavos`.
5. Include rounding adjustments in audit and timeline payload summaries.
6. Persist allocation rows in the same deterministic order used for rounding.

This rule must produce the same result for the same request payload every time.

### Timeline and Audit

Parent ticket timeline event:

```text
bill_split_created
```

Child ticket timeline event:

```text
child_bill_created
```

Audit action:

```text
dining_ticket.bill_split_created
```

Audit payload should include:

1. Parent ticket id and number.
2. Split method.
3. Child ticket ids and ticket numbers.
4. Source item count.
5. Allocation row count.
6. Parent grand total centavos.
7. Sum child grand total centavos.
8. Sum promotion discount centavos.
9. Rounding adjustment total.
10. `client_request_uuid`.

Payloads must stay compact and avoid duplicating full item snapshots when ledger rows already contain the details.

## Database Migrations

Create `bill_split_allocations`.

Required columns:

```text
id
tenant_id
branch_id
split_request_uuid
request_fingerprint
parent_ticket_id
child_ticket_id
child_ticket_item_id
source_ticket_item_id
allocation_method
allocation_sequence
allocated_quantity
allocated_amount_centavos
promotion_discount_centavos
rounding_adjustment_centavos
promotion_allocation_snapshot
created_by
created_at
```

Recommended column details:

1. `split_request_uuid`: UUID or string matching existing idempotency conventions.
2. `request_fingerprint`: SHA-256 string.
3. `allocation_method`: constrained string enum.
4. `allocated_quantity`: same precision and scale as `dining_ticket_items.quantity`.
5. Centavos columns: signed integer where rounding adjustment may be negative if needed.
6. `promotion_allocation_snapshot`: nullable JSON.
7. No `updated_at` is required unless the project convention requires it; rows are immutable.

Indexes and constraints:

1. Index `(tenant_id, branch_id, parent_ticket_id, split_request_uuid)`.
2. Unique `(tenant_id, branch_id, child_ticket_item_id)`.
3. Index `(tenant_id, branch_id, parent_ticket_id)`.
4. Index `(tenant_id, branch_id, child_ticket_id)`.
5. Index `(tenant_id, branch_id, source_ticket_item_id)`.
6. Foreign key parent ticket to `dining_tickets`.
7. Foreign key child ticket to `dining_tickets`.
8. Foreign key child ticket item to `dining_ticket_items`.
9. Foreign key source ticket item to `dining_ticket_items`.
10. Foreign key `created_by` to users if consistent with local audit tables.

No new sale or payment tables are part of this story.

## API Contracts

All routes require authenticated POS terminal context and the existing dining mutation authorization.

### Split by Seat

```text
POST /pos/dining/tickets/{ticket}/splits/seat
```

Request:

```json
{
  "expected_ticket_revision": 6,
  "client_request_uuid": "6f799d45-e61f-4b3f-a0e9-0287b46e3f31",
  "groups": [
    {
      "label": "Seat 1",
      "seat_numbers": [1]
    },
    {
      "label": "Seat 2",
      "seat_numbers": [2]
    },
    {
      "label": "Unassigned",
      "seat_numbers": [null]
    }
  ]
}
```

Rules:

1. `groups` must contain at least two groups.
2. Labels are optional display labels, not financial identifiers.
3. Seat numbers must not overlap across groups.
4. `null` means unassigned items.
5. All active parent items must be covered.

### Split by Item Quantity

```text
POST /pos/dining/tickets/{ticket}/splits/items
```

Request:

```json
{
  "expected_ticket_revision": 6,
  "client_request_uuid": "2fd6e377-55c7-41fe-a337-70e6b48275e2",
  "groups": [
    {
      "label": "Guest A",
      "items": [
        {
          "dining_ticket_item_id": 101,
          "quantity": "1.000"
        }
      ]
    },
    {
      "label": "Guest B",
      "items": [
        {
          "dining_ticket_item_id": 102,
          "quantity": "0.500"
        },
        {
          "dining_ticket_item_id": 103,
          "quantity": "1.000"
        }
      ]
    }
  ]
}
```

Rules:

1. `groups` must contain at least two groups.
2. Item ids must belong to active rows on the parent ticket.
3. The sum of quantities for each parent item must equal the parent item quantity.
4. Quantity validation must use decimal-safe arithmetic.

### Response

Successful create returns `201`.

```json
{
  "parent_ticket": {
    "id": 50,
    "ticket_number": "DT-000050",
    "status": "settling",
    "ticket_revision": 7,
    "total_centavos": 275000
  },
  "children": [
    {
      "id": 51,
      "ticket_number": "DT-000051",
      "status": "open",
      "total_centavos": 150000,
      "promotion_discount_centavos": 10000,
      "rounding_adjustment_centavos": 0
    },
    {
      "id": 52,
      "ticket_number": "DT-000052",
      "status": "open",
      "total_centavos": 125000,
      "promotion_discount_centavos": 5000,
      "rounding_adjustment_centavos": 1
    }
  ],
  "allocation_summary": {
    "allocation_method": "seat",
    "allocation_count": 4,
    "allocated_amount_centavos": 275000,
    "promotion_discount_centavos": 15000,
    "rounding_adjustment_centavos": 1
  }
}
```

### Error Responses

Use existing project error envelope conventions while preserving these status codes and codes:

| Condition | HTTP status | Code |
| --- | ---: | --- |
| Successful split | `201` | - |
| Validation failure | `422` | `VALIDATION_FAILED` |
| Unauthorized | `403` | `FORBIDDEN` |
| Cross-tenant or cross-branch resource | `404` | `NOT_FOUND` |
| Stale ticket revision | `409` | `DINING_TICKET_REVISION_CONFLICT` |
| Idempotency drift | `409` | `DINING_SPLIT_IDEMPOTENCY_DRIFT` |
| Already split parent | `409` | `DINING_TICKET_ALREADY_SPLIT` |
| Statutory discount present | `409` | `DINING_TICKET_STATUTORY_DISCOUNT_SPLIT_BLOCKED` |
| Parent item coverage mismatch | `409` | `DINING_SPLIT_ALLOCATION_MISMATCH` |

## UI Notes

Provide a minimal POS split organizer if the implementation slice includes frontend work.

Required UI behavior:

1. Entry point appears from the active dining ticket view, not from checkout.
2. Cashier can choose split by seat or split by item.
3. Cashier can preview child ticket totals before submitting.
4. Cashier sees deterministic rounding adjustment when non-zero.
5. Cashier cannot submit if every active item quantity is not fully allocated.
6. Cashier sees a stale-ticket refresh message on `409`.
7. Cashier sees created child ticket summaries after success.
8. Payment buttons remain disabled or absent for split children until Story 38.7.

If frontend scope must be reduced, backend APIs and feature tests take priority over a full cashier split UI.

## Test Cases

### Backend Feature Tests

1. Splitting by seat creates child tickets, child item rows, allocation ledger rows, parent revision, child revisions, timeline events, and audit records.
2. Splitting by item quantity creates child item portions whose quantities sum exactly to parent source item quantities.
3. Sum of child ticket totals equals parent ticket total.
4. Sum of allocation `allocated_amount_centavos` equals parent ticket total.
5. Sum of child promotion allocations equals parent promotion allocation.
6. Uneven centavos remainder is assigned to the deterministic final child allocation.
7. Same idempotency key and same payload returns the original split result without duplicate rows.
8. Same idempotency key and different payload returns `409`.
9. Stale `expected_ticket_revision` returns `409` and creates no child or allocation rows.
10. Concurrent split attempts allow only one successful split.
11. Ticket with statutory discount metadata returns `409`.
12. Already split parent returns `409`.
13. Parent item underallocation returns `409`.
14. Parent item overallocation returns `409`.
15. Cross-branch source item ids are hidden or rejected through scoped resolution.
16. Parent item mutation after split returns `409`.
17. Split operation rolls back child tickets, item rows, ledger rows, audit, timeline, and revisions when any validation inside the transaction fails.

### Unit Tests

1. Allocation calculator preserves integer centavos totals.
2. Promotion allocation splitter preserves snapshot totals.
3. Decimal quantity coverage uses decimal-safe comparison.
4. Request fingerprint ignores transport metadata.
5. Final-child rounding selection is deterministic.

### Frontend Tests

1. Split mode toggle switches between seat and item grouping without losing unsaved input.
2. Submit is disabled while allocation coverage is incomplete.
3. Preview totals match API response fixtures.
4. Revision conflict displays refresh guidance.
5. Payment actions are not exposed from the split organizer.

### Manual/UAT Checks

1. Open a two-seat ticket, add items, split by seat, verify two child bills.
2. Open a ticket with a shared quantity item, split by item quantity, verify exact totals.
3. Retry a split request after a network timeout and confirm no duplicate child bills.
4. Attempt to edit the parent ticket after split and confirm it is blocked.
5. Confirm timeline and audit entries are understandable for manager/support review.

## Rollout Plan

1. Implement behind the existing dining/POS permission gates.
2. Seed or confirm the permission needed for split mutation.
3. Deploy backend migration before enabling UI entry points.
4. Pilot with staff test tickets only.
5. Validate exact centavos reconciliation with seeded multi-seat and shared-item scenarios.
6. Keep checkout of child tickets disabled until Story 38.7 is implemented.
7. Monitor audit/timeline records for payload size and readability.

## Rollback Considerations

Before pilot data exists:

1. Revert routes, service, models, and migration normally.
2. Drop `bill_split_allocations` if no split rows exist.

After pilot data exists:

1. Do not drop split allocation rows without a data-retention decision.
2. Disable split routes and UI entry points.
3. Keep child ticket and allocation rows readable for support diagnostics.
4. Preserve timeline and audit records.
5. Do not merge split children back into the parent manually; split reversal is out of scope.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Split math preserves exact centavos.
4. Multi-record split operations are transactionally atomic.
5. Mutation endpoints enforce applicable guards.
6. Idempotent replay is verified.
7. Stale revision conflict is verified.
8. Promotion allocation preservation is verified.
9. Parent post-split mutation guard is verified.
10. Timeline events are verified.
11. Audit records are verified.
12. Frontend split organizer is tested if included in the implementation slice.
13. No architecture constraints are violated.
14. Code review is approved.
15. Relevant documentation or story notes are updated.

## Implementation Checklist

1. [x] Add `bill_split_allocations` migration.
2. [x] Add `BillSplitAllocation` model.
3. [x] Add allocation calculator/value object.
4. [x] Add promotion allocation splitter helper.
5. [x] Add split request fingerprint helper.
6. [x] Add `BillSplitAllocatorService`.
7. [x] Add split-specific domain exceptions.
8. [x] Add request validators for seat and item quantity splits.
9. [x] Add POS routes and controller actions.
10. [x] Add parent split guard to item mutation paths.
11. [x] Add timeline event creation for split.
12. [x] Add audit event creation for split.
13. [x] Add revision records for parent and child tickets.
14. [x] Add backend feature tests.
15. [ ] Add frontend split organizer if approved for this slice.
16. [x] Update story status after implementation.

## Implementation Notes

1. Implemented backend-first per the story priority. The full cashier split organizer UI was not included in this slice.
2. The ledger stores one immutable row per child ticket item allocation.
3. Idempotency is enforced in `BillSplitAllocatorService` by querying existing allocation rows for the same parent ticket and split request UUID.
4. The database indexes `split_request_uuid` for lookup and uniquely protects child item allocation assignment. A strict unique key on `(parent_ticket_id, split_request_uuid)` was not used because one split request intentionally creates multiple ledger rows.
5. Parent item mutations now reject with `DINING_TICKET_ALREADY_SPLIT` when active child bills exist.
6. Child ticket numbers are generated by `DiningTicketNumberService`.
7. Child timeline records use `child_bill_created`; parent timeline records use `bill_split_created`.

## Verification

1. `php artisan test tests/Feature/Dining/BillSplitAllocatorTest.php`
2. `php artisan test tests/Feature/Dining/DiningTicketItemMutationTest.php tests/Feature/Dining/DiningTicketAuditRevisionTest.php tests/Feature/Dining/DiningTicketFoundationTest.php tests/Feature/Dining/DiningFloorMapReadModelTest.php`
3. `php -l app/Services/Dining/BillSplitAllocatorService.php`
4. `php -l app/Http/Controllers/POS/DiningTicketSplitController.php`
5. `php -l app/Models/BillSplitAllocation.php`
