# Story 40.6 Inventory Adjustment Authorization

## 1. Status

Approved for Implementation

## 2. Objective

Harden manual branch-inventory adjustments so every adjustment is authorized, reasoned, direction-safe, auditable, and represented by canonical append-only inventory movement evidence.

This story closes the gap between the current permissive `InventoryService::adjustStock()` path and the Epic 40 architecture requirement that manual adjustments use structured reason governance, approval thresholds, opening-balance restrictions, and immutable before/delta/after movement snapshots.

Comparable POS inventory systems validate the baseline pattern: manual stock corrections are usually classified by adjustment type, direction, and beginning-stock behavior, while receiving, transfers, stocktake, and sales returns remain separate workflows. IPOS intentionally adds stronger integrity controls: append-only movement evidence, context-bound approval, request idempotency, replay drift detection, immutable reason snapshots, and branch-scoped locking.

## 3. Architecture Alignment

This story implements the Epic 40 Architecture Lock sections:

1. Movement Invariants.
2. Stock State Invariants.
3. Adjustment Reason Governance.
4. Offline Policy.
5. Architecture Constraints.

The governing architecture constraints are:

1. Inventory movement history remains append-only.
2. Current stock changes require movement or reconciliation evidence.
3. Manual adjustment reasons are structured and direction-aware.
4. Opening balance must not be reused as a generic adjustment reason.
5. Offline inventory mutation remains prohibited.
6. Inventory does not create accounting liability.
7. Procurement may consume inventory signals but is not hidden inside adjustment workflows.

## 4. Current State

Relevant existing implementation:

| Area | Current artifact | Notes |
| --- | --- | --- |
| Adjustment service | `app/Services/InventoryService.php` | `adjustStock(BranchInventory $inventory, float $quantityChange, string $reasonCode, ?string $remarks = null)` validates tenant, branch, generic permission, non-empty reason, and no negative result. |
| Movement recorder | `app/Services/Inventory/InventoryMovementRecorder.php` | Records append-only movement rows, movement sequence, before/change/after snapshots, source effect idempotency, and replay drift checks. |
| Movement model | `app/Models/InventoryMovement.php` | Movement rows are immutable through model guards. |
| Inventory model | `app/Models/BranchInventory.php` | Stores operational `current_stock` and Epic 40 `inventory_revision`. |
| Approval rules | `app/Models/ApprovalRule.php` | Currently statutory-discount focused. Not sufficient by itself for inventory adjustment reason thresholds. |
| Manager approvals | `app/Models/ManagerApproval.php` | Existing generic table with context HMAC behavior used by statutory discount authorization. Can be reused only if the Story 40.6 context contract is explicit. |
| RBAC | `app/Services/RbacSeeder.php` | Existing permissions include `inventory.adjustment.view`, `inventory.adjustment.create`, and `inventory.adjustment.approve`. |
| Existing tests | `tests/Feature/StockAdjustmentTest.php` | Covers current basic adjust path, tenant/branch isolation, atomicity, reason required, and negative-result block. |

## 5. Problems To Solve

The current adjustment path has these gaps:

1. Reason codes are free-form strings.
2. Reason direction is not enforced by catalog policy.
3. Approval requirements are not reason-aware or threshold-aware.
4. Opening balance is implemented by initialization, but Story 40.6 must prevent it from becoming a generic later adjustment reason.
5. Movement rows do not yet preserve an adjustment source snapshot with reason version, approval evidence, actor, and request fingerprint.
6. Idempotency exists only if a source effect key is supplied. Manual adjustment command contracts do not yet require one.
7. There is no formal adjustment request DTO or service boundary.
8. UI/API callers can still treat adjustments as raw stock deltas instead of governed business actions.

## 6. Scope

In scope:

1. Adjustment reason catalog schema and model.
2. Direction policy enforcement for manual adjustments.
3. Reason category governance for reporting and reserved workflow protection.
4. Approval policy evaluation for high-risk adjustments.
5. Manager approval integration for required approvals.
6. Idempotent adjustment command contract.
7. Opening-balance special handling and lockout after prior movement exists.
8. Adjustment source snapshot stored on inventory movement metadata.
9. Service-layer authorization and validation.
10. Minimal admin management surface for adjustment reasons.
11. Feature tests for authorization, approval, thresholds, tenant/branch isolation, idempotency, opening-balance lockout, and append-only evidence.

Out of scope:

1. Accounting journal posting.
2. Procurement receiving, supplier return, or purchase-order workflow changes.
3. Stocktake posting behavior changes.
4. Cost-of-goods accounting.
5. Inventory valuation reports.
6. Offline adjustment queueing.
7. Mobile counting or scanner UI.
8. Automatic correction of negative-stock variance logs.
9. Bulk adjustment import.
10. Persistent pending adjustment request inbox.
11. Asynchronous approval workflow.
12. File attachment handling.
13. Backdated adjustment posting unless separately governed.

## 7. Locked Decisions

### 7.1 Adjustment Service Boundary

Manual adjustments must go through a dedicated governed service.

Recommended service:

```text
App\Services\Inventory\InventoryAdjustmentService
```

It should coordinate:

1. Tenant and branch validation.
2. User permission checks.
3. Reason resolution.
4. Direction and threshold validation.
5. Approval validation when required.
6. Branch inventory row locking.
7. Current-stock mutation.
8. Movement recording.
9. Audit logging.
10. Idempotent replay and drift detection.

`InventoryService::adjustStock()` may remain for backward compatibility during migration, but new controller/API usage should call `InventoryAdjustmentService`. If retained, `InventoryService::adjustStock()` should delegate to the governed service or be marked legacy/internal in code comments.

### 7.2 Reason Catalog Is Authoritative

Manual adjustment reason codes must resolve to an active tenant-scoped reason row.

Free-form reason codes are rejected once the catalog exists, except for explicitly supported legacy fixtures during migration tests.

Adjustment reasons classify business intent. Approval policies classify risk. They may be configured together in the UI for operator convenience, but they remain separate domain concepts.

### 7.3 Direction Policy Is Mandatory

Each reason must define a direction policy:

```text
increase_only
decrease_only
bidirectional
opening_balance
```

Rules:

1. `increase_only` accepts positive quantity change only.
2. `decrease_only` accepts negative quantity change only.
3. `bidirectional` accepts positive or negative quantity change only for tightly controlled administrative correction reasons.
4. `opening_balance` is allowed only for initial branch/product setup before prior committed movement exists.
5. Zero quantity change is rejected.
6. `bidirectional` reasons always require notes and approval.
7. No generic bidirectional reason is seeded by default.

### 7.4 Reason Category Is Separate From Direction

Reasons must have a category separate from direction policy.

Approved first-release categories:

```text
damage
expiry
spoilage
shrinkage
theft_or_loss
found_stock
data_correction
internal_consumption
quality_rejection
opening_balance
other_controlled
```

Reserved system-workflow categories are rejected for manual-adjustment reasons:

```text
supplier_receiving
supplier_return
branch_transfer
stocktake_correction
sale_return
void_reversal
```

Manual adjustments must not be used to imitate supplier receiving, supplier returns, branch transfers, sales refunds, void reversals, or stocktake corrections when those workflows exist.

### 7.5 Opening Balance Is Setup, Not Normal Adjustment

Opening balance remains a special inventory source type:

```text
inventory_opening_balance
```

It must not be posted as ordinary `manual_adjustment` after committed branch/product movement exists.

For a branch/product pair:

```text
opening_balance allowed iff no prior inventory_movements row exists
for tenant_id + branch_id + product_id
```

If inventory was migrated with `inventory_migration_baseline`, later opening balance is blocked.

Opening balance is hidden from the normal manual-adjustment UI and requires a separate permission:

```text
inventory.opening-balance.create
```

It is blocked if either:

1. A committed movement exists for tenant, branch, and product.
2. Inventory setup is already complete for that branch/product.

### 7.6 Synchronous Approval Only

Story 40.6 supports synchronous, context-bound approval only:

```text
draft_command
        ↓
approval_not_required
        ↓
posted
```

or:

```text
draft_command
        ↓
approval_required
        ↓
authorized
        ↓
posted
```

Do not introduce a persisted pending adjustment-request aggregate in this story. A future asynchronous approval inbox would need its own lifecycle, expiry, cancellation, editing, reassignment, and audit design.

### 7.7 Approval Is Consumed By Context

High-risk adjustment approval must be context-bound. The approved payload must match the actual adjustment command.

The approval context should include:

1. Tenant ID.
2. Branch ID.
3. Branch inventory ID.
4. Product ID.
5. Requesting user ID.
6. Reason UUID, reason code, reason version, and reason schema version.
7. Direction policy.
8. Quantity change.
9. Quantity before at approval request time.
10. Projected quantity after.
11. Threshold rule that made approval required.
12. Request UUID.

If any material field changes, approval consumption fails.

### 7.8 Approval Preview Is Advisory

Approval preview does not reserve stock, reason policy, approval policy, or inventory revision.

Preview responses must include:

```text
preview_inventory_revision
preview_reason_version
preview_rule_version
preview_generated_at
```

The final posting transaction must recalculate under lock:

1. Current stock.
2. Projected stock.
3. Active reason version.
4. Approval requirement.
5. Matching approval context.

If material values changed, reject with:

```text
409 ADJUSTMENT_PREVIEW_STALE
```

or require a newly issued approval.

### 7.9 Approval Cannot Be Self-Approved

The approving manager must:

1. Belong to the same tenant.
2. Have authorized scope over the target branch.
3. Have `inventory.adjustment.approve`.
4. Be active.
5. Not be the requesting user.

Authorized scope may come from branch assignment, tenant-wide inventory authority, or a future regional/multi-branch scope. Tenant equality remains mandatory.

### 7.10 Adjustment Is Atomic

The inventory update, movement creation, approval consumption, and audit event must be in one database transaction.

If movement recording or approval consumption fails, `branch_inventories.current_stock` must remain unchanged.

### 7.11 Manual Adjustment Cannot Create Negative Stock

Manual adjustments cannot create negative stock in Story 40.6, even when the branch permits soft-negative POS sales.

```text
POS soft-negative policy != Manual adjustment policy
```

A future inventory-supervisor negative adjustment exception requires a separate architecture revision and negative-stock evidence contract.

### 7.12 Idempotency Is Required

Every adjustment command must include:

```text
client_request_uuid
```

The system must derive a stable source effect key:

```text
manual_adjustment:{client_request_uuid}:product:{product_id}
```

Exact replay returns the original movement. Drift is rejected before mutation.

Exact replay remains valid even if the reason is later deactivated, superseded, or policy thresholds change. New adjustments must use an active reason version, but replay validates against the stored movement fingerprint and source effect key.

### 7.13 Material Reason Changes Create New Versions

Material reason changes create a replacement row instead of mutating historical identity.

Material fields:

1. Direction policy.
2. Reason category.
3. Notes requirement.
4. Opening-balance flag.
5. Evidence requirement.

Historical movements keep full reason snapshots regardless, but versioned reason rows improve audit and replay diagnostics.

## 8. Data Model

### 8.1 New Table: `inventory_adjustment_reasons`

Recommended columns:

```text
id uuid primary key
reason_uuid uuid not null
tenant_id uuid not null
code string not null
name string not null
description nullable text
reason_category string not null
direction_policy string not null
requires_notes boolean default false
evidence_required boolean default false
is_opening_balance boolean default false
is_active boolean default true
reason_version unsigned integer default 1
reason_schema_version unsigned integer default 1
supersedes_reason_id nullable uuid
sort_order integer default 0
created_by nullable uuid
updated_by nullable uuid
created_at timestamp
updated_at timestamp
```

Indexes:

```text
tenant_id, code unique
tenant_id, reason_uuid, reason_version unique
tenant_id, is_active
tenant_id, reason_category
tenant_id, direction_policy
tenant_id, is_opening_balance
```

Constraints:

1. `direction_policy` must be one of the approved values.
2. `reason_category` must be one of the approved non-reserved manual-adjustment categories.
3. Reserved workflow categories are rejected.
4. `is_opening_balance = true` requires `direction_policy = opening_balance` and `reason_category = opening_balance`.
5. `bidirectional` reasons require notes and approval policy.
6. Only one active opening-balance reason should be allowed per tenant unless implementation proves multiple named opening-balance reasons are useful.

Material edits create a replacement row with the same `reason_uuid`, incremented `reason_version`, and `supersedes_reason_id` pointing to the prior row.

### 8.2 Approval Policy

Approval policy is separate from reason identity.

Preferred table if persistent policy is implemented in Story 40.6:

```text
inventory_adjustment_approval_rules
```

Recommended columns:

```text
id uuid primary key
tenant_id uuid not null
branch_id nullable uuid
reason_id nullable uuid
minimum_absolute_quantity decimal(18,4) nullable
threshold_unit nullable string
minimum_percentage_of_stock decimal(8,4) nullable
minimum_value_centavos nullable bigint
required_permission string default inventory.adjustment.approve
requires_distinct_approver boolean default true
priority integer default 100
is_active boolean default true
rule_version unsigned integer default 1
rule_schema_version unsigned integer default 1
created_by nullable uuid
updated_by nullable uuid
created_at timestamp
updated_at timestamp
```

First-release rule:

1. Base-unit quantity thresholds are allowed only when the rule identifies the relevant unit or product scope clearly enough to avoid tenant-wide ambiguity.
2. Percentage threshold is the preferred general risk control.
3. Value threshold is schema-ready only unless a stable product-cost authority already exists.
4. Approval policy changes do not change reason identity.

If separate approval-rule persistence is too much for the first implementation, columns may remain embedded with the reason as first-release configuration, but the implementation must document them as embedded approval policy and not intrinsic reason identity.

### 8.3 Existing `manager_approvals`

Use existing `manager_approvals` through `InventoryAdjustmentApprovalService` if it can safely support:

```text
approvable_type = InventoryManualAdjustment
action = approve
context_version = inventory-adjustment-approval-v1
context_hmac = hash of normalized adjustment context
metadata.rule_source
metadata.reason_id
metadata.reason_uuid
metadata.reason_version
metadata.approval_rule_id
metadata.approval_rule_version
metadata.approval_basis
```

Inventory code must not depend directly on statutory-discount assumptions. Do not create a second generic approval table in this story.

### 8.4 Movement Metadata Snapshot

Each manual adjustment movement must include metadata:

```json
{
  "schema": "manual_adjustment_v1",
  "client_request_uuid": "uuid",
  "adjustment_reason_id": "uuid",
  "reason_uuid": "uuid",
  "reason_code": "DAMAGED",
  "reason_name": "Damaged stock",
  "reason_category": "damage",
  "reason_version": 1,
  "reason_schema_version": 1,
  "direction_policy": "decrease_only",
  "requires_notes": true,
  "evidence_required": false,
  "approval_required": false,
  "approval_id": null,
  "approval_basis": [],
  "approval_rule_id": null,
  "approval_rule_version": null,
  "requested_quantity_input": "2.0000",
  "requested_direction": "decrease",
  "requested_quantity_change": "-2.0000",
  "quantity_before": "20.0000",
  "quantity_after": "18.0000",
  "inventory_revision_before": 4,
  "inventory_revision_after": 5,
  "performed_by": "user_uuid",
  "performed_at": "iso8601"
}
```

If approval is required:

```json
{
  "approval_required": true,
  "approval_id": "manager_approval_uuid",
  "approved_by": "manager_uuid",
  "approved_at": "iso8601",
  "approval_basis": [
    "reason_required",
    "percentage_threshold"
  ],
  "requires_distinct_approver": true
}
```

## 9. Command Contract

Recommended DTO:

```text
InventoryAdjustmentCommand
```

Fields:

```text
tenant_id
branch_id
branch_inventory_id
product_id
quantity_change
requested_quantity nullable
requested_direction nullable
reason_code
remarks nullable
client_request_uuid
manager_approval_id nullable
actor_user_id
```

Validation:

1. `quantity_change` required numeric and non-zero.
2. Direction-specific UI may submit positive `requested_quantity`; the backend derives signed `quantity_change` from selected reason direction and requested direction.
3. `reason_code` required active catalog code for new adjustments.
4. `remarks` required when reason requires notes.
5. `manager_approval_id` required when reason or approval policy requires approval.
6. `client_request_uuid` required UUID.
7. Product and branch inventory must match the active tenant and branch.
8. Product must be inventory tracked.
9. User-supplied `occurred_at` is out of scope for normal Story 40.6 posting.

Posting time is server authoritative. Backdating requires a separate permission and policy:

```text
inventory.adjustment.backdate
```

Backdating should remain disabled in the first release unless explicitly implemented with period, stocktake, and migration-baseline boundary checks.

## 10. Approval Requirement Resolution

Approval is required when any of these is true:

1. Reason is `bidirectional`.
2. Approval rule requires approval for the reason or branch.
3. Absolute base-unit quantity change is greater than or equal to a unit-scoped threshold.
4. Percentage-of-stock threshold is met or exceeded.
5. Optional value threshold is configured and exceeded.

Value threshold calculation:

1. If existing reliable cost source exists, use the documented product cost source and snapshot it.
2. If no reliable cost source exists, keep value threshold support disabled in runtime and document as schema-ready only.
3. Do not invent accounting valuation in this story.

Recommended first implementation:

```text
base-unit quantity thresholds supported only when unit scope is explicit
percentage threshold supported as general risk control
value threshold schema present but not enforced unless a stable cost authority exists
```

Approval basis is an array because more than one control may trigger:

```json
{
  "approval_basis": [
    "reason_required",
    "percentage_threshold"
  ]
}
```

Frozen decision:

Value-threshold approval remains schema-ready only for Story 40.6 unless a stable product-cost authority already exists. Retail price, last purchase price, and estimated cost must not be substituted casually because they can produce materially different approval results.

## 11. Opening-Balance Behavior

Opening balance can be created only through one of these paths:

1. Existing `InventoryService::initializeInventory()` flow.
2. Governed adjustment service with an `opening_balance` reason before prior movement exists.

Runtime rules:

1. If any prior movement exists for tenant, branch, and product, reject opening-balance adjustment with HTTP `409`.
2. If branch inventory setup is already complete, reject opening-balance adjustment with HTTP `409`.
3. If branch inventory already has non-zero current stock and no movement exists, the opening-balance command must capture a source snapshot explaining the legacy state.
4. Opening balance movement type must be `inventory_opening_balance`, not `manual_adjustment`.
5. Opening balance source effect key must be deterministic.
6. Opening balance remains append-only after committed.
7. The normal manual-adjustment screen must not show opening-balance reasons.

## 12. Service Design

### 12.1 `InventoryAdjustmentReasonService`

Responsibilities:

1. Create and update reasons.
2. Deactivate reasons.
3. Enforce code uniqueness.
4. Prevent invalid direction policy edits.
5. Preserve historical reason evidence by snapshotting reason details onto movements.
6. Reject reserved workflow categories.
7. Create replacement reason rows for material behavior changes.

Material edits to reason behavior must create a new reason version row.

Material fields:

1. Direction policy.
2. Reason category.
3. Requires notes.
4. Evidence requirement.
5. Opening-balance flag.

### 12.2 `InventoryAdjustmentApprovalService`

Responsibilities:

1. Determine whether approval is required.
2. Build normalized context.
3. Issue approval where interactive manager authorization is supported.
4. Consume approval inside adjustment transaction.
5. Reject expired, used, self-approved, unauthorized-scope, or context-drifted approvals.
6. Resolve base-unit and percentage thresholds.
7. Return advisory preview with reason, rule, and inventory revision evidence.

The service may reuse `ManagerApproval` but must not reuse statutory-discount calculation logic.

Frozen decision:

`ManagerApproval` persistence is reused only through `InventoryAdjustmentApprovalService`. Inventory adjustment code must not call statutory-discount approval services directly.

### 12.3 `InventoryAdjustmentService`

Responsibilities:

1. Validate command.
2. Resolve active reason.
3. Lock branch inventory.
4. Check opening-balance rule.
5. Check direction policy.
6. Check resulting stock cannot be negative unless a future explicit policy says otherwise.
7. Resolve and consume required approval.
8. Increment `inventory_revision`.
9. Record inventory movement through `InventoryMovementRecorder`.
10. Emit audit log.
11. Return movement and inventory snapshot.

Posting sequence:

```text
Check replay
        ↓
Lock branch inventory
        ↓
Resolve active reason and approval policy
        ↓
Calculate signed delta and resulting stock
        ↓
Validate or consume approval
        ↓
Record movement
        ↓
Increment inventory revision
        ↓
Write audit event
        ↓
Commit
```

This avoids consuming an approval before the movement can be posted.

`InventoryService::adjustStock()` must be converted into a deprecated delegate to `InventoryAdjustmentService` rather than preserved as an alternative production mutation path.

## 13. API Contracts

### 13.1 Create Manual Adjustment

```text
POST /inventory/adjustments
```

Request:

```json
{
  "branch_inventory_id": "uuid",
  "requested_quantity": "2.0000",
  "requested_direction": "decrease",
  "reason_code": "DAMAGED",
  "remarks": "Two packs spoiled during storage.",
  "client_request_uuid": "uuid",
  "manager_approval_id": null
}
```

Response `201`:

```json
{
  "status": "posted",
  "movement_id": "uuid",
  "movement_sequence": 42,
  "inventory_revision": 5,
  "quantity_before": "20.0000",
  "quantity_change": "-2.0000",
  "quantity_after": "18.0000"
}
```

Exact replay response `200`:

```json
{
  "status": "replayed",
  "movement_id": "uuid",
  "movement_sequence": 42
}
```

### 13.2 Preview Approval Requirement

```text
POST /inventory/adjustments/approval-preview
```

Purpose:

Return whether an adjustment requires approval and why, without mutating stock.

Response:

```json
{
  "approval_required": true,
  "approval_basis": [
    "percentage_threshold"
  ],
  "reason_name": "Shrinkage",
  "reason_category": "shrinkage",
  "reason_code": "SHRINKAGE",
  "reason_version": 1,
  "approval_rule_version": 2,
  "direction_policy": "decrease_only",
  "quantity_before": "100.0000",
  "requested_quantity": "30.0000",
  "signed_delta": "-30.0000",
  "inventory_revision": 7,
  "preview_inventory_revision": 7,
  "preview_generated_at": "iso8601",
  "projected_quantity_after": "70.0000"
}
```

### 13.3 Issue Manager Approval

If the UI requires interactive manager credentials, add an inventory-specific endpoint:

```text
POST /inventory/adjustments/manager-approval
```

Request includes the exact adjustment context plus manager credentials.

Response:

```json
{
  "status": "authorized",
  "approval_id": "uuid",
  "expires_at": "iso8601"
}
```

This endpoint must not mutate stock.

### 13.4 Reason Admin

Use admin routes consistent with existing admin reason management:

```text
GET /admin/inventory-adjustment-reasons
POST /admin/inventory-adjustment-reasons
PUT /admin/inventory-adjustment-reasons/{reason}
DELETE /admin/inventory-adjustment-reasons/{reason}
```

`DELETE` should deactivate, not physically delete.

## 14. HTTP Response Policy

| Condition | Status |
| --- | ---: |
| Created adjustment | `201` |
| Exact replay | `200` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant or hidden branch resource | `404` or `403` following existing inventory convention |
| Direction policy conflict | `409` |
| Approval required but missing | `409` |
| Approval invalid, expired, used, or context drifted | `409` |
| Adjustment preview stale | `409` |
| Opening balance blocked by prior movement | `409` |
| Negative resulting stock blocked | `409` |
| Reserved workflow reason category | `422` |

## 15. Authorization

Permissions:

1. `inventory.adjustment.view` can view adjustment history.
2. `inventory.adjustment.create` can submit governed adjustments.
3. `inventory.adjustment.approve` can approve high-risk adjustments.
4. Admin reason management should require a new permission if none exists:

```text
inventory.adjustment.reason.manage
```

Branch rules:

1. A branch-scoped user may adjust only assigned branches.
2. Cross-tenant adjustment must be hidden or rejected.
3. Approval manager must have authorized scope over the target branch.
4. Tenant-wide or future regional inventory authority may satisfy branch scope if represented in permission policy.

## 16. Audit Events

Required audit events:

```text
inventory_adjustment_reason_created
inventory_adjustment_reason_updated
inventory_adjustment_reason_deactivated
inventory_adjustment_approval_issued
inventory_adjustment_approval_rejected
inventory_adjustment_approval_consumed
inventory_adjustment_posted
inventory_adjustment_replay_returned
inventory_adjustment_replay_drift_rejected
inventory_adjustment_opening_balance_blocked
```

`inventory_adjustment_posted` metadata:

1. Branch inventory ID.
2. Product ID.
3. Movement ID.
4. Movement sequence.
5. Reason code and version.
6. Reason category and direction policy.
7. Quantity before.
8. Requested quantity.
9. Signed quantity change.
10. Quantity after.
11. Inventory revision before and after.
12. Approval ID and approval basis if any.
13. Client request UUID.

Audit must not replace inventory movement evidence. Movement rows remain the canonical stock-change evidence.

## 17. UI Requirements

Adjustment UI should support:

1. Product/branch inventory lookup.
2. Current stock display.
3. Quantity input as a positive amount whenever the selected reason implies direction.
4. Reason dropdown filtered to active tenant reasons.
5. Direction helper based on selected reason.
6. Required remarks behavior.
7. Approval-required advisory preview.
8. Manager approval prompt only when required.
9. Submit disabled while request UUID is pending.
10. Success state showing movement sequence.
11. Error state for direction conflict, stale approval, opening-balance lockout, and negative resulting stock.
12. Opening-balance reason hidden from the normal adjustment screen.
13. Explicit direction selector only for privileged `bidirectional` reasons.

The UI must not calculate authoritative current stock, approval requirement, or movement evidence. Those are backend-owned.

## 18. Idempotency and Replay

Fingerprint business fields:

1. Tenant ID.
2. Branch ID.
3. Branch inventory ID.
4. Product ID.
5. Quantity change.
6. Reason UUID.
7. Reason version.
8. Normalized remarks.
9. Occurred-at only if a future backdating policy makes it business-significant.
10. Client request UUID.

Approval ID is authorization evidence, not the primary business effect identity. Switching approval IDs for the same command should not by itself change the adjustment fingerprint.

Remarks normalization:

1. Trim leading and trailing whitespace.
2. Normalize line endings.
3. Preserve text and case.
4. Explicitly document whether repeated internal spaces are significant.

This prevents harmless input formatting differences from causing false replay drift while preserving the actual operator note.

Exact replay:

1. Returns the original movement.
2. Does not update stock again.
3. Does not consume approval again.
4. Logs `inventory_adjustment_replay_returned`.

Drift:

1. Reject with conflict.
2. Do not mutate stock.
3. Do not consume approval.
4. Log `inventory_adjustment_replay_drift_rejected`.

## 19. Concurrency

Adjustment posting must:

1. Lock `branch_inventories` row for update.
2. Resolve latest `inventory_revision`.
3. Calculate quantity before from the locked row.
4. Calculate quantity after from locked row plus requested delta.
5. Record movement inside the same transaction.
6. Increment inventory revision only if the movement is newly posted.
7. Return exact replay without changing revision.

Concurrent adjustments to the same branch/product should serialize through row locking and movement sequence allocation.

## 20. Seed Data

Seed default tenant adjustment reasons only if the project has a tenant-scoped seeding pattern that can avoid duplicates.

Recommended defaults:

| Code | Direction | Notes | Approval |
| --- | --- | --- | --- |
| `DAMAGED` | `decrease_only` | Required | Optional threshold |
| `EXPIRED` | `decrease_only` | Required | Optional threshold |
| `SPOILAGE` | `decrease_only` | Required | Optional threshold |
| `SHRINKAGE` | `decrease_only` | Required | Optional threshold |
| `FOUND_STOCK` | `increase_only` | Optional | Optional threshold |
| `CORRECTION_IN` | `increase_only` | Required | Optional threshold |
| `CORRECTION_OUT` | `decrease_only` | Required | Optional threshold |
| `OPENING_BALANCE` | `opening_balance` | Required | Optional |

Do not seed generic `ADJ_IN`, `ADJ_OUT`, or unrestricted bidirectional reasons as final production codes unless product approves those labels.

## 21. Testing Requirements

### 21.1 Backend Feature Tests

Add or update tests covering:

1. Manual adjustment requires active catalog reason.
2. Increase-only reason rejects negative delta.
3. Decrease-only reason rejects positive delta.
4. Bidirectional reason requires notes and approval.
5. Zero delta is rejected.
6. Notes required by reason are enforced.
7. Reserved system-workflow reason category is rejected.
8. Quantity or percentage threshold requires approval.
9. Valid manager approval allows posting.
10. Self-approval is rejected.
11. Unauthorized branch-scope approval is rejected.
12. Approval context drift is rejected.
13. Exact replay returns existing movement.
14. Replay drift is rejected.
15. Opening balance is allowed before prior movement.
16. Opening balance is blocked after prior movement.
17. Negative resulting stock is blocked.
18. Movement metadata includes reason and approval snapshot.
19. Audit event is recorded.
20. Tenant and branch isolation are enforced.
21. Exact replay succeeds after reason deactivation or replacement.
22. Stale approval preview is rejected or requires refreshed approval.
23. Direction-friendly positive quantity input derives signed delta correctly.
24. Concurrent approval-policy changes reject stale approval context when final posting no longer matches.
25. Exact replay after successful posting does not re-evaluate current approval policy.

### 21.2 Regression Tests

Preserve existing expectations from:

```text
tests/Feature/StockAdjustmentTest.php
tests/Feature/InventoryMovementTest.php
```

If existing tests use free-form reason codes, update fixtures to seed catalog reasons rather than weakening the new validation.

### 21.3 Frontend Tests

If UI is touched:

1. Reason dropdown filters active reasons.
2. Direction warnings render correctly.
3. Required notes validation is visible.
4. Approval prompt appears only when backend preview requires it.
5. Submit cannot double-post while pending.

## 22. Migration Strategy

1. Create `inventory_adjustment_reasons`.
2. Create approval policy persistence if implementation chooses table-backed rules.
3. Seed defaults for active tenants if safe, and provide a minimal admin setup path.
4. Preserve existing historical `manual_adjustment` movements as legacy evidence.
5. Do not rewrite historical movement rows.
6. If historical movements have free-form reason codes, reports may display them as legacy reason evidence.
7. New adjustments after migration must use catalog reason IDs in metadata.

## 23. Implementation Slices

Recommended PR sequence:

1. **Reason catalog foundation**
   - Migration
   - Model
   - Factory
   - Seeder/admin defaults
   - Reason service
   - Reason tests

2. **Governed adjustment service**
   - Command DTO
   - Service validation
   - Direction policy
   - Opening-balance rule
   - Movement metadata
   - Idempotency tests

3. **Approval integration**
   - Approval preview
   - Manager approval issue/consume service
   - Context HMAC
   - Threshold tests
   - Concurrent policy-version change tests
   - Exact replay after policy-change tests

4. **API/UI integration**
   - Routes/controllers/requests
   - Admin reason UI
   - Adjustment create UI
   - Frontend tests where applicable

5. **Regression and documentation**
   - Existing stock adjustment test migration
   - User guide update
   - Epic 40 guide status update

## 24. Acceptance Criteria

Story 40.6 is accepted when:

1. Manual adjustments require a structured active reason.
2. Reason direction policy constrains signed quantity.
3. Required notes are enforced.
4. High-risk adjustments require valid manager approval.
5. Approval is context-bound and cannot be self-approved.
6. Opening balance is blocked after prior committed branch/product movement.
7. Adjustment creates exactly one append-only inventory movement.
8. Exact replay does not duplicate stock changes or movements.
9. Replay drift is rejected before mutation.
10. Current stock, movement evidence, and inventory revision update atomically.
11. Unauthorized and cross-branch adjustments are blocked.
12. Audit events capture reason, approval, and movement evidence references.
13. Offline adjustment mutation is not introduced.
14. Existing inventory movement and stocktake tests still pass.
15. Reserved system workflow categories cannot be used for manual adjustment reasons.
16. Manual adjustments cannot create negative stock even when POS soft-negative policy is enabled.
17. Exact replay remains valid after reason deactivation or replacement.
18. Approval preview is advisory and final posting recalculates under lock.
19. Approvers require authorized scope over the branch, not necessarily direct branch staff membership.
20. Direction-friendly UI input can submit a positive affected quantity and derive signed movement evidence.
21. Approval policy changes do not rewrite reason identity or historical movement snapshots.
22. Concurrent approval-policy changes are detected during final posting under lock.
23. Exact replay after successful posting does not re-evaluate active approval policy.

## 25. Definition of Done

Done means:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Frontend tests pass if UI is changed.
4. Existing stock adjustment regression tests are updated and passing.
5. Full `php artisan test` passes.
6. Migrations run cleanly.
7. No historical movement rewrite is introduced.
8. Code review verifies no direct controller writes to `branch_inventories.current_stock`.
9. Documentation is updated.
10. CI is green.

## 26. Frozen Implementation Decisions

1. Value-threshold approval is schema-ready only unless a stable product-cost authority already exists.
2. Minimal reason-management admin UI is included in Story 40.6.
3. `ManagerApproval` is reused only through `InventoryAdjustmentApprovalService`.
4. `InventoryService::adjustStock()` becomes a deprecated delegate to `InventoryAdjustmentService`.
5. Synchronous context-bound approval is in scope; persistent pending adjustment requests are out of scope.
6. Final posting resolves active reason version, active approval-rule version, locked inventory revision, and approval context before consuming approval.
