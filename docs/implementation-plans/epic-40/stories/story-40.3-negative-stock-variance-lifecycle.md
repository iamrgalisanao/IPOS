# Story 40.3 Negative Stock Exception and Resolution Lifecycle

## 1. Status

Implemented - Pending Review

Date: 2026-07-16

## 2. Objective

Formalize negative-stock exception evidence and its operational resolution lifecycle without allowing exception records to correct stock directly.

Every permitted soft-negative deduction must create immutable, movement-linked evidence that managers can investigate, acknowledge, associate with corrective workflows, and resolve without rewriting the original stock event.

Internally, the canonical category may remain:

```text
variance_category = negative_stock
```

But manager-facing UI should call the workflow:

```text
Negative Stock Exception
```

This prevents confusion with physical stocktake variance and system reconciliation variance.

## 3. User Story

As an inventory controller,
I want every policy-permitted negative stock deduction to create clear exception evidence and a manager-friendly resolution workflow,
so that stores can continue permitted sales while shortages remain visible, auditable, and operationally actionable.

## 4. Architecture Alignment

This story implements the variance and stock-state invariants from:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Source evidence fields are immutable.
2. Exception rows do not change stock.
3. Negative stock exception, physical count variance, system reconciliation variance, and configuration variance remain distinct.
4. Low stock is not a variance or exception.
5. Only `quantity_after < 0` may create a `negative_stock` exception.
6. Strict deduction policy blocks insufficient stock.
7. Soft-negative deduction policy permits negative stock only when the sale, movement, exception evidence, and initial lifecycle event commit atomically.
8. System reconciliation variance is a system-integrity exception, not an ordinary stock shortage.
9. Physical stock variance belongs to stocktake evidence and posting workflows, not POS deduction.
10. Exception reports must preserve tenant and branch boundaries.
11. Offline inventory mutation remains prohibited.

## 5. Competitive Product Lessons

Publicly documented behavior from comparable POS inventory systems supports the IPOS direction without requiring IPOS to copy a simpler mutable shortage log.

Relevant lessons:

1. Mosaic-style inventory separates stock cards, adjustments, receiving, transfers, and variance reporting. Detection is not correction.
2. StoreHub-style inventory emphasizes low-stock prevention, real-time visibility, and investigation of overuse, waste, spoilage, or theft.
3. UTAK-style SMB workflows should remain understandable to store managers and avoid exposing technical reconciliation details in the primary queue.

IPOS position:

```text
Exception detection
        !=
Stock correction
```

Correction must happen through movement-producing workflows such as stocktake posting, receiving, approved manual adjustment, refund return, or void reversal.

## 6. Existing Implementation Context

Current files and behavior that must be respected:

| Area | Current File | Current Behavior |
| --- | --- | --- |
| Deduction policy | `app/Services/InventoryService.php` | `performDeduction()` blocks negative stock under `strict_block` and creates `InventoryVarianceLog` under `allow_negative_with_warning`. |
| Branch policy | `app/Http/Controllers/Admin/BranchPolicyController.php` | Allows `strict_block` or `allow_negative_with_warning`. Policy is branch-scoped. |
| Variance model | `app/Models/InventoryVarianceLog.php` | Uses UUIDs, tenant scope, quantity fields, metadata, and model hooks that reject update/delete. |
| Variance table | `database/migrations/2026_05_19_100003_create_inventory_variance_logs_table.php` | Stores sale-linked shortage evidence with branch, product/ingredient, required/available/shortage/resulting quantities. |
| Variance report | `app/Http/Controllers/Inventory/VarianceLogController.php` | Lists and exports variance logs with branch/date/search filters and CSV formula-injection mitigation. |
| Movement writer | `app/Services/Inventory/InventoryMovementRecorder.php` | Creates append-only movement rows with branch sequence, before/delta/after, source effect idempotency, and replay drift checks. |
| Reconciliation | `app/Services/Inventory/InventoryReconciliationService.php` | Compares `branch_inventories.current_stock` against movement-derived stock and returns system reconciliation variance. |
| Unit conversion | `app/Services/Inventory/UnitConversionResolver.php` | Story 40.2 returns conversion snapshots for deduction evidence. |
| Existing tests | `tests/Feature/POS/InventoryDeductionPolicyTest.php` | Covers strict blocking, soft-negative variance creation, recipe shortages, conversion behavior, and variance immutability. |
| Variance report tests | `tests/Feature/Inventory/VarianceLogAuditingTest.php` | Covers authorized variance log viewing/export and immutable records. |

Important current gaps:

1. Variance rows are generic shortage logs rather than typed exception records.
2. There is no explicit category field.
3. There is no resolution lifecycle with append-only events.
4. The variance row does not explicitly reference the inventory movement that caused the negative state.
5. The snapshot does not preserve enough source context from Story 40.1 and Story 40.2.
6. Existing shortage fields can conflate incremental shortage with total negative exposure.
7. Report filtering does not expose category, status, severity, aging, recurrence, policy, or correction linkage.
8. Negative stock exception, stocktake variance, and system reconciliation variance are conceptually separated but not consistently encoded.

## 7. Scope

### In Scope

1. Define explicit exception/variance categories.
2. Add source evidence fields for negative-stock exceptions.
3. Preserve branch-scoped soft-negative deduction policy.
4. Explicitly distinguish low stock from negative stock.
5. Store both incremental shortage and resulting total negative exposure.
6. Link exceptions to the movement effect that caused them.
7. Add append-only lifecycle status events.
8. Add append-only correction links.
9. Add operational queue fields such as severity, aging, recurrence, and correction-link summary as projections or derived read-model values.
10. Keep strict deduction policy blocking unchanged.
11. Keep soft-negative deduction atomic with sale, movement, exception evidence, and initial lifecycle event.
12. Add report filters and export fields for category, status, severity, aging, policy, movement reference, and correction linkage.
13. Add audit events for soft-negative exception creation and lifecycle transitions.
14. Add tests for strict policy, soft policy, idempotency, lifecycle transitions, correction links, branch visibility, reporting, low-stock distinction, full void, refund linkage, and reconciliation separation.

### Out of Scope

1. Automatic purchase-order creation from negative stock exceptions.
2. Automatic stock adjustment from exception resolution.
3. Manual adjustment reason catalog redesign. Covered by Story 40.6.
4. Stocktake posting redesign. Covered by Story 40.5.
5. Recipe deduction snapshot redesign. Covered by Story 40.4.
6. Procurement automation.
7. Accounting liability or cost-of-goods accounting.
8. Offline inventory mutation.
9. Full stock card and movement-summary UI. Covered by Story 40.7.

## 8. Locked Decisions

### 8.1 Negative Stock Policy Scope

Negative-stock permission remains branch-scoped in the first release.

Approved policy chain:

```text
Tenant default
        ↓
Branch policy
        ↓
No product override in Story 40.3
```

Rules:

1. `strict_block` rejects insufficient stock.
2. `allow_negative_with_warning` permits deduction only with movement and exception evidence.
3. Product-level override is a deferred extension.
4. A policy snapshot must explain why the deduction was allowed.

### 8.2 Low Stock Is Not Negative Stock

Low stock and negative stock are different operational conditions.

```text
Low stock
=
threshold notification

Negative stock
=
policy-permitted operational exception with immutable evidence
```

Rules:

1. Low-stock alerts must not create exception rows.
2. Zero stock must not create exception rows unless a deduction crosses below zero.
3. Only `quantity_after < 0` creates a `negative_stock` exception.
4. Low-stock UI and alerting may remain in inventory visibility features.

### 8.3 One Exception Per Movement Effect

Story 40.1 introduced `source_effect_key`. Story 40.3 uses movement effect identity to avoid duplicate exceptions.

Preferred canonical uniqueness after movement creation:

```text
tenant_id
branch_id
movement_id
variance_category
```

Fallback creation identity before movement linkage:

```text
tenant_id
branch_id
source_type
source_id
source_effect_key
variance_category
```

Rules:

1. Once `movement_id` exists, it is the preferred canonical linkage.
2. Exact replay returns existing exception evidence.
3. Drifted replay is rejected before mutation.

### 8.4 Evidence and Resolution Are Separate

Do not claim the entire `inventory_variance_logs` row is immutable if current workflow projection fields are stored on it.

Locked distinction:

```text
Source evidence fields are immutable.

Operational projection fields may change only through
InventoryVarianceLifecycleService.

Every lifecycle change must produce an append-only status event.
```

Evidence examples:

1. Category.
2. Quantities.
3. Movement.
4. Sale and sale item.
5. Product or ingredient.
6. Policy snapshot.
7. Unit and conversion snapshots.
8. Source snapshot.

Projection examples:

1. `current_status`.
2. `first_reviewed_by`.
3. `first_reviewed_at`.
4. `resolved_at`.
5. `terminal_status_reason`.
6. `linked_correction_count` when retained as a non-authoritative projection.

## 9. Variance Category Model

Story 40.3 must introduce explicit category values.

Recommended category enum:

```text
negative_stock
physical_count
system_reconciliation
configuration
```

Rules:

1. `negative_stock` is created only by permitted soft-negative deduction.
2. `physical_count` is created only by stocktake workflows, or remains represented by stocktake lines until Story 40.5 explicitly migrates it.
3. `system_reconciliation` is created only by reconciliation detection or support diagnostics.
4. `configuration` is created only for setup defects when a story explicitly elects to log them; normal strict failures may remain exceptions.
5. Story 40.3 must not force stocktake lines into `inventory_variance_logs`.

First-release requirement:

```text
inventory_variance_logs.variance_category = negative_stock
```

for every row created by POS soft-negative deduction.

## 10. Lifecycle Status Model

Lifecycle statuses:

```text
open
acknowledged
action_planned
linked_to_correction
resolved
voided
dismissed
```

Status definitions:

| Status | Meaning |
| --- | --- |
| `open` | Newly created and awaiting review. |
| `acknowledged` | Reviewed by an authorized manager. |
| `action_planned` | A reason and intended corrective action are recorded, but no correction movement exists yet. |
| `linked_to_correction` | One or more correction movements, refund returns, void reversals, or stocktake records are linked. |
| `resolved` | Manager confirms review is complete and no further operational action is required. This does not necessarily mean current stock is non-negative. |
| `voided` | The originating sale effect was fully reversed, such as by full sale void. |
| `dismissed` | Evidence is valid, but the manager determines no correction is appropriate. |

Allowed transitions:

```text
open -> acknowledged
open -> action_planned
open -> linked_to_correction
open -> voided
open -> dismissed

acknowledged -> action_planned
acknowledged -> linked_to_correction
acknowledged -> resolved
acknowledged -> voided
acknowledged -> dismissed

action_planned -> linked_to_correction
action_planned -> resolved
action_planned -> voided
action_planned -> dismissed

linked_to_correction -> resolved
linked_to_correction -> voided
```

Terminal states:

```text
resolved
voided
dismissed
```

Rules:

1. Terminal states cannot reopen in the first release.
2. Reopening requires a future explicit workflow and append-only event.
3. Lifecycle transitions must not modify source evidence fields.
4. Lifecycle transitions must not directly modify `branch_inventories.current_stock`.
5. Linking a correction does not automatically mark an exception resolved.
6. `resolved` is an operational review decision, not a calculated stock state.

## 11. Data Model Requirements

### 11.1 `inventory_variance_logs`

Add or clarify fields:

```text
variance_uuid
variance_schema_version
variance_category
current_status
movement_id
movement_uuid
movement_sequence
branch_inventory_id
sale_id
sale_item_id
product_id
ingredient_product_id nullable
source_type
source_id
source_reference
source_effect_key

quantity_before
quantity_required
quantity_delta
quantity_after
incremental_shortage_quantity
resulting_negative_quantity

policy_snapshot
unit_snapshot
conversion_snapshot
source_snapshot

first_reviewed_by nullable
first_reviewed_at nullable
resolved_at nullable
terminal_status_reason nullable
linked_correction_count
```

Rules:

1. `variance_uuid` is immutable external identity.
2. `variance_schema_version` starts at `1`.
3. `variance_category` is required for new rows.
4. `current_status` defaults to `open`.
5. `movement_id`, `movement_uuid`, and `movement_sequence` link to the inventory movement that took stock negative.
6. `branch_inventory_id` links to the affected branch/product stock row.
7. `sale_item_id` links direct or recipe-derived deduction back to the sale item when available.
8. `source_type`, `source_id`, `source_reference`, and `source_effect_key` mirror movement source identity.
9. `policy_snapshot` preserves policy resolution at deduction time.
10. `unit_snapshot` preserves base/source unit fields from movement evidence.
11. `conversion_snapshot` preserves Story 40.2 conversion evidence when present.
12. `source_snapshot` may include sale number, sale item ID, parent product, ingredient product, recipe reference, actor, business date, and terminal context if available.
13. `linked_correction_count` is a projection only. It does not replace correction-link records.
14. First release should derive correction counts from `inventory_variance_correction_links` unless query profiling proves a stored projection is needed.

Backward compatibility:

1. Existing fields such as `required_quantity`, `available_quantity_before`, `shortage_quantity`, `resulting_quantity`, `unit`, `policy`, `reason`, and `metadata` may remain during migration.
2. Migration should backfill legacy rows as:

```text
variance_category = negative_stock
current_status = open
variance_schema_version = 1
source_type = sale
source_id = sale_id
quantity_required = required_quantity
quantity_before = available_quantity_before
quantity_after = resulting_quantity
resulting_negative_quantity = abs(min(resulting_quantity, 0))
```

### 11.2 Incremental Shortage vs Total Exposure

The story must preserve two separate quantities:

```text
incremental_shortage_quantity
resulting_negative_quantity
```

Definitions:

```text
incremental_shortage_quantity
=
quantity_required when quantity_before < 0
otherwise max(0, quantity_required - max(quantity_before, 0))

resulting_negative_quantity
=
abs(min(quantity_after, 0))
```

Example:

```text
quantity_before = -2
quantity_required = 5
quantity_after = -7
```

Then:

```text
incremental_shortage_quantity = 5
resulting_negative_quantity = 7
```

This prevents conflating the shortage caused by the current sale with the total negative exposure after the sale.

### 11.3 `inventory_variance_status_events`

Create append-only lifecycle events:

```text
id
event_uuid
event_schema_version
tenant_id
branch_id
inventory_variance_log_id
from_status
to_status
event_type
reason_code
notes
request_uuid nullable
request_fingerprint nullable
actor_id
event_snapshot
created_at
```

Rules:

1. Events are append-only.
2. Events are tenant and branch scoped.
3. Events preserve actor and reason evidence.
4. Events may update the parent row's `current_status` projection within the same transaction.
5. Events must not update source quantity fields.
6. Lifecycle mutations must be idempotent.

Recommended event types:

```text
created
acknowledged
action_planned
linked_to_correction
resolved
voided
dismissed
```

### 11.4 `inventory_variance_correction_links`

Create append-only correction links:

```text
id
tenant_id
branch_id
inventory_variance_log_id
inventory_movement_id nullable
stocktake_session_id nullable
stocktake_line_id nullable
correction_type
linked_quantity
relationship_type
reason_code
actor_id
link_snapshot
created_at
```

Recommended `relationship_type` values:

```text
addresses
partially_addresses
reverses_source
informational
```

Rules:

1. Link rows are append-only.
2. One exception may link to multiple movements or stocktake records.
3. One correction movement may address multiple exception records only when explicitly permitted.
4. Linking does not mutate stock.
5. Linking does not automatically mark the exception resolved.
6. Resolution requires an explicit lifecycle transition.
7. Parent `linked_correction_count` should be derived from correction-link rows in the first release.
8. If later stored for queue performance, it is non-authoritative and must be rebuildable from correction-link rows.

## 12. Snapshot Contracts

### 12.1 Policy Snapshot

Recommended shape:

```json
{
  "policy_schema_version": 1,
  "resolved_policy": "allow_negative_with_warning",
  "tenant_default": "strict_block",
  "branch_override": "allow_negative_with_warning",
  "negative_threshold_quantity": null,
  "manager_notification_required": true,
  "policy_source": "branch",
  "resolved_at": "timestamp"
}
```

### 12.2 Stock Snapshot

Recommended shape:

```json
{
  "stock_snapshot_version": 1,
  "quantity_before": "2.0000",
  "quantity_required": "5.0000",
  "quantity_delta": "-5.0000",
  "quantity_after": "-3.0000",
  "incremental_shortage_quantity": "3.0000",
  "resulting_negative_quantity": "3.0000",
  "base_stock_unit": "kg",
  "branch_inventory_id": "uuid",
  "movement_uuid": "uuid",
  "movement_sequence": 1042
}
```

### 12.3 Unit and Conversion Snapshots

Unit snapshot should preserve:

```text
base_unit_id
source_unit_id
source_quantity
resolved_quantity
```

Conversion snapshot should reuse Story 40.2 movement `conversion_snapshot` without recalculating conversion history.

## 13. Negative Stock Creation Contract

Strict policy:

```text
Validate sale and branch policy
        ↓
Lock branch inventory
        ↓
Compute quantity_before, quantity_delta, quantity_after
        ↓
If quantity_after < 0 and policy is strict_block
        ↓
Rollback and reject sale/payment
```

Allowed soft-negative policy:

```text
Validate sale and branch policy
        ↓
Lock branch inventory
        ↓
Compute negative result
        ↓
Create inventory movement
        ↓
Create or replay negative_stock exception linked to movement
        ↓
Create or replay initial lifecycle event
        ↓
Audit event
        ↓
Commit with sale/payment transaction
```

Atomicity rule:

If negative stock exception evidence is required by policy and the exception row or initial status event cannot be created, the parent sale/payment transaction must roll back.

No successful soft-negative sale may exist with:

1. a negative inventory movement but no exception row,
2. an exception row but no linked movement,
3. an exception row whose source snapshot disagrees with the movement,
4. an exception row whose category is not `negative_stock`.

## 14. Negative Stock Exception Service

Implement negative-stock evidence through a dedicated service.

Recommended service:

```text
App\Services\Inventory\NegativeStockExceptionService
```

Responsibilities:

1. Determine whether exception evidence is required.
2. Build immutable source, policy, stock, unit, and conversion snapshots.
3. Create or replay the exception row.
4. Create or replay the initial lifecycle event.
5. Calculate operational severity.
6. Return a stable result object.

It must not:

1. decide whether the sale is valid,
2. mutate stock independently,
3. create correction movements,
4. resolve or dismiss the exception.

Recommended result object:

```text
NegativeStockExceptionResult
```

Fields:

```text
movement
variance
status_event
replayed
quantity_before
quantity_after
incremental_shortage_quantity
resulting_negative_quantity
severity
```

## 15. Idempotency and Replay

Story 40.1 introduced movement source-effect idempotency. Story 40.3 must align exception creation and lifecycle mutations with that identity.

Creation uniqueness:

```text
tenant_id
branch_id
movement_id
variance_category
```

Fallback uniqueness if movement is not available during lookup:

```text
tenant_id
branch_id
source_type
source_id
source_effect_key
variance_category
```

Rules:

1. Exact replay returns or preserves the existing exception row.
2. Replay must not create duplicate exception rows for the same sale item/product/ingredient shortage.
3. Replay drift is rejected before mutation.
4. Drift checks compare movement ID, affected product or ingredient, sale item, quantities, policy, and conversion snapshot identity.

Lifecycle idempotency:

```text
variance-ack:{variance_id}:{client_request_uuid}
variance-plan:{variance_id}:{client_request_uuid}
variance-link:{variance_id}:{movement_or_stocktake_reference}:{client_request_uuid}
variance-resolve:{variance_id}:{client_request_uuid}
variance-dismiss:{variance_id}:{client_request_uuid}
variance-void:{variance_id}:{reversal_movement_id}
```

Rules:

1. Exact lifecycle replay returns the prior lifecycle event.
2. Reusing the same request identity with different material data rejects drift.
3. Lifecycle idempotency must not create duplicate status events or correction links.

## 16. Lifecycle and Correction Services

Recommended service:

```text
App\Services\Inventory\InventoryVarianceLifecycleService
```

Responsibilities:

1. Acknowledge exception.
2. Record planned action.
3. Link correction movement or stocktake evidence.
4. Resolve exception.
5. Dismiss exception.
6. Mark exception voided after full source reversal.
7. Validate allowed status transitions.
8. Create append-only status events.
9. Create append-only correction links.
10. Emit audit events.
11. Enforce tenant and branch isolation.
12. Enforce lifecycle idempotency and drift detection.

Forbidden behavior:

1. It must not create inventory movements.
2. It must not adjust current stock.
3. It must not rewrite original exception quantities.
4. It must not hide, delete, or erase exceptions.

## 17. Void and Refund Treatment

### 17.1 Full Void

If the exact negative-causing movement is fully reversed by an approved void:

```text
negative stock exception
        ↓
void reversal movement
        ↓
status = voided
```

Rules:

1. The exception remains visible.
2. The lifecycle event references the reversal movement.
3. The correction link uses `relationship_type = reverses_source`.
4. The void transition is append-only and idempotent.

### 17.2 Full Refund

A refund return may restore stock, but the original sale exception still happened.

Rules:

1. Refund return links as correction or reversal evidence.
2. Refund return does not automatically mark the exception `voided`.
3. The exception may become `resolved` only through lifecycle service.

### 17.3 Partial Refund

Rules:

1. Link the refund-return movement only for the quantity restored.
2. Use `partially_addresses` when the restored quantity is less than the exposure.
3. Do not mark resolved automatically unless the entire shortage has been operationally addressed and an authorized manager confirms resolution.

## 18. Operational Queue and Reporting

Story 40.3 should make the manager-facing report an operational exception queue, not just an audit list.

Recommended columns:

```text
Age
Severity
Status
Branch
Product / Ingredient
Source Sale
Quantity Before
Deducted Quantity
Resulting Stock
New Shortage
Total Negative Exposure
Movement Sequence
Policy
Correction Links
Assigned Reviewer
```

Recommended quick filters:

```text
Open only
Critical
Older than 1 day
Repeated product exceptions
No correction linked
Recipe ingredients
Direct products
```

Rules:

1. Keep technical values such as source effect key and fingerprints in evidence detail views, not the primary queue.
2. Preserve existing CSV injection protection.
3. Preserve tenant and branch isolation.
4. Do not turn this story into full Story 40.7 analytics.

### 18.1 Severity, Aging, and Recurrence

Severity, aging, and recurrence may be derived in a query/read model rather than persisted as canonical evidence.

Recommended derived fields:

```text
severity
age_days
same_product_open_exception_count
same_source_type_recent_count
last_exception_at
```

Recommended severity:

```text
warning
high
critical
```

Suggested deterministic first-release severity:

```text
shortage_ratio = incremental_shortage_quantity / quantity_required

warning:  shortage ratio <= 25%
high:     shortage ratio > 25% and <= 100%
critical: resulting negative quantity exceeds configured threshold
          or repeated open exception count exceeds threshold
```

Do not hard-code commercial thresholds permanently. Prefer branch policy or tenant defaults where practical.

Severity safeguards:

1. `quantity_required <= 0` must fail validation during normal exception creation.
2. Read models must avoid division by zero for legacy or corrupted records and may return diagnostic severity.
3. Severity output should expose its basis without making severity canonical evidence.

Example read-model basis:

```json
{
  "severity": "high",
  "severity_basis": {
    "shortage_ratio": "0.6000",
    "resulting_negative_quantity": "3.0000",
    "open_recurrence_count": 2,
    "policy_version": 1
  }
}
```

## 19. Authorization and Audit

Permissions:

1. Existing inventory audit/report permissions should continue to guard read access.
2. Lifecycle transition endpoints require manager/reviewer permission, not cashier permission.
3. Branch-scoped users may only view and transition exceptions for assigned branches.
4. Tenant-wide inventory managers may view all branch exception records within the tenant.

Audit events:

```text
inventory_negative_exception_created
inventory_negative_exception_acknowledged
inventory_negative_exception_action_planned
inventory_negative_exception_correction_linked
inventory_negative_exception_resolved
inventory_negative_exception_voided
inventory_negative_exception_dismissed
```

Audit payloads should include:

1. exception ID and UUID,
2. category,
3. status transition,
4. branch ID,
5. product or ingredient ID,
6. movement ID,
7. sale ID and sale item ID when present,
8. incremental shortage quantity,
9. resulting negative quantity,
10. actor ID,
11. reason code or notes.

## 20. API and UI Requirements

Minimum backend endpoints if lifecycle actions are exposed:

```text
GET    /inventory/reports/variance-logs
GET    /inventory/reports/variance-logs/export
POST   /inventory/variance-logs/{varianceLog}/acknowledge
POST   /inventory/variance-logs/{varianceLog}/plan-action
POST   /inventory/variance-logs/{varianceLog}/link-correction
POST   /inventory/variance-logs/{varianceLog}/resolve
POST   /inventory/variance-logs/{varianceLog}/dismiss
POST   /inventory/variance-logs/{varianceLog}/void
```

Response behavior:

| Condition | Status |
| --- | ---: |
| Successful lifecycle transition | `200` |
| Exact lifecycle replay | `200` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant or hidden branch resource | `404` |
| Invalid status transition | `409` |
| Replay drift | `409` |
| Linked correction belongs to another branch/product without explicit permission | `409` |

UI expectations:

1. The existing variance log page may evolve into an exception queue.
2. Primary text should say `Negative Stock Exception`.
3. Show severity, age, status, shortage, total negative exposure, movement sequence, and source sale.
4. Lifecycle actions should be available only to authorized reviewers.
5. No UI action may imply that acknowledging, linking, resolving, or dismissing an exception fixes stock.

## 21. Migration and Backfill Requirements

Migration must:

1. Add nullable fields safely.
2. Backfill legacy variance rows.
3. Add status-events table.
4. Add correction-links table.
5. Add indexes for common queue/report filters.
6. Add uniqueness for source/category idempotency if supported safely.
7. Preserve SQLite test compatibility and production database compatibility.
8. Include rollback behavior for added fields/tables.

Recommended indexes:

```text
tenant_id, branch_id, variance_category, current_status
tenant_id, branch_id, source_type, source_id
tenant_id, branch_id, movement_id
tenant_id, branch_id, created_at
tenant_id, branch_id, ingredient_product_id
tenant_id, branch_id, current_status, created_at
```

If nullable uniqueness is not portable across database engines, enforce idempotency in services with supporting non-unique indexes.

## 22. Testing Requirements

Feature tests:

1. Strict policy blocks insufficient direct-product sale and creates no movement or exception.
2. Strict policy blocks insufficient recipe ingredient sale and creates no movement or exception.
3. Soft policy creates movement, exception row, initial status event, and audit event atomically.
4. Soft policy direct-product exception has category `negative_stock` and current status `open`.
5. Soft policy recipe exception preserves parent product, ingredient, sale item, and conversion snapshot.
6. Low-stock condition above or equal to zero creates no exception row.
7. Existing negative stock records both incremental shortage and resulting total negative exposure.
8. Replay of sale deduction does not duplicate exception rows.
9. Replay drift is rejected.
10. Source evidence fields cannot be modified after creation.
11. Exception row cannot be deleted.
12. Acknowledge transition creates status event and updates current status.
13. Action-planned transition records intended corrective action.
14. Invalid transition returns conflict.
15. Correction link creates append-only link and lifecycle event.
16. Linking correction does not resolve automatically.
17. Linking correction does not change current stock.
18. Resolve transition requires authorized lifecycle action.
19. Dismiss transition records reason and terminal state.
20. Full void marks exception `voided` through lifecycle event linked to reversal movement.
21. Refund return links as correction evidence and does not automatically void the exception.
22. Lifecycle exact replay does not duplicate events.
23. Lifecycle drift is rejected.
24. Branch-scoped user cannot view or transition another branch's exception.
25. Report filters by category, status, severity, branch, policy, recurrence, and search.
26. CSV export includes new fields and preserves formula-injection mitigation.
27. System reconciliation variance is reported separately and does not create ordinary `negative_stock` exception rows.

Regression tests:

1. Existing `InventoryDeductionPolicyTest` still passes.
2. Existing `VarianceLogAuditingTest` still passes after field additions.
3. Existing `InventoryMovementTest` reconciliation checks still pass.
4. Story 40.2 conversion snapshot tests remain green.

## 23. Implementation Slicing

Recommended PR sequence:

1. **Negative-stock evidence foundation**
   - Add category and evidence fields.
   - Add movement linkage.
   - Distinguish incremental shortage from total negative exposure.
   - Backfill legacy rows.
   - Add creation/replay service.

2. **Atomic POS integration**
   - Strict policy remains fail-closed.
   - Soft policy posts movement, exception, initial event, and audit atomically.
   - Add recipe conversion snapshots.
   - Add replay and drift tests.

3. **Lifecycle and correction links**
   - Add append-only status events.
   - Add append-only correction-link table.
   - Add acknowledge, plan, link, resolve, dismiss, and void transitions.
   - Add lifecycle idempotency.

4. **Operational queue and export**
   - Add status, severity, aging, recurrence, and correction-link filters.
   - Preserve CSV injection protection.
   - Preserve tenant and branch isolation.

5. **Void/refund integration and regression**
   - Full void creates `voided` lifecycle event.
   - Refund movements may be linked but do not automatically erase evidence.
   - Verify store-credit refund behavior does not alter inventory exception semantics.
   - Run full regression suite.

## 24. Acceptance Criteria

1. Strict policy blocks insufficient stock and creates no exception.
2. Soft policy creates negative-stock exception evidence.
3. Sale, movement, exception evidence, and initial lifecycle event commit atomically.
4. Exception records preserve source sale, sale item, product or ingredient, movement, policy, stock, unit, and conversion evidence.
5. Source evidence fields are immutable.
6. Lifecycle transitions are controlled, idempotent, and auditable.
7. Lifecycle transitions do not directly change stock.
8. Negative stock exception is distinct from low-stock alerting.
9. Negative stock exception is distinct from physical count variance.
10. Negative stock exception is distinct from system reconciliation variance.
11. Negative stock exception is distinct from configuration variance.
12. Existing negative stock records incremental shortage and total negative exposure separately.
13. Correction linkage is append-only.
14. Linking correction does not resolve automatically.
15. Full void creates terminal void behavior through lifecycle evidence.
16. Refund return links as correction evidence without automatically voiding the original exception.
17. Exception reports preserve tenant and branch boundaries.
18. Replayed deduction does not duplicate exception records.
19. Drifted replay is rejected.
20. CSV export includes lifecycle, severity, correction, and source evidence fields.

## 25. Definition of Done

Story 40.3 is done when:

1. Acceptance criteria pass.
2. Feature tests pass.
3. Existing inventory deduction, movement, conversion, reconciliation, and variance tests pass.
4. Database migrations include indexes and rollback verification.
5. Tenant and branch isolation are verified.
6. Source evidence remains immutable.
7. Status transitions and correction links are append-only.
8. Lifecycle replay and drift behavior are tested.
9. No exception row or lifecycle action directly changes stock.
10. No offline mutation path is introduced.
11. Code review confirms architecture constraints are preserved.

## 26. Explicit Non-Goals

Do not pull these into Story 40.3:

1. Stocktake posting watermark logic.
2. Full recipe deduction result contract.
3. Manual adjustment approval catalog.
4. Procurement reorder automation.
5. Cost accounting or inventory valuation.
6. Physical stock correction engine.
7. Automatic purchase order creation.
8. Automatic stock adjustment from resolution.
9. Offline exception creation or sync.
10. Full inventory analytics dashboard.

Resolution does not mean stock was corrected unless correction movement evidence is linked.
