# Slice E Planning Lock: Branch Stock Movement Summary

Status: Proposed Scope Lock
Date: 2026-05-25
Parent Plan: `docs/roadmap/market-readiness-inventory-operations-priority-plan.md`

## 1. Purpose

Define the planning lock for a branch-scoped, read-only stock movement summary
surface that improves operational troubleshooting and audit confidence without
adding any inventory mutation, reversal, or accounting side effects.

## 2. Scope Boundary

This task is planning-only.

Do not implement:

1. Stock adjustment, reversal, or correction mutations.
2. Procurement automation or purchase-order generation.
3. Accounting outbox behavior changes.
4. Tax, receipt, Z-read, GCT, e-journal, or offline engine changes.
5. BIR certification/accreditation claims.

## 3. Baseline Existing Surface

Current movement-related surfaces:

1. Route: `/inventory/movements` (`inventory.movements.index`)
2. Controller: `app/Http/Controllers/Inventory/InventoryMovementController.php`
3. Service read-path: `InventoryService::getMovementsForBranch(...)`
4. Existing dashboard summary card uses coarse movement totals:
   `resources/js/Pages/Inventory/Dashboard/Index.jsx`

Current limitations:

1. `/inventory/movements` currently returns JSON only.
2. No dedicated branch movement summary UI table/panel with breakdown by
   movement type and source dimensions.
3. No explicit planning-locked branch movement drill-down summary workflow.

## 4. Target Scope for Slice E

Target capabilities for implementation after lock acceptance:

1. Branch-scoped movement summary with movement-type and date-range filters.
2. Read-only breakdown totals for key movement categories.
3. Optional contextual drill-down links to existing read surfaces only.
4. Preservation of append-only movement records as source-of-truth.
5. No change to movement write paths or mutation permissions.

## 5. Candidate Data Sources

Primary read-only data sources:

1. `inventory_movements` (movement_type, source_type, quantity deltas, timestamps)
2. `branch_inventories` (current snapshot context)
3. `products` (SKU/name category context)
4. `branches` (scoped branch identity)

## 6. Role and Permission Boundaries

Required boundary behavior:

1. Enforce `view_branch_inventory` for movement summary access.
2. Respect branch context and tenant isolation across all queries.
3. Keep all outputs read-only and append-only aligned.
4. No expanded write permission implied by summary visibility.

## 7. Information Architecture Rules

1. Reuse existing inventory movement route and service where practical.
2. Keep movement summary additive to existing dashboard/reporting surfaces.
3. Clearly label summary as read-only operational visibility.
4. Avoid introducing new source-of-truth records.
5. Preserve formula-injection safety for any CSV surface if added.

## 8. Acceptance Criteria

Implementation may proceed only if:

1. Movement summary remains read-only end-to-end.
2. Branch and tenant scopes are strictly enforced.
3. Movement filters (at minimum type/date range) are available.
4. Summary totals reconcile with underlying movement records for selected scope.
5. No write-path, reversal, or accounting behavior change is introduced.
6. Focused tests cover access, scope isolation, and payload integrity.
7. `npm run build` passes after implementation.

## 9. Non-Goals

1. No adjustment posting or undo workflows.
2. No procurement workflow generation.
3. No accounting export/outbox redesign.
4. No cross-tenant reporting or global admin movement analytics.
5. No compliance positioning changes.

## 10. Implementation Readiness Checklist

Before implementation starts, confirm:

1. Baseline movement endpoint behavior and gaps are documented.
2. Target movement dimensions (type/source/date/product) are enumerated.
3. Branch-scoped summary layout and drill-down behavior are defined.
4. Test targets for access and scope boundaries are defined.
5. Export inclusion/defer decision is explicitly approved.

## 11. Decision

Slice E is ready for review as a planning lock.

Implementation should begin only after this planning lock is accepted.
