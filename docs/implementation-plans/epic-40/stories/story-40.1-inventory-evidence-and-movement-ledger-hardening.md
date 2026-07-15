# Story 40.1 Inventory Evidence and Movement Ledger Hardening

## 1. Status

Approved for Implementation

Date: 2026-07-15

## 2. Objective

Harden inventory movement evidence so every committed stock change is ordered, source-traceable, replay-safe, and reconcilable to `branch_inventories.current_stock`.

This story establishes the inventory movement ledger foundation for Epic 40. It does not redesign unit conversion, recipe deduction, stocktake posting, adjustment approval, or inventory reporting beyond the minimum contracts needed for a reliable movement ledger.

## 3. User Story

As an inventory controller,
I want every stock movement to preserve deterministic before/delta/after evidence and source identity,
so that current stock can be explained, audited, and safely reconciled after sales, voids, refunds, stocktake corrections, procurement movements, and opening balances.

## 4. Architecture Alignment

This story implements the first inventory foundation required by:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Inventory movements remain append-only.
2. `branch_inventories.current_stock` remains operational state.
3. Current stock must reconcile to movement history.
4. Movement ordering is branch-scoped.
5. Every stock-affecting source must use the hardened movement writer.
6. No offline inventory mutation is introduced.
7. Procurement, stocktake, recipe, and adjustment feature redesigns remain out of scope.
8. Inventory remains operational evidence, not accounting authority.

## 5. Inventory Aggregate Ownership

Story 40.1 establishes explicit ownership boundaries for the inventory movement ledger.

| Component | Owns | Must Not Own |
| --- | --- | --- |
| `InventoryMovementRecorder` | Movement creation, sequence allocation, movement validation, source replay/drift checks | Sale, refund, stocktake, procurement, or payment business decisions |
| `InventoryService` | Inventory orchestration, stock-in, deduction rules, branch policy application, calls into the recorder | Direct bypass writes to `InventoryMovement` after the recorder exists |
| `BranchInventory` | Operational stock state for a tenant/branch/product | Historical evidence or source-event authority |
| Source services | Business transaction validity, source-specific workflow rules | Direct movement insertion that bypasses the hardened writer |
| Controllers | HTTP transport, request validation, authorization handoff | Inventory movement mutation logic |

Aggregate rule:

```text
Inventory stock mutation
        |
        v
Domain/source service validates business operation
        |
        v
InventoryMovementRecorder records stock evidence
        |
        v
BranchInventory current stock and InventoryMovement commit together
```

## 6. Existing Implementation Context

Current files and behavior that must be respected:

| Area | Current File | Current Behavior |
| --- | --- | --- |
| Movement model | `app/Models/InventoryMovement.php` | Append-only through model update/delete guards. Stores `quantity_change`, `quantity_before`, `quantity_after`, source fields, reason, remarks. |
| Branch stock | `app/Models/BranchInventory.php` | Stores `current_stock` as mutable operational state. Unique by branch/product. |
| Main service | `app/Services/InventoryService.php` | Handles stock-in, manual adjustment, sale deduction, recipe component deduction, movement validation. |
| Sale deduction | `InventoryService::deductFromSale()` | Uses sale-source replay guard and transaction. |
| Void reversal | `app/Services/POS/VoidService.php` | Uses `recordMovement()` and `original_movement_id` guard. |
| Refund return | `app/Services/POS/RefundService.php` | Uses `recordMovement()` but currently needs stronger cumulative/idempotency expectations. |
| Stocktake posting | `app/Services/Inventory/StocktakePostingService.php` | Creates `InventoryMovement` directly and uses `STOCKTAKE_ADJUSTMENT`. |
| Procurement receiving | `app/Services/Procurement/PurchaseReceivingPostingService.php` | Creates `InventoryMovement` directly using `supplier_receiving`. |
| Supplier return | `app/Services/Procurement/SupplierReturnPostingService.php` | Creates `InventoryMovement` directly using `supplier_return`. |
| Inter-branch transfer | `app/Services/Procurement/IbtStockMovementService.php` | Creates `InventoryMovement` directly using `ibt_dispatch` and `ibt_receipt`. |
| Movement API | `app/Http/Controllers/Inventory/InventoryMovementController.php` | Returns movement data for active branch. |
| History service | `app/Services/InventoryHistoryService.php` | Retrieves movement history ordered latest first. |

Important current gap:

`InventoryService::recordMovement()` enforces a narrower movement type list than the direct movement writers. Story 40.1 must remove that split-brain behavior by establishing one hardened writer contract used by all stock movement producers.

## 7. Scope

### In Scope

1. Add branch-scoped movement sequencing.
2. Preserve before/delta/after movement evidence.
3. Treat existing `quantity_change` as the persisted `quantity_delta` equivalent for this story.
4. Harden source reference and idempotency requirements.
5. Introduce `inventory_opening_balance` as a source-controlled movement type.
6. Introduce migration-only baseline evidence for partially reconciled legacy inventory.
7. Add movement-derived current-stock reconciliation logic.
8. Add system reconciliation variance detection.
9. Route direct movement writers through a shared movement recording boundary or equivalent shared service.
10. Update movement model casts/fillable fields.
11. Add database indexes and constraints required for deterministic stock-card evidence.
12. Add feature tests for movement sequence, replay behavior, reconciliation, and source evidence.

### Out of Scope

1. Unit role redesign and conversion versioning. Covered by Story 40.2.
2. Negative stock variance workflow redesign. Covered by Story 40.3.
3. Recipe deduction snapshot redesign. Covered by Story 40.4.
4. Stocktake watermark implementation. Covered by Story 40.5.
5. Adjustment reason approval catalog. Covered by Story 40.6.
6. Full stock card and movement summary UI. Covered by Story 40.7.
7. Procurement workflow redesign.
8. Accounting, costing, tax, receipt, and payment behavior.
9. Offline mutation queueing.

## 8. Data Model Requirements

### 8.1 `inventory_movements`

Add columns:

```text
movement_uuid uuid nullable during migration, required after backfill
movement_schema_version unsignedSmallInteger default 1
movement_sequence unsignedBigInteger nullable during migration, required after backfill
base_unit_id nullable uuid/string-compatible field, depending on existing unit model availability
source_unit_id nullable uuid/string-compatible field, depending on existing unit model availability
source_quantity decimal(19,4) nullable
conversion_snapshot json nullable
business_date date nullable
posted_at timestamp nullable
source_reference string nullable
source_effect_key string nullable
metadata json nullable
```

`movement_uuid` is the immutable external identity for exports, diagnostics, future event publishing, and integration references. The existing primary key remains the database primary key; `movement_uuid` must be unique and must not change after creation.

`movement_schema_version` starts at `1` and identifies the evidence shape used when the movement was created. It is not a business revision counter.

Compatibility rule:

```text
inventory_movements.quantity_change
=
domain quantity_delta
```

Do not rename `quantity_change` in this story. Existing tests, reports, settlement views, procurement features, and POS flows already depend on that column name.

### 8.2 Sequence Constraint

After backfill, enforce:

```text
unique tenant_id, branch_id, movement_sequence
```

Recommended index:

```text
tenant_id, branch_id, movement_sequence
movement_uuid unique
tenant_id, branch_id, product_id, movement_sequence
tenant_id, branch_id, source_type, source_id
tenant_id, branch_id, source_type, source_id, source_effect_key
tenant_id, branch_id, business_date
```

### 8.3 Source Reference

`source_reference` should preserve human-readable source identity such as:

```text
sale_number
void_number
refund_number
stocktake_number
receiving_number
supplier_return_number
ibt_reference_number
opening_balance_reference
```

Existing `reference_number` may remain for compatibility, but the story should standardize new movement creation on `source_reference`. If both fields remain, they must carry the same value for newly created movements until old consumers are migrated.

### 8.4 Opening Balance

Add movement type:

```text
inventory_opening_balance
```

Rules:

1. Allowed only when no prior committed movement exists for the same tenant, branch, and product.
2. Must set `quantity_before = 0`.
3. Must set `quantity_change = opening quantity`.
4. Must set `quantity_after = opening quantity`.
5. Must be idempotent by source key.
6. Must not be reused as a normal correction.
7. Later corrections must use stocktake correction or manual adjustment.

### 8.5 Legacy Migration Baseline

Add migration-only movement type:

```text
inventory_migration_baseline
```

Purpose:

Legacy inventory may already have partial movement history that does not reconcile to `branch_inventories.current_stock`. A migration baseline makes the post-migration invariant true without falsely labeling a later correction as an opening balance.

Backfill calculation for every tenant, branch, and product:

```text
legacy_baseline_delta
=
branch_inventories.current_stock
- sum(existing inventory_movements.quantity_change)
```

Rules:

1. If no movement history exists and `current_stock != 0`, create `inventory_opening_balance`.
2. If movement history exists and `legacy_baseline_delta != 0`, create `inventory_migration_baseline`.
3. `inventory_migration_baseline` is executable only by the Epic 40 migration/backfill process.
4. Runtime services must reject attempts to create `inventory_migration_baseline`.
5. Its `quantity_change` equals `legacy_baseline_delta`.
6. Its `quantity_before` equals the historical movement-derived balance.
7. Its `quantity_after` equals the existing `current_stock`.
8. Its source snapshot records migration version, prior movement count, prior movement sum, original current stock, and baseline reason.
9. It receives `movement_uuid`, `movement_schema_version`, branch sequence, source reference, and deterministic source-effect identity.
10. It is classified as legacy baseline evidence, not an operational adjustment.

## 9. Service Design

### 9.1 Shared Movement Writer

Create or refactor toward a single shared movement writer boundary.

Acceptable implementation options:

1. Harden `InventoryService::recordMovement()` and require all stock writers to use it.
2. Extract a dedicated `InventoryMovementRecorder` service and have `InventoryService` delegate to it.

Recommended option:

```text
App\Services\Inventory\InventoryMovementRecorder
```

Reason:

`InventoryService` currently mixes stock operations, deduction orchestration, validation, reporting queries, and movement creation. A recorder service gives Story 40.1 a clear boundary without redesigning the whole inventory subsystem.

### 9.2 Writer Responsibilities

The shared writer must:

1. Require active tenant context or explicit trusted tenant context.
2. Validate tenant and branch isolation.
3. Lock the related `branch_inventories` row before stock mutation.
4. Assign the next branch-scoped `movement_sequence`.
5. Validate:
   - movement type,
   - signed quantity delta,
   - before + delta = after,
   - source fields for source-controlled movements,
   - source-effect identity for multi-row sources,
   - replay idempotency and drift,
   - original movement linkage for reversals where applicable.
6. Persist the movement row.
7. Preserve `posted_at`, `business_date`, and source reference.
8. Preserve conversion snapshot fields when provided.
9. Assign immutable `movement_uuid`.
10. Assign `movement_schema_version = 1`.
11. Return the created or replayed `InventoryMovement`.

### 9.3 Sequence Allocation

Sequence allocation must be concurrency-safe.

Preferred approach:

```text
inventory_movement_sequences
```

Columns:

```text
tenant_id
branch_id
last_sequence
created_at
updated_at
```

Constraint:

```text
unique tenant_id, branch_id
```

Allocation flow:

```text
lock tenant/branch sequence row
increment last_sequence
assign movement_sequence
create movement
commit
```

Do not allocate by querying `MAX(movement_sequence)` under ordinary concurrent writes unless the implementation uses sufficient locking to avoid duplicate sequences.

### 9.4 Current Stock Mutation Contract

For stock-changing operations:

```text
lock branch_inventory
calculate quantity_before
calculate quantity_after
update branch_inventory.current_stock
record movement with same before/delta/after
commit
```

Movement creation and current-stock update must happen in the same database transaction.

### 9.5 Failure Atomicity

If a stock-affecting operation requires inventory movement, the parent transaction must roll back if any required inventory consequence fails.

Rollback is required when:

1. movement cannot be recorded,
2. current stock cannot be updated,
3. sequence allocation fails,
4. replay drift is detected after a source transaction attempts to reuse an idempotency key,
5. required source linkage or original movement linkage is missing.

Inventory state and the source business transaction must never diverge unless a future approved asynchronous workflow explicitly permits it.

No asynchronous inventory posting is approved in Story 40.1.

### 9.6 Direct Writers to Convert

The implementation must route these direct writers through the shared movement boundary or prove equivalent shared validation:

1. `StocktakePostingService`
2. `PurchaseReceivingPostingService`
3. `SupplierReturnPostingService`
4. `IbtStockMovementService`

The story must not redesign procurement, stocktake, or IBT business behavior. It only standardizes how those operations produce movement evidence.

## 10. Movement Type Vocabulary

Approved first-pass movement types:

```text
stock_in
manual_adjustment
sale_deduction
void_reversal
refund_return
stock_correction
inventory_opening_balance
inventory_migration_baseline
supplier_receiving
supplier_return
ibt_dispatch
ibt_receipt
```

Normalize existing `STOCKTAKE_ADJUSTMENT` to:

```text
stock_correction
```

Do not introduce ambiguous values such as:

```text
adjustment
sale
void
return
stock_out
```

## 11. Idempotency Requirements

Universal replay rule:

```text
Exact replay
=> return the existing movement/effect without mutating stock again

Replay with drift
=> reject before mutation
```

This applies to every source-controlled movement type, not only sales.

Recommended idempotency key vocabulary:

| Source | Idempotency Key |
| --- | --- |
| Sale | `sale:{sale_id}` |
| Void | `sale_void:{void_id}` |
| Refund | `refund:{refund_id}` |
| Opening Balance | `opening_balance:{reference}` |
| Stocktake Posting | `stocktake:{stocktake_session_id}` |
| Purchase Receiving | `purchase_receiving:{receiving_id}` |
| Supplier Return | `supplier_return:{supplier_return_id}` |
| IBT Dispatch | `ibt_dispatch:{ibt_id}` |
| IBT Receipt | `ibt_receipt:{ibt_id}` |

The implementation may store the key explicitly in `metadata` or derive it from controlled `source_type` and `source_id`, but the behavior must be deterministic.

### 11.1 Source-Effect Identity

A single source can create multiple movement rows. Source-level idempotency identifies the business request, but each movement must also identify the individual stock effect.

Required field:

```text
source_effect_key
```

Recommended examples:

```text
sale:{sale_id}:product:{product_id}
sale:{sale_id}:sale_item:{sale_item_id}:ingredient:{ingredient_id}
refund:{refund_id}:sale_item:{sale_item_id}:product:{product_id}
stocktake:{session_id}:line:{stocktake_line_id}
purchase_receiving:{receiving_id}:line:{receiving_line_id}
supplier_return:{supplier_return_id}:line:{supplier_return_line_id}
ibt_dispatch:{ibt_id}:line:{ibt_line_id}
ibt_receipt:{ibt_id}:line:{ibt_line_id}
opening_balance:{reference}:product:{product_id}
migration_baseline:{migration_version}:branch:{branch_id}:product:{product_id}
```

Rules:

1. For source-controlled movements, exact replay is evaluated by source identity plus `source_effect_key`.
2. Multi-line sources must not treat the first created movement as proof that the full source has completed.
3. If only some source effects exist, replay must create only the missing matching effects or reject drift if existing effects differ.
4. Story 40.4 may refine recipe ingredient identity, but Story 40.1 establishes the generic source-plus-effect convention.

### 11.2 Sale Deduction

Existing behavior:

```text
source_type = sale
source_id = sale.id
```

Required behavior:

1. Exact replay must not create duplicate movement rows.
2. Replay must not change stock again.
3. Replay must return successfully if existing movements match the expected sale source.
4. Drift must be rejected if the existing movements for the sale do not match expected product/ingredient effects.

Story 40.1 may implement drift detection at source/product/count level and leave recipe explosion-level precision to Story 40.4.

### 11.3 Void Reversal

Existing behavior uses:

```text
original_movement_id
movement_type = void_reversal
source_type = sale_void
source_id = void.id
```

Required behavior:

1. Exact replay must not create duplicate reversal rows.
2. Each original sale deduction may be void-restored once.
3. Void reversal must reference original movement.

### 11.4 Refund Return

Required behavior:

1. Refund return must not over-restore inventory.
2. Exact replay of the same refund must not create duplicate return rows.
3. Partial refunds must preserve cumulative restored quantity by original sale item/product.
4. Refund return must reference original movement where available.

### 11.5 Opening Balance

Required behavior:

1. Exact replay by source reference must not duplicate opening balance.
2. Opening balance must fail if any prior movement exists for the same tenant, branch, product.
3. Opening balance must not be accepted as a generic stock correction.

## 12. Reconciliation Requirements

Add a query/service method that compares operational current stock with movement-derived stock.

Recommended service:

```text
App\Services\Inventory\InventoryReconciliationService
```

Required invariant:

```text
current_stock
=
opening_balance
+ sum(all later inventory_movements.quantity_change)
```

Because `inventory_opening_balance` is itself a movement, this can be implemented as:

```text
movement_derived_stock = sum(inventory_movements.quantity_change)
system_reconciliation_variance =
branch_inventories.current_stock - movement_derived_stock
```

There must be no special-case mutable baseline hidden outside the movement ledger once Epic 40.1 migration and backfill are complete.

For partially reconciled legacy inventory, `inventory_migration_baseline` is also a movement and participates in the same sum. It is not a mutable baseline table and must not be editable after creation.

Scope:

```text
tenant_id
branch_id
product_id optional
```

Rules:

1. Variance of zero means system state reconciles.
2. Non-zero variance is a system-integrity exception.
3. This is not the same as physical count variance.
4. This story may expose the result through a service and tests only; full UI/reporting belongs to Story 40.7.

Backfill consideration:

If historical rows are incomplete or legacy stock was initialized without movement evidence, the migration must create movement evidence that makes the post-migration reconciliation invariant true.

Preferred approach:

1. Backfill deterministic opening-balance movements for branch/product records where no movement history exists and `current_stock != 0`.
2. Backfill deterministic migration-baseline movements for branch/product records where movement history exists but movement-derived stock does not equal `current_stock`.

## 13. API and UI Requirements

No new full UI is required.

If existing movement endpoints are touched, preserve current behavior and add fields only:

```text
movement_sequence
movement_uuid
movement_schema_version
quantity_delta alias for quantity_change if useful
source_reference
source_effect_key
business_date
posted_at
```

Do not remove existing response fields:

```text
quantity_change
reference_number
source_type
source_id
```

## 14. Authorization and Isolation

1. Tenant isolation remains mandatory.
2. Branch isolation remains mandatory.
3. Movement viewing permissions remain unchanged.
4. Movement creation must not be exposed as a generic public/admin endpoint.
5. Stock-affecting use cases must call domain services, not controllers writing `InventoryMovement` directly.

## 15. Audit Requirements

Audit events should remain focused on source operations:

1. sale inventory deduction,
2. void reversal,
3. refund return,
4. stocktake posted,
5. receiving posted,
6. supplier return posted,
7. IBT dispatch/receipt,
8. opening balance recorded.

The movement row is canonical inventory evidence. Audit logs explain the administrative/business action around it.

## 16. Migration and Backfill Plan

Recommended slices:

1. Add nullable new movement evidence columns.
2. Create `inventory_movement_sequences`.
3. Backfill branch sequence rows and movement sequences for existing movements ordered by:
   ```text
   tenant_id, branch_id, created_at, id
   ```
4. Backfill `posted_at` from `created_at` where missing.
5. Backfill `business_date` from `created_at` date where missing.
6. Backfill `source_reference` from `reference_number` where present.
7. Backfill `movement_uuid` for existing movements.
8. Backfill `movement_schema_version = 1` for existing movements.
9. Backfill `source_effect_key` for existing source-controlled movements where deterministic source fields are available.
10. Create opening-balance movements for branch/product current stock records with no movements and non-zero current stock.
11. Create migration-baseline movements for branch/product records with prior movements and non-zero reconciliation gap.
12. Add unique index for `movement_uuid`.
13. Add unique index for tenant/branch/movement sequence after backfill.
14. Add supporting indexes.
15. Keep rollback safe: dropping new columns and sequence table must not affect legacy movement rows beyond removing Epic 40 evidence columns.

## 17. Test Requirements

Add or update focused tests around existing test suites:

```text
tests/Feature/InventoryMovementTest.php
tests/Feature/POS/InventoryDeductionPolicyTest.php
tests/Feature/POS/InventoryMovementVisibilityTest.php
tests/Feature/Inventory/ProcessSaleInventoryDeductionJobTest.php
tests/Feature/Inventory/StocktakePostingTest.php
```

Required coverage:

1. Movement sequence increments by tenant and branch.
2. Different branches can have independent sequence values.
3. Concurrent or repeated movement writes cannot duplicate tenant/branch sequence.
4. `movement_uuid` is unique and immutable.
5. `movement_schema_version` is set to `1` for newly created and backfilled movements.
6. Movement before + quantity_change = after remains enforced.
7. Existing append-only update/delete guards still pass.
8. Sale deduction replay does not duplicate movement rows or stock deduction.
9. Void reversal replay does not duplicate restoration.
10. Refund return replay does not duplicate restoration.
11. Opening balance can be created only before prior movements.
12. Opening balance replay is idempotent.
13. Migration baseline is created for partial legacy reconciliation gaps.
14. Runtime services cannot create migration-baseline movements.
15. Source-controlled movements preserve `source_effect_key`.
16. Replay drift is rejected before mutation for source-controlled movements.
17. Direct stocktake/procurement/IBT movement writers produce movement sequences.
18. Reconciliation service returns zero variance for healthy stock.
19. Reconciliation service detects manually corrupted current stock.
20. Cross-tenant and cross-branch movement reads remain isolated.

## 18. Acceptance Criteria

### AC1 Movement Sequence

Given inventory movements are posted for a branch,
when multiple movement rows are created,
then each row receives a deterministic branch-scoped `movement_sequence`,
and `(tenant_id, branch_id, movement_sequence)` is unique.

### AC1.1 Canonical Movement Identity

Given an inventory movement is created,
when the movement is persisted,
then it receives an immutable `movement_uuid`,
and it records `movement_schema_version = 1`.

### AC2 Before Delta After Evidence

Given any stock movement is recorded,
when the movement is persisted,
then `quantity_before + quantity_change = quantity_after`,
and the before/change/after values are preserved as immutable historical evidence.

### AC3 Shared Movement Boundary

Given sales, voids, refunds, stocktakes, procurement receiving, supplier returns, or inter-branch transfers create inventory movement rows,
when those operations post stock changes,
then movement creation uses the shared hardened movement boundary or equivalent shared validation.

### AC3.1 Failure Atomicity

Given a source business transaction requires stock movement,
when movement recording, current-stock update, or sequence allocation fails,
then the parent transaction rolls back,
and the source transaction and inventory state do not diverge.

### AC4 Sale Deduction Idempotency

Given sale inventory deduction has already posted,
when the same sale deduction is replayed,
then no duplicate movement rows are created,
and `branch_inventories.current_stock` is not decremented again.

### AC5 Void and Refund Idempotency

Given a void or refund inventory restoration has already posted,
when the same source is replayed,
then no duplicate restoration movement is created,
and stock is not restored more than allowed by the original deduction.

### AC6 Opening Balance Control

Given no prior movement exists for a branch/product,
when an opening balance is recorded,
then an `inventory_opening_balance` movement is created with `quantity_before = 0`.

Given any prior movement exists for the branch/product,
when opening balance is attempted,
then the operation is rejected.

### AC7 Current Stock Reconciliation

Given movement history and branch inventory current stock are consistent,
when reconciliation runs,
then system reconciliation variance is zero.

Given current stock is manually corrupted without movement evidence,
when reconciliation runs,
then the service reports non-zero system reconciliation variance.

The reconciliation contract must treat opening balance as a movement:

```text
current_stock = sum(inventory_movements.quantity_change)
```

after migration/backfill completion.

For legacy partial histories, migration baseline movements are also part of the same sum.

### AC8 Isolation

Given a user belongs to one tenant or branch context,
when movement history or reconciliation is queried,
then records outside that tenant or branch are not visible.

### AC9 Universal Replay and Drift

Given a source-controlled movement is replayed with the same effective payload,
when the recorder handles the request,
then it returns the existing movement/effect without mutating stock.

Given the same source idempotency key is replayed with a different effective payload,
when the recorder handles the request,
then it rejects drift before mutation.

### AC10 Legacy Migration Baseline

Given a branch/product has existing movement history,
and the movement sum does not equal current stock,
when the Epic 40 migration backfill runs,
then it creates an `inventory_migration_baseline` movement for the reconciliation gap,
and post-migration current stock equals the movement-derived stock.

Given runtime code attempts to create `inventory_migration_baseline`,
when the recorder handles the request,
then the operation is rejected.

### AC11 Source-Effect Identity

Given a source operation creates multiple stock effects,
when movements are recorded,
then each movement has a deterministic `source_effect_key`.

Given only part of a multi-row source operation was previously recorded,
when the source operation is replayed,
then exact missing effects are recorded safely or drift is rejected before mutation.

## 19. Implementation Checklist

1. Add migration for movement evidence columns.
2. Add migration for `inventory_movement_sequences`.
3. Backfill movement sequences and baseline fields.
4. Update `InventoryMovement` fillable/casts.
5. Add `InventoryMovementRecorder` or equivalent hardened boundary.
6. Refactor `InventoryService::recordMovement()` to delegate to the recorder.
7. Refactor stocktake/procurement/IBT direct movement creation through the shared boundary.
8. Normalize stocktake movement type to `stock_correction`.
9. Add opening-balance recording path.
10. Add reconciliation service.
11. Add migration-baseline backfill handling for partial legacy reconciliation gaps.
12. Add source-effect identity for source-controlled movements.
13. Add universal replay/drift handling for source-controlled movements.
14. Update movement API response only additively if touched.
15. Add feature tests.
16. Run targeted inventory/POS tests.
17. Run full Laravel test suite if runtime code changes are broad.

## 20. Recommended Verification Commands

Targeted:

```bash
php artisan test tests/Feature/InventoryMovementTest.php
php artisan test tests/Feature/POS/InventoryDeductionPolicyTest.php
php artisan test tests/Feature/Inventory/ProcessSaleInventoryDeductionJobTest.php
php artisan test tests/Feature/Inventory/StocktakePostingTest.php
```

Broader confidence:

```bash
php artisan test
npm run build
```

## 21. Review Notes for Implementation PR

Reviewers should pay special attention to:

1. Race safety of branch sequence allocation.
2. Whether any `InventoryMovement::create()` path bypasses the shared writer.
3. Whether existing procurement and stocktake tests still pass.
4. Whether idempotency guards are exact enough to prevent duplicate stock effects.
5. Whether rollback leaves current stock and movement rows consistent.
6. Whether old response fields remain backward compatible.
7. Whether system reconciliation variance is clearly separated from physical count variance.
8. Whether `movement_uuid` and `movement_schema_version` are populated for old and new rows.
9. Whether replay drift is rejected universally, not only for sale deduction.
10. Whether partial legacy reconciliation gaps receive migration-baseline movement evidence.
11. Whether multi-row source operations use deterministic `source_effect_key` values.

## 22. Non-Blocking Follow-Ups

These should not be pulled into Story 40.1:

1. Conversion rule versioning and unit role model.
2. Full stock card UI.
3. Movement summary reports.
4. Stocktake movement watermark.
5. Direction-aware adjustment reason catalog.
6. Theoretical-versus-actual consumption.
7. Procurement approval or accounting integration changes.
