# Slice D Planning Lock: Low-Stock and Reorder Read-Only Dashboard

Status: Closed - Implemented & Locally Validated
Date: 2026-05-25
Parent Plan: `docs/roadmap/market-readiness-inventory-operations-priority-plan.md`
Closure Evidence: `docs/validation/low-stock-reorder-dashboard-closure.md`

## 1. Purpose

Define the planning lock for a manager-facing, read-only low-stock and reorder
dashboard slice that improves branch replenishment visibility without enabling
procurement automation or stock mutation workflows.

## 2. Scope Boundary

This task is planning-only.

Do not implement:

1. Purchase order creation, approval, or posting.
2. Auto-reorder scheduler or procurement automation.
3. Any stock mutation endpoint.
4. Any stocktake posting/review behavior change.
5. Accounting, tax, receipt, Z-read, GCT, e-journal, or offline engine changes.
6. BIR certification/accreditation claims.

## 3. Baseline Existing Surface

Existing read-only baseline already available:

1. Route: `/inventory/dashboard` (`inventory.dashboard.index`)
2. Controller: `app/Http/Controllers/Inventory/InventoryDashboardController.php`
3. Page: `resources/js/Pages/Inventory/Dashboard/Index.jsx`

Baseline already includes:

1. Branch/product/status/date filters.
2. Low-stock and negative-stock summary counts.
3. Branch summary and product visibility table.
4. Read-only messaging and no mutation controls.

## 4. Target Scope for Slice D

Planning scope for this slice focuses on extending and hardening low-stock and
reorder visibility as an explicit market-readiness surface.

Target capabilities:

1. Clarify reorder signal presentation per branch/product.
2. Add explicit reorder-priority views (read-only recommendations only).
3. Add stock value context where role-permitted.
4. Preserve tenant/branch permission boundaries and scoped data visibility.
5. Add optional export-safe CSV only if acceptance criteria require it.

## 5. Candidate Data Sources

Primary read-only data sources:

1. `branch_inventories` (current stock, reorder level, average cost)
2. `products` (name, SKU, tracked flags, status)
3. `branches` (branch identity and access scope)
4. `inventory_movements` (contextual movement trend, if needed)

## 6. Role and Permission Boundaries

Required boundary behavior:

1. Respect assigned branch scope for non-multi-branch users.
2. Respect multi-branch visibility only when explicitly permitted.
3. Keep cost/value fields hidden unless role has cost-audit permission.
4. Keep all outputs read-only across UI and export surfaces.

## 7. Information Architecture Rules

1. Reuse existing inventory dashboard route/page where practical.
2. Keep slice additive to current read-only behavior.
3. Keep reorder signals advisory only (no action buttons that mutate data).
4. Keep controller/service separation clear if query complexity grows.
5. Keep export behavior formula-injection-safe if CSV is added.

## 8. Acceptance Criteria

Implementation may proceed only if:

1. Dashboard remains read-only with no mutation controls.
2. Reorder/low-stock signals are clearly visible and branch scoped.
3. Permission-based cost masking is preserved for all outputs.
4. No procurement automation behavior is introduced.
5. No stocktake, accounting, tax, receipt, compliance, or offline engine behavior changes.
6. Focused tests cover access scope and data masking boundaries.
7. `npm run build` passes after implementation.

## 9. Non-Goals

1. No PO generation or procurement orchestration.
2. No reorder recommendations that trigger backend mutations.
3. No cross-tenant or cross-branch data exposure.
4. No rewrite of existing stock movement engines.
5. No BIR/compliance positioning changes.

## 10. Implementation Readiness Checklist

Before implementation starts, confirm:

1. Existing dashboard baseline and gaps are documented.
2. Role-specific field visibility matrix is defined.
3. Target filters/cards/tables are explicitly enumerated.
4. Test targets are defined for permission and scope boundaries.
5. Export decision (include/defer) is explicitly approved.

## 11. Closure Decision

Slice D was implemented as a read-only extension of the existing Inventory
Overview Dashboard.

Validation evidence:

1. `php artisan test tests/Feature/Inventory/InventoryDashboardTest.php tests/Feature/Inventory/InventoryHubTest.php tests/Feature/Inventory/StocktakeReportTest.php` passed: 13 tests, 133 assertions.
2. `npm run build` passed.

Closure accepted with boundaries preserved:

1. No stock mutation workflow was added.
2. No procurement automation or auto-reorder behavior was introduced.
3. No stocktake, accounting, tax, receipt, compliance, or offline engine behavior changed.
4. Cost/value context remains gated by `audit_inventory`.
