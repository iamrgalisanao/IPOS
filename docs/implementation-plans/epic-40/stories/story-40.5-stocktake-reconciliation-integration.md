# Story 40.5 Stocktake Reconciliation Integration

## 1. Status

Implemented - Pending Review

Date: 2026-07-16

## 2. Objective

Align stocktake posting with canonical inventory movement and variance evidence.

Story 40.5 hardens the existing stocktake workflow so a posted physical count can be reconciled against:

1. Expected stock at count start.
2. Counted physical quantity.
3. Inventory movements that occurred before and after the physical line count.
4. Expected stock immediately before posting.
5. The final stock correction movement created by posting.
6. The immutable posted stocktake evidence.

The goal is not to redesign stocktake UX. The goal is to make stocktake posting deterministic, auditable, replay-safe, and compatible with the movement ledger established in Stories 40.1 through 40.4.

## 3. User Story

As an inventory controller,
I want stocktake posting to reconcile physical counts against movement watermarks,
so that current stock can be corrected without losing the history of sales, refunds, voids, recipe deductions, receiving, or other movements that happened during the count.

## 4. Architecture Alignment

This story implements the stocktake requirements from:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Inventory movements remain append-only.
2. Stocktake sessions are controlled workflows.
3. Count submission does not equal stock correction.
4. Posting is the only stocktake correction event.
5. Posted stocktake evidence remains immutable.
6. Posting must not erase or rewrite prior movement history.
7. Every posted stocktake line must preserve the movement watermark used for reconciliation.
8. Stocktake reconciliation must distinguish expected-at-count-start, counted quantity, movement-during-count, expected-at-posting, and posted variance.
9. Stocktake posting creates `stock_correction` movements through `InventoryMovementRecorder`.
10. Stocktake correction evidence must remain separate from negative-stock exception evidence.
11. Missing branch inventory is a configuration error and must fail closed.
12. Offline inventory mutation remains prohibited.
13. Backend services own review and posting calculations.
14. User-facing stocktake workflows must remain simple: count, review, preview, post.

## 5. Existing Implementation Context

Current files and behavior that must be respected:

| Area | Current File | Current Behavior |
| --- | --- | --- |
| Stocktake controller | `app/Http/Controllers/Inventory/StocktakeController.php` | Creates sessions, starts counting, updates lines, submits review, updates variance reasons, and posts reviewed sessions. |
| Stocktake session model | `app/Models/StocktakeSession.php` | Tracks statuses: `draft`, `counting`, `review`, `posted`, `cancelled`, `rejected`. |
| Stocktake line model | `app/Models/StocktakeLine.php` | Stores expected quantity, counted quantity, variance, reason code, remarks, and counted user/time. |
| Posting service | `app/Services/Inventory/StocktakePostingService.php` | Posts reviewed sessions, updates current stock, and records `stock_correction` movements. |
| Movement recorder | `app/Services/Inventory/InventoryMovementRecorder.php` | Provides branch movement sequence, source-effect idempotency, and replay drift detection. |
| Movement model | `app/Models/InventoryMovement.php` | Movements are append-only and include source references, before/delta/after quantities, sequence, and metadata. |
| Reports | `app/Http/Controllers/Inventory/StocktakeReportController.php` and `StocktakeVarianceCsvExportService` | Exports current stocktake variance summary from session lines. |
| Existing tests | `tests/Feature/Inventory/Stocktake*Test.php` | Cover creation, counting, review, RBAC, and posting behavior. |

Important current gaps:

1. `expected_quantity` is captured as a simple snapshot but does not identify the movement sequence watermark behind it.
2. `variance_quantity` is calculated against the count-start expectation, which can be wrong if movements occur before posting.
3. Posting currently applies the stored variance directly to current stock.
4. Posting does not preserve movement-during-count evidence per line.
5. Posting does not store the expected quantity immediately before correction.
6. Posted stocktake line evidence is not rich enough to replay or explain reconciliation later.
7. Existing post replay returns early when already posted, but line-level movement idempotency and drift are not explicit in the stocktake contract.
8. Missing `BranchInventory` rows are skipped today; Story 40.5 must fail closed instead.

## 6. Scope

### In Scope

1. Stocktake movement watermark capture.
2. Expected-at-count-start quantity evidence.
3. Physical-count movement watermark capture.
4. Movement-during-count calculation.
5. Movement-after-count calculation.
6. Expected-at-posting quantity evidence.
7. Counted quantity projected to posting time.
8. Posted variance calculation.
9. `stock_correction` movement contract refinement.
10. Posted stocktake session and line immutability.
11. Stocktake posting idempotency and replay drift protection.
12. Posted stocktake audit payload.
13. Stocktake reconciliation report data for UI and CSV.
14. Tenant and branch isolation enforcement.
15. Feature tests for posting, movement evidence, watermarks, and immutability.
16. Stocktake operation mode and count scope.
17. Server-generated posting preview with stale-preview handling.
18. Legacy and inferred evidence-quality handling.

### Out of Scope

1. New mobile counting UX.
2. Barcode scanner integration.
3. Procurement receiving changes.
4. Supplier returns.
5. Inter-branch transfers.
6. Manual stock adjustment approval. Covered by Story 40.6.
7. Full inventory reporting redesign. Covered by Story 40.7.
8. Offline stocktake posting.
9. Automatic product discovery during stocktake.
10. Accounting or tax behavior.
11. Recipe deduction changes. Covered by Story 40.4.

## 7. Locked Decisions

1. `StocktakePostingService` remains the only stocktake posting authority.
2. Posting is allowed only from `review`.
3. `posted` sessions are terminal and immutable.
4. A stocktake line correction must be represented by one append-only `stock_correction` movement when the posted variance is non-zero.
5. A zero posted variance line does not create a movement, but it still preserves reconciliation evidence.
6. Stocktake posting must use `InventoryMovementRecorder` and must not create `InventoryMovement` rows directly.
7. Stocktake physical-count variance is not a negative-stock exception.
8. Stocktake correction must not automatically close or mutate negative-stock exceptions unless a later approved linking workflow explicitly does so.
9. Missing branch inventory for a counted product fails closed in Story 40.5.
10. Duplicate active lines for the same product in one stocktake session are rejected before posting.
11. The count-start movement watermark is the branch-scoped movement sequence observed when counting starts or when a product line is added to the session.
12. The physical-count movement watermark is the branch-scoped movement sequence observed when the counted quantity for that line is saved.
13. Movement-during-count explains drift between the line count-start snapshot and the physical counted moment.
14. Movement-after-count is used to project the physical counted quantity forward to posting time.
15. The correction delta is based on the counted quantity projected to posting time, not on the original count-start variance alone.
16. Stocktake sessions declare an operation mode: `frozen_window` or `movement_aware`.
17. Stocktake sessions declare a count scope.
18. Line-level watermarks are authoritative. Session-level watermarks are informational and must never override line watermarks.
19. The accepted physical count has a stable count snapshot identity.
20. Review and posting calculations are server-owned.
21. Posting preview is advisory and may become stale.
22. Physical-count variance is counted quantity minus expected stock at the accepted physical-count watermark.
23. Raw count-start difference is explanatory only and is not used as the posted correction.
24. Movement projection includes only eligible committed movements within the same branch, product, and stocktake scope.
25. Legacy posted sessions are visibly marked as `legacy` or `inferred` rather than given fabricated exact watermarks.
26. Partial stocktake posting is intentionally unsupported in Story 40.5. Posting remains atomic for the whole approved session.
27. Reconciliation evidence must snapshot the projection policy version used to calculate posted correction values.

## 8. Operating Mode and Scope

Story 40.5 supports two stocktake operation modes:

| Mode | Meaning | First-Release Behavior |
| --- | --- | --- |
| `frozen_window` | Stock-affecting operations for the relevant branch and count scope are blocked while counting is active. | Allowed for controlled full counts only if the implementation can enforce the block. |
| `movement_aware` | Sales, refunds, voids, receiving, and other eligible movements may continue while counting is active. | Default mode. Requires line watermarks and projection. |

If the system cannot enforce the stock-affecting operation block for a requested `frozen_window` session, it must reject the mode or downgrade only through an explicit manager-visible choice to `movement_aware`.

First-release scope types:

| Scope Type | Meaning |
| --- | --- |
| `full_branch` | The session claims to cover all active inventory products in the branch. |
| `selected_products` | The session covers only the products included in its lines. |

Reserved future scope types:

```text
category
cycle_count_reserved
zone_reserved
stock_room_reserved
```

Rules:

1. Every session must store `stocktake_operation_mode`.
2. Every session must store `stocktake_scope_type`.
3. Every line remains authoritative for its own product evidence.
4. A posted `selected_products` session must not be presented as a full branch count.
5. Movement projection must filter by tenant, branch, product, and count scope.

## 9. Stocktake Reconciliation Model

Each posted line must preserve these quantities:

| Field | Meaning |
| --- | --- |
| `expected_quantity_at_count_start` | Current stock for the product when the line entered counting. |
| `count_start_movement_sequence` | Last branch movement sequence observed when the line entered counting. |
| `count_snapshot_uuid` | Stable identity of the accepted physical-count snapshot. |
| `count_snapshot_schema_version` | Schema version of the accepted count snapshot. |
| `counted_quantity` | Physical quantity recorded by the counter at count time. |
| `physically_counted_at` | Time the stock was physically observed where operationally available. |
| `count_recorded_at` | Time the count was saved in IPOS. |
| `counted_movement_sequence` | Last branch movement sequence corresponding to the accepted physical-count snapshot. |
| `expected_quantity_at_count_time` | Current stock for the product at the accepted count watermark. |
| `raw_count_start_difference` | Counted quantity minus count-start expectation. Timeline explanation only. |
| `physical_count_variance_quantity` | Counted quantity minus expected stock at count time. Review variance. |
| `movement_during_count_delta` | Sum of movement deltas after the count-start watermark and up to the physical-count watermark. |
| `movement_after_count_delta` | Sum of movement deltas after the physical-count watermark and before stocktake correction posting. |
| `movement_during_count_sequence_from/to` | Movement range used to explain activity before the accepted count snapshot. |
| `movement_after_count_sequence_from/to` | Movement range used to project the accepted count to posting. |
| `movement_during_count_count` | Number of eligible movements included before the accepted count snapshot. |
| `movement_after_count_count` | Number of eligible movements included after the accepted count snapshot. |
| `expected_quantity_at_posting` | Current stock immediately before the correction movement is posted. |
| `counted_quantity_projected_to_posting` | Counted quantity adjusted by movement activity after the physical count. |
| `posted_variance_quantity` | Correction delta required at posting time. |
| `posting_movement_sequence` | Sequence of the correction movement created for this line, if non-zero. |
| `posting_outcome` | `no_correction_required`, `positive_correction`, or `negative_correction`. |

Formula:

```text
counted_quantity_projected_to_posting
    =
counted_quantity
    +
movement_after_count_delta

posted_variance_quantity
    =
counted_quantity_projected_to_posting
    -
expected_quantity_at_posting
```

Example:

```text
Expected at count start:        10
Counted quantity:                9
Movement during count:          -2
Expected at count time:          8
Movement after count:           -1
Expected at posting:             7

Projected count at posting:
9 + (-1) = 8

Posted variance:
8 - 7 = +1
```

The raw count-start difference is useful for timeline explanation:

```text
raw_count_start_difference
    =
counted_quantity
    -
expected_quantity_at_count_start
```

The physical-count variance is useful for explaining count accuracy at the moment the count was saved:

```text
physical_count_variance
    =
counted_quantity
    -
expected_quantity_at_count_time
```

But posting must use `posted_variance_quantity`.

## 10. Watermark Policy

### Session Start

When a session transitions from `draft` to `counting`, each generated line must capture:

```text
expected_quantity_at_count_start
count_start_movement_sequence
count_start_stock_snapshot_at
```

The sequence must be the current highest branch movement sequence for the session branch at the time the line is created.

### Added Lines

When a product is added to an active counting session, the new line must capture its own watermark at the time of addition.

It must not inherit the original session start watermark.

The session-level watermark is informational only. If a line has its own watermark, all reconciliation must use the line watermark.

### Review

Reviewers may update reason codes and remarks only.

Review-time variance values must be generated by the backend from the accepted count snapshot and movement ledger. The UI may send reason, notes, approval, or rejection intent, but it must not send authoritative variance calculations.

Review must not rewrite:

```text
expected_quantity_at_count_start
count_start_movement_sequence
count_snapshot_uuid
counted_movement_sequence
expected_quantity_at_count_time
physical_count_variance_quantity
movement_during_count_delta
movement_after_count_delta
expected_quantity_at_posting
posted_variance_quantity
posting_movement_sequence
```

### Posting

Posting must calculate movement-after-count and expected-at-posting inside the posting transaction.

The implementation must not trust client-provided reconciliation quantities.

### Count Updates

When a counted quantity is saved, the backend must capture:

```text
count_snapshot_uuid
count_snapshot_schema_version
physically_counted_at
count_recorded_at
counted_movement_sequence
expected_quantity_at_count_time
movement_during_count_delta
physical_count_variance_quantity
```

This prevents the posting service from treating movements that happened before the physical count as if they happened after it.

The watermark should correspond to the physical observation time where operationally possible. For the first release, if the UI cannot capture a separate physical observation timestamp:

```text
physically_counted_at = count_recorded_at
```

### Recounts

Recounts are allowed before review if existing workflow permits count edits.

First-release minimum:

1. The current accepted count snapshot is stored on `stocktake_lines`.
2. Recount changes write an audit event with previous quantity, new quantity, counter, reason or notes, previous snapshot UUID, new snapshot UUID, movement watermark, physical count time, and record time.
3. Posting uses only the accepted count snapshot.

Future option:

```text
stocktake_count_events
```

An append-only count-event table can preserve `first_count`, `recount`, and `supervisor_recount` without relying on audit logs.

## 11. Schema Requirements

The implementation may add columns to the existing tables instead of introducing new aggregate tables.

### `stocktake_sessions`

Add or confirm:

```text
count_started_at nullable timestamp
count_start_movement_sequence nullable unsigned big integer
stocktake_operation_mode string default movement_aware
stocktake_scope_type string default selected_products
session_revision unsigned integer default 1
posting_preview_generated_at nullable timestamp
posting_preview_latest_movement_sequence nullable unsigned big integer
posting_preview_inventory_revision nullable unsigned big integer
posted_movement_sequence_min nullable unsigned big integer
posted_movement_sequence_max nullable unsigned big integer
posting_schema_version unsigned integer default 1
projection_policy_version unsigned integer default 1
posting_evidence_quality string default exact
posting_summary_snapshot json nullable
```

Notes:

1. `count_start_movement_sequence` on the session is a session-level convenience watermark only.
2. Line-level watermarks are authoritative.
3. `posted_movement_sequence_min` and `posted_movement_sequence_max` support quick report filters but do not replace movement rows.
4. `posting_summary_snapshot` stores compact totals, not full line evidence.
5. `posting_evidence_quality` values are `exact`, `legacy`, or `inferred`.
6. `projection_policy_version` identifies the reconciliation algorithm and movement eligibility policy used for posting evidence.

### `stocktake_lines`

Add or confirm:

```text
expected_quantity_at_count_start decimal(19,4) nullable
count_start_movement_sequence unsigned big integer nullable
count_start_stock_snapshot_at nullable timestamp
raw_count_start_difference decimal(19,4) nullable
count_snapshot_uuid uuid nullable
count_snapshot_schema_version unsigned integer default 1
physically_counted_at nullable timestamp
count_recorded_at nullable timestamp
counted_inventory_revision unsigned big integer nullable
counted_movement_sequence unsigned big integer nullable
expected_quantity_at_count_time decimal(19,4) nullable
physical_count_variance_quantity decimal(19,4) nullable
movement_during_count_delta decimal(19,4) nullable
movement_during_count_summary json nullable
movement_during_count_sequence_from unsigned big integer nullable
movement_during_count_sequence_to unsigned big integer nullable
movement_during_count_count unsigned integer nullable
movement_after_count_delta decimal(19,4) nullable
movement_after_count_summary json nullable
movement_after_count_sequence_from unsigned big integer nullable
movement_after_count_sequence_to unsigned big integer nullable
movement_after_count_count unsigned integer nullable
expected_quantity_at_posting decimal(19,4) nullable
posting_inventory_revision_before unsigned big integer nullable
posting_inventory_revision_after unsigned big integer nullable
counted_quantity_projected_to_posting decimal(19,4) nullable
posted_variance_quantity decimal(19,4) nullable
posting_outcome string nullable
projection_policy_version unsigned integer default 1
reason_schema_version unsigned integer default 1
posting_movement_id uuid nullable
posting_movement_sequence unsigned big integer nullable
posting_evidence_quality string default exact
posting_snapshot json nullable
```

Compatibility:

1. Existing `expected_quantity` may remain as a legacy alias for `expected_quantity_at_count_start`.
2. Existing `variance_quantity` may remain as a review-time alias for `physical_count_variance_quantity`.
3. New posting logic must use `posted_variance_quantity`.

Indexes:

```text
tenant_id, branch_id, stocktake_session_id, product_id
tenant_id, branch_id, stocktake_session_id, posting_movement_id
tenant_id, branch_id, count_start_movement_sequence
tenant_id, branch_id, counted_movement_sequence
tenant_id, branch_id, posting_movement_sequence
```

Add a portable duplicate-line guard.

Recommended:

```text
stocktake_session_id, product_id
```

as a unique key if existing data is clean. If existing duplicate lines are possible, add transactional validation first and defer the unique key until data cleanup.

Duplicate-line migration rule:

1. Run a pre-migration diagnostic.
2. Classify duplicates as duplicate empty lines, multiple actual counts, or already-posted duplicates.
3. Do not automatically merge posted evidence.
4. Resolve or retain legacy duplicates before enabling uniqueness for new sessions.

## 12. Posting Transaction Boundary

Posting must run in one database transaction:

```text
Validate session is review
        |
Lock stocktake session
        |
Lock stocktake lines
        |
Validate counted quantities and variance reasons
        |
Lock affected branch inventory rows in deterministic product order
        |
Resolve eligible movement projection ranges
        |
Calculate movement-after-count per line
        |
Calculate expected-at-posting per line
        |
Calculate posted variance per line
        |
Record stock_correction movements through InventoryMovementRecorder
        |
Update stocktake line posting evidence
        |
Mark session posted
        |
Write audit payload
        |
Commit
```

If any line fails validation or movement recording, the entire post fails and the session remains in `review`.

## 13. Stocktake Reconciliation Service

Reconciliation should be owned by an internal collaborator:

```text
StocktakeReconciliationService
```

Responsibilities:

1. Determine eligible movement types.
2. Sum movements between watermarks.
3. Apply tenant, branch, product, and scope filtering.
4. Exclude the current stocktake posting corrections.
5. Apply reconciliation formulas.
6. Generate posting preview data.
7. Validate stale-preview and conflict conditions.
8. Return reconciliation evidence, including movement ranges, movement counts, movement summaries, and projection policy version.

Projection is one responsibility of this service, not the whole boundary.

Current projection policy:

```text
projection_policy_version = 1
```

Eligible committed movement types:

```text
sale_deduction
void_reversal
refund_return
stock_correction from other sessions
manual_adjustment
supplier_receiving
supplier_return
ibt_dispatch
ibt_receipt
```

Recipe deductions are included through their `sale_deduction` inventory movement rows.

Reserved future eligible movement types:

```text
production_input
production_output
```

Excluded movement types:

```text
inventory_migration_baseline
informational records
rejected or rolled-back records
stocktake correction movements from the same posting
movements outside tenant, branch, product, or count scope
```

Movement-during-count explains the difference between the line start snapshot and the moment the physical count was accepted:

```text
movement_during_count_delta
    =
SUM(inventory_movements.quantity_change)
WHERE tenant_id = line.tenant_id
AND branch_id = line.branch_id
AND product_id = line.product_id
AND movement_sequence > line.count_start_movement_sequence
AND movement_sequence <= line.counted_movement_sequence
```

Movement-after-count is the quantity used to project the physical count to posting time:

```text
movement_after_count_delta
    =
SUM(inventory_movements.quantity_change)
WHERE tenant_id = line.tenant_id
AND branch_id = line.branch_id
AND product_id = line.product_id
AND movement_sequence > line.counted_movement_sequence
AND movement_sequence <= current_latest_branch_sequence_before_posting
```

Because the correction sequence is not known until each movement is recorded, the service must compute movement-after-count before creating the correction movement.

Implementation approach:

1. Lock branch inventory rows first.
2. Read current stock as `expected_quantity_at_posting`.
3. Sum movements after the physical-count watermark up to the current latest branch movement sequence.
4. Calculate posted variance.
5. Create the correction movement if the variance is non-zero.

The correction movement itself must never be included in either movement sum.

Movement summaries should group included movement deltas by movement family for preview and support investigation:

```json
{
  "sales": "-3.0000",
  "refunds": "1.0000",
  "receiving": "5.0000",
  "transfers": "-4.0000",
  "adjustments": "0.0000",
  "other_stocktake_corrections": "0.0000",
  "net": "-1.0000"
}
```

The line snapshot should store summaries and sequence ranges, not copy every movement row.

Inventory revision guard:

1. Posting should compare the inventory row revision observed during preview or count acceptance where available.
2. Movement sequence remains the canonical ordering authority.
3. Inventory revision is an additional conflict signal for migration, maintenance, or future code paths that could alter `branch_inventories` without movement evidence.
4. A revision mismatch must force recalculation and may trigger `STOCKTAKE_PREVIEW_STALE`.

## 14. Posting Preview

Before final posting, authorized users should be able to request a backend-generated preview:

```text
GET /inventory/stocktakes/{session}/posting-preview
```

The preview must not mutate stock.

Preview response should include:

```text
preview_generated_at
preview_latest_movement_sequence
preview_inventory_revision
session_revision
stocktake_operation_mode
stocktake_scope_type
expected_at_posting
projected_physical_count
correction_delta
posting_outcome
reason_code
movement_after_count_summary
products with movements after count
products missing inventory configuration
```

The preview is advisory. Posting must recalculate all quantities under lock.

Stale-preview policy:

1. If the user chooses `post_using_latest_movement_state`, posting recalculates under lock and proceeds with the latest values.
2. If the user posts against a prior preview and correction values changed materially, return `409 STOCKTAKE_PREVIEW_STALE`.
3. The stale response must include enough metadata to request a fresh preview.

This keeps the user-facing workflow simple while preserving concurrency safety for movement-aware counts.

Partial posting:

```text
Unsupported in Story 40.5.
```

If any line fails validation or movement recording, the whole post rolls back. Future warehouse workflows may introduce partial posting, but that must be a separate architecture decision.

## 15. Stock Correction Movement Contract

Non-zero posted variance lines must create one movement with:

```text
movement_type: stock_correction
source_type: stocktake_session
source_id: {stocktake_session_id}
source_reference: {stocktake_number}
reference_number: {stocktake_number}
source_effect_key: stocktake:{session_id}:line:{line_id}:product:{product_id}
quantity_change: posted_variance_quantity
quantity_before: expected_quantity_at_posting
quantity_after: expected_quantity_at_posting + posted_variance_quantity
reason_code: {line.reason_code}
remarks: {line.remarks}
```

Required movement metadata:

```json
{
  "schema": "stocktake_posting_v1",
  "stocktake_session_id": "...",
  "stocktake_line_id": "...",
  "count_snapshot_uuid": "...",
  "stocktake_number": "...",
  "stocktake_operation_mode": "movement_aware",
  "stocktake_scope_type": "selected_products",
  "projection_policy_version": 1,
  "expected_quantity_at_count_start": "10.0000",
  "count_start_movement_sequence": 123,
  "counted_quantity": "9.0000",
  "physically_counted_at": "2026-07-16T09:00:00+08:00",
  "count_recorded_at": "2026-07-16T09:00:00+08:00",
  "counted_movement_sequence": 126,
  "movement_during_count_delta": "-2.0000",
  "movement_during_count_sequence_from": 124,
  "movement_during_count_sequence_to": 126,
  "movement_during_count_count": 2,
  "movement_after_count_delta": "-1.0000",
  "movement_after_count_summary": {
    "sales": "-3.0000",
    "refunds": "1.0000",
    "receiving": "5.0000",
    "transfers": "-4.0000",
    "net": "-1.0000"
  },
  "movement_after_count_sequence_from": 127,
  "movement_after_count_sequence_to": 130,
  "movement_after_count_count": 1,
  "expected_quantity_at_posting": "7.0000",
  "counted_quantity_projected_to_posting": "8.0000",
  "posted_variance_quantity": "1.0000",
  "posting_outcome": "positive_correction",
  "reason_code": "MISCOUNT",
  "reason_schema_version": 1
}
```

The movement recorder's replay-drift checks must remain active.

Exact replay must return the existing movement.

Replay with different quantities, product, or source effect must fail.

## 16. Zero-Variance Lines

Zero posted variance lines:

1. Must not create movement rows.
2. Must preserve line-level posting evidence.
3. Must be included in the posted session summary.
4. Must remain immutable after posting.
5. Must store `posting_outcome = no_correction_required`.

This prevents reports from mistaking missing movement rows for missing line reconciliation.

## 17. Idempotency and Replay

Posting replay behavior:

| Condition | Behavior |
| --- | --- |
| Session already `posted` and evidence is complete | Return the posted session without creating new movements. |
| Session already `posted` but evidence is incomplete | Fail with support-facing inconsistency error. |
| Existing line movement matches the expected source effect | Reuse the existing movement and preserve idempotency. |
| Existing line movement has different quantity/product/evidence | Reject as replay drift. |
| Session is not `review` or `posted` | Reject. |

Idempotency key source:

```text
stocktake:{session_id}:line:{line_id}:product:{product_id}
```

This is intentionally line-level. A session-level key is too coarse because a partial or failed post could otherwise hide missing line corrections.

## 18. Immutability Rules

After a session is posted:

1. Session status cannot change.
2. Stocktake lines cannot be edited.
3. Counted quantities cannot be changed.
4. Reason codes and remarks cannot be changed.
5. Posting evidence fields cannot be changed.
6. Posted correction movement links cannot be removed.
7. Stocktake lines cannot be added or deleted.
8. The report may be regenerated from immutable evidence, but evidence itself must not be recalculated.

The implementation may enforce this through model guards, service guards, controller checks, and tests.

## 19. Authorization

Existing permissions remain:

```text
inventory.stocktake.view
inventory.stocktake.create
inventory.stocktake.count
inventory.stocktake.review
inventory.stocktake.approve
inventory.stocktake.post
inventory.stocktake.cancel
```

Story 40.5 must verify:

1. Only authorized users can post.
2. Post authorization is tenant and branch scoped.
3. A user cannot post a session from another branch.
4. A user cannot mutate a posted session even if they have count or review permission.
5. CSV/report access continues to obey stocktake review/view permissions.

## 20. Negative Stock and Variance Separation

Stocktake physical variance is not a negative-stock exception.

Rules:

1. A stocktake correction may increase stock above zero.
2. A stocktake correction may reduce stock.
3. A stocktake correction must not create a negative-stock exception record.
4. A stocktake correction may be linked to a prior negative-stock exception only through an explicit correction-link workflow from Story 40.3 or a future story.
5. Story 40.5 must not auto-close negative-stock exceptions.
6. Posted stocktake variance remains represented by stocktake line evidence plus the `stock_correction` movement.

## 21. Missing Inventory Policy

If a stocktake line references a product without a branch inventory row:

```text
Fail closed.
```

The implementation must not silently skip the line.

The implementation must not create a hidden opening balance.

Reason:

1. Opening balances are governed by movement-ledger rules from Story 40.1.
2. Product discovery during physical count requires its own controlled workflow.
3. Silent skipping creates false posted evidence.

Error response should identify:

```text
stocktake_session_id
stocktake_line_id
product_id
branch_id
reason: missing_branch_inventory
```

## 22. Legacy Evidence Policy

Historical posted stocktake sessions without movement watermarks must not be presented as exact movement-aware reconciliation.

Use:

```text
posting_schema_version = 0
posting_evidence_quality = legacy
```

If a best-effort backfill can infer some evidence, mark it explicitly:

```text
posting_evidence_quality = inferred
```

Backfill may populate:

1. Original expected quantity.
2. Counted quantity.
3. Stored variance.
4. Posting movement only if a unique match exists.

Backfill must not fabricate:

1. Exact count-start movement sequence.
2. Exact physical-count movement sequence.
3. Exact movement ranges.
4. Exact projection deltas.

## 23. Reporting Requirements

Stocktake summary and CSV exports should include, or be ready to include:

```text
Stocktake number
Session status
Operation mode
Scope type
Product
Expected at count start
Count start movement sequence
Count snapshot UUID
Counted quantity
Counted movement sequence
Expected at count time
Raw count-start difference
Physical count variance
Movement during count
Movement during count summary
Movement during count sequence range
Movement after count
Movement after count summary
Movement after count sequence range
Expected at posting
Inventory revision before posting
Projected counted quantity at posting
Posted variance
Posting outcome
Correction movement sequence
Correction movement UUID
Reason code
Reason schema version
Remarks
Counted by
Posted by
Posted at
Evidence quality
Projection policy version
```

Reports must clearly distinguish:

1. Raw count-start difference.
2. Physical-count variance.
3. Movement activity before the count was saved.
4. Movement activity after the count was saved.
5. Posted correction variance.

The report must not recompute posted values from current inventory after posting.

Default posted UI should show:

```text
Counted quantity
Expected at count time
Physical count variance
Movements after count
Expected at posting
Posted correction
```

Hide expected-at-count-start and raw count-start difference under an expandable timeline unless movement-during-count is non-zero.

## 24. Audit Requirements

Posting audit payload must include compact summary data:

```json
{
  "stocktake_number": "ST-20260716-ABCD",
  "stocktake_operation_mode": "movement_aware",
  "stocktake_scope_type": "selected_products",
  "projection_policy_version": 1,
  "line_count": 42,
  "counted_line_count": 42,
  "movement_count": 12,
  "zero_variance_line_count": 30,
  "positive_correction_line_count": 8,
  "negative_correction_line_count": 4,
  "total_positive_adjustment": "15.2500",
  "total_negative_adjustment": "7.5000",
  "posted_movement_sequence_min": 501,
  "posted_movement_sequence_max": 512,
  "movement_after_count_summary": {
    "sales": "-3.0000",
    "refunds": "1.0000",
    "receiving": "5.0000",
    "transfers": "-4.0000",
    "net": "-1.0000"
  },
  "posting_schema_version": 1,
  "posted_by": "..."
}
```

The audit payload should not duplicate full per-line snapshots when those are already stored on lines and movements.

## 25. API and Controller Behavior

Existing routes may remain.

Expected status behavior:

| Condition | HTTP Status |
| --- | ---: |
| Successful post | 302 redirect for Inertia flow or 200 for API flow |
| Validation failure | 422 |
| Unauthorized | 403 |
| Cross-tenant or hidden branch resource | 404 or existing scoped binding behavior |
| Wrong status transition | 409 |
| Already posted exact replay | 200 or redirect with idempotent success |
| Replay drift or incomplete posted evidence | 409 |
| Missing branch inventory | 409 |
| Stale posting preview | 409 |
| Unsupported frozen-window mode | 409 |

Controllers should remain thin.

Posting calculation belongs in `StocktakePostingService` or a dedicated helper owned by it.

## 26. Frontend Requirements

No major new UX is required.

Minimum UI/report refinements:

1. Count flow should remain simple: count, review discrepancy, preview, post.
2. Posted stocktake detail should show physical-count variance, movement-after-count, expected-at-posting, and posted correction by default.
3. Posted stocktake detail should show movement-during-count only when non-zero or in the expandable timeline.
4. Posted stocktake detail should distinguish review variance from posted correction variance.
5. Posted sessions should disable line edits, reason edits, add-line actions, cancel, reject, and submit actions.
6. Posting errors should surface configuration failures, especially missing branch inventory.
7. Posting preview should call out products with movement activity after count.
8. Existing count and review flows should remain familiar.

No mobile counting redesign is approved in this story.

## 27. Implementation Slices

Recommended PR sequence:

### Slice 1 - Session Mode, Scope, and Line Evidence

1. Add operation mode.
2. Add scope type.
3. Add session revision and preview metadata.
4. Add count snapshot UUID and schema version.
5. Add physical-count and record timestamps.
6. Add legacy evidence-quality fields.
7. Add posted immutability guards.

### Slice 2 - Watermark and Recount Behavior

1. Capture session and line movement watermarks when counting starts.
2. Capture line-specific watermarks when products are added during counting.
3. Capture accepted count snapshot identity.
4. Capture physical-count watermarks when counted quantities are saved.
5. Add recount audit/event behavior.
6. Preserve legacy `expected_quantity` compatibility.
7. Add tests for start-counting, added-line, counted-line, and recount snapshots.

### Slice 3 - Projection and Preview

1. Add `StocktakeReconciliationService`.
2. Define eligible movement filtering.
3. Add posting preview endpoint.
4. Add preview watermark and session revision handling.
5. Add inventory revision conflict handling.
6. Add stale-preview conflict behavior.
7. Add movement range, movement count, and movement summary evidence.
8. Snapshot `projection_policy_version`.

### Slice 4 - Atomic Posting

1. Lock session, lines, and inventory rows deterministically.
2. Recalculate authoritative values under lock.
3. Post line-level correction movements through `InventoryMovementRecorder`.
4. Store movement IDs, movement sequences, ranges, outcomes, and snapshots on lines.
5. Store session summary snapshot.
6. Mark zero-variance outcomes explicitly.
7. Expand audit payload.
8. Fail closed on missing branch inventory.

### Slice 5 - Migration, Reporting, and Regression

1. Diagnose duplicate product lines.
2. Clean or classify duplicates before enabling uniqueness.
3. Mark historical posted sessions as `legacy` or `inferred`.
4. Update stocktake summary and CSV data.
5. Add feature tests for movement-during-count.
6. Add idempotency/replay tests.
7. Add posted immutability tests.
8. Add tenant/branch isolation tests.

## 28. Test Plan

Required feature tests:

1. Starting counting captures line expected quantity and movement sequence watermark.
2. Adding a product line during counting captures a line-specific watermark.
3. Saving a counted quantity captures the physical-count movement sequence.
4. Count snapshot UUID is stable for an accepted count.
5. Recount writes audit/event evidence before review.
6. Movements before physical count are not double-counted during posting.
7. Posting with no movements after count behaves like current variance posting.
8. Posting with sale movements after count adjusts the correction delta.
9. Posting with refund or void movements after count adjusts the correction delta.
10. Projection excludes ineligible movements and current posting corrections.
11. Posting preview returns projected quantities without mutating stock.
12. Stale preview handling returns conflict or posts latest state according to request intent.
13. `frozen_window` blocks stock-affecting operations inside scope or is rejected.
14. `selected_products` sessions do not claim full-branch coverage.
15. Movement summary groups sales, refunds, receiving, transfers, adjustments, other stocktake corrections, and net movement.
16. Projection policy version is stored on preview, posting snapshot, and movement metadata.
17. Inventory revision mismatch forces recalculation or stale-preview conflict.
18. Partial posting is rejected or unavailable.
19. Posting creates `stock_correction` movements with before/delta/after evidence.
20. Posted line stores movement ID and movement sequence.
21. Posted line stores movement sequence ranges and movement counts.
22. Zero posted variance lines do not create movements but preserve posting snapshots and `posting_outcome`.
23. Exact post replay does not create duplicate movements.
24. Replay drift is rejected.
25. Missing branch inventory fails closed.
26. Duplicate product lines in one session are rejected before posting.
27. Posted sessions cannot update lines.
28. Posted sessions cannot update reason codes or remarks.
29. Posted sessions cannot add lines.
30. Posted sessions cannot be cancelled, rejected, or resubmitted.
31. Stocktake correction does not create negative-stock exception rows.
32. Tenant and branch isolation are enforced during post.
33. Audit payload includes posting summary.
34. Legacy sessions display `legacy` or `inferred` evidence quality.
35. CSV or summary output includes movement summaries, posting outcome, evidence quality, projection policy version, and posted variance fields.

Suggested targeted commands:

```bash
php artisan test tests/Feature/Inventory/StocktakeFoundationTest.php
php artisan test tests/Feature/Inventory/StocktakeCountingTest.php
php artisan test tests/Feature/Inventory/StocktakeReviewTest.php
php artisan test tests/Feature/Inventory/StocktakePostingTest.php
php artisan test tests/Feature/Inventory/StocktakeRBACHardeningTest.php
```

If the implementation touches shared movement behavior, also run:

```bash
php artisan test tests/Feature/Inventory/InventoryMovementLedgerHardeningTest.php
php artisan test tests/Feature/Inventory/NegativeStockVarianceLifecycleTest.php
php artisan test tests/Feature/POS/InventoryDeductionPolicyTest.php
```

## 29. Acceptance Criteria

Story 40.5 is accepted when:

1. Posting creates controlled `stock_correction` evidence.
2. Posted sessions cannot be silently mutated.
3. Current stock after posting matches posted evidence.
4. Branch and tenant isolation are enforced.
5. Stocktake lines preserve movement watermarks.
6. Movement before and after the physical count is reconciled through the watermark model.
7. Expected-at-count-start and expected-at-posting quantities are both preserved.
8. Physical-count movement sequence is preserved.
9. Posted variance is calculated from projected counted quantity.
10. Correction movements preserve line-level source-effect idempotency.
11. Exact post replay does not duplicate corrections.
12. Replay drift is rejected.
13. Missing branch inventory fails closed.
14. Stocktake variance remains separate from negative-stock exceptions.
15. Summary/CSV evidence distinguishes raw count-start difference, physical-count variance, movement-after-count, and posted variance.
16. `frozen_window` and `movement_aware` modes are explicitly modeled.
17. Stocktake scope is explicitly modeled.
18. Accepted counts store count snapshot UUID, physical count time, record time, and movement watermark.
19. Review and posting calculations are server-owned.
20. Posting preview is server-generated and non-mutating.
21. Stale preview handling is deterministic.
22. Projection includes only eligible committed movements inside branch, product, and count scope.
23. Movement sums preserve sequence ranges and movement counts.
24. Movement summaries are available for preview, audit, support, and reporting.
25. Projection policy version is preserved.
26. Inventory revision is used as an additional conflict signal where available.
27. Partial posting is intentionally unsupported.
28. Zero-variance lines store explicit `posting_outcome`.
29. Historical sessions without exact watermarks are marked `legacy` or `inferred`.
30. Duplicate line cleanup or classification happens before enforcing unique active session lines.

## 30. Definition of Done

Story 40.5 is done when:

1. Acceptance criteria pass.
2. Required migrations are reversible.
3. Model casts and fillable fields are updated.
4. Posting transaction is atomic.
5. Line-level source-effect keys are used.
6. Movement evidence is append-only.
7. Posted stocktake evidence is immutable.
8. Audit payload is verified.
9. Existing stocktake tests pass.
10. Movement-ledger regression tests pass where touched.
11. No offline mutation path is introduced.
12. Posting preview behavior is verified.
13. Legacy evidence-quality behavior is verified.
14. Projection policy version is verified.
15. Documentation is updated with final implementation notes.

## 31. Risks

1. Existing stocktake data may contain duplicate product lines per session.
2. Existing tests may assume `variance_quantity` is the final posting delta.
3. Reports may need compatibility handling for older posted sessions without watermark fields.
4. Concurrent posting and sales can expose locking-order issues if product rows are not locked deterministically.
5. Decimal comparisons must continue using normalized four-decimal strings to avoid false replay drift.
6. Capturing physical observation time separately from record time may require a small UI affordance.
7. `frozen_window` mode is risky unless all stock-affecting paths honor the scope block.
8. Inventory revision support may require a small `branch_inventories` schema addition if no suitable revision field exists.

## 32. Open Questions for Review

1. Should Story 40.5 implement a dedicated `stocktake_count_events` table now, or use audit events for first-release recount evidence?
2. Should `frozen_window` be enabled in Story 40.5, or modeled but disabled until every stock-affecting path can enforce scope locks?
3. What threshold counts as material drift for `STOCKTAKE_PREVIEW_STALE`?

These questions do not change the core architecture. They should be resolved before implementation begins.

## 33. Follow-Up Documentation

Recommended follow-up after Story 40.5 approval:

```text
docs/implementation-plans/epic-40/adr/ADR-005-movement-aware-stocktake-reconciliation.md
```

The ADR should summarize:

1. Why stocktake posting is movement-aware by default.
2. Why line watermarks are authoritative.
3. Why posting remains atomic.
4. Why partial posting is deferred.
5. Why legacy evidence is marked rather than reconstructed.

Story 40.7 should also include manager dashboard reporting for:

```text
stocktakes in progress
movement-aware sessions
frozen-window sessions
pending review
pending posting
rejected sessions
posted sessions
```

## 34. Implementation Notes

Implemented on 2026-07-16.

Delivered:

1. Stocktake reconciliation evidence migration.
2. `StocktakeReconciliationService` for watermarks, projection, preview, summaries, and policy version evidence.
3. Count-start and count-snapshot capture during stocktake counting.
4. Movement-aware posting through `StocktakePostingService`.
5. Line-level stock correction movement evidence and source-effect keys.
6. Posting preview endpoint.
7. Zero-variance posting outcomes.
8. Missing branch inventory fail-closed behavior at posting.
9. Posted evidence fields in stocktake summary and CSV surfaces.
10. Regression tests for watermark capture, projection, preview, missing inventory, zero-variance outcomes, and existing stocktake behavior.
