# Slice C Planning Lock: Print-Friendly Stocktake and Inventory Report Views

Status: Closed - Implemented & Locally Validated
Date: 2026-05-25
Parent Plan: `docs/roadmap/market-readiness-inventory-operations-priority-plan.md`
Closure Evidence: `docs/validation/print-friendly-inventory-reports-closure.md`

## 1. Purpose

Define a planning-only lock for print-friendly report views focused on stocktake
and inventory reporting surfaces. The objective is pilot enablement and branch
operations usability for binder-ready and manager-review output.

## 2. Execution Boundary

This task is planning-only.

Do not implement:

1. New stocktake business logic.
2. Stock mutation workflows.
3. Posting/review decision changes.
4. New report engines or aggregation services.
5. New export formats beyond existing CSV and browser print surfaces.
6. Tax, accounting, e-journal, Z-read, GCT, receipt, or offline engine changes.
7. BIR certification/accreditation claims.

## 3. Current Route Inventory (Target Print Surfaces)

| Area | Route | Method | Route Name | Permission Boundary | Print-Friendly Role |
| --- | --- | --- | --- | --- | --- |
| Stocktake summary | `/inventory/stocktakes/{stocktakeSession}/summary` | GET | `inventory.stocktakes.summary` | `inventory.stocktake.view` | Primary stocktake printable summary surface |
| Stocktake variance CSV | `/inventory/stocktakes/{stocktakeSession}/export/variance-csv` | GET | `inventory.stocktakes.export.variance-csv` | `inventory.stocktake.view` | Existing audit export target |
| Variance logs report | `/inventory/reports/variance-logs` | GET | `inventory.reports.variance-logs.index` | `view_inventory_reports` or `audit_inventory` | Inventory variance printable list target |
| Variance logs CSV | `/inventory/reports/variance-logs/export` | GET | `inventory.reports.variance-logs.export` | `view_inventory_reports` or `audit_inventory` | Existing export target |
| Product composition report | `/inventory/reports/product-composition` | GET | `inventory.reports.product-composition.index` | `view_inventory_reports` or `audit_inventory` | Composition print target with role-aware cost visibility |
| Product composition CSV | `/inventory/reports/product-composition/export` | GET | `inventory.reports.product-composition.export` | `view_inventory_reports` or `audit_inventory` | Existing export target |

## 4. Current Page Inventory (Target UI Files)

| Page | File | Current State | Print-Friendly Opportunity |
| --- | --- | --- | --- |
| Stocktake summary | `resources/js/Pages/Inventory/Stocktake/Summary.jsx` | Existing summary/detail surface | Add print-focused layout and print action clarity |
| Stocktake show | `resources/js/Pages/Inventory/Stocktake/Show.jsx` | Operational session view | Add contextual print link guidance to summary/export |
| Stocktake review | `resources/js/Pages/Inventory/Stocktake/Review.jsx` | Review and posting workflow | Preserve workflow and add print guidance only |
| Variance logs | `resources/js/Pages/Inventory/VarianceLogs/Index.jsx` | Table + CSV export | Add print stylesheet/readability mode |
| Product composition | `resources/js/Pages/Inventory/ProductComposition/Index.jsx` | Filtered composition report + export | Add print stylesheet and cost masking confirmation text |

## 5. User Role Workflow Map

### Branch Manager

Needs:

1. Print-ready stocktake summary for sign-off packs.
2. Printable variance and composition views for branch binder filing.

### Reviewer / Poster

Needs:

1. Review-state printable evidence without altering posting workflow.
2. Clear separation between review decisions and print output.

### Auditor / Owner

Needs:

1. Readable hard-copy or PDF-browser output for variance and composition checks.
2. Cost visibility only when `audit_inventory` permission is present.

## 6. Information Architecture Rules

1. Reuse existing report pages and route names.
2. Add print affordances to existing pages rather than creating duplicate report engines.
3. Keep permission checks and masking behavior identical to current runtime behavior.
4. Keep print content tenant/branch scoped exactly as current page data scope.
5. Do not alter stocktake lifecycle transitions.

## 7. Acceptance Criteria

Implementation may proceed only if:

1. Print-friendly views are implemented as presentation-layer behavior on existing pages.
2. Existing stocktake and inventory report routes remain unchanged or only minimally extended for print UI actions.
3. `audit_inventory` cost visibility behavior remains enforced in print output.
4. No report engine or posting logic changes are introduced.
5. No new mutation routes are added.
6. Existing CSV export behavior remains intact.
7. Focused feature/frontend tests validate access and rendering boundaries.
8. `npm run build` passes after implementation.

## 8. Non-Goals

1. No new stocktake business rules.
2. No new procurement behavior.
3. No accounting/tax compliance engine change.
4. No BIR certification format claims.
5. No branch-crossing data exposure.

## 9. Implementation Readiness Checklist

Before implementation starts, confirm:

1. Target report routes/pages are verified.
2. Print action placement is specified per target page.
3. Cost masking is explicitly validated in print mode.
4. No mutation behavior is altered.
5. Test targets are defined.

## 10. Closure Decision

Slice C was implemented as a presentation-layer enhancement on existing
stocktake and inventory report pages.

Validation evidence:

1. `php artisan test tests/Feature/Inventory/VarianceLogAuditingTest.php tests/Feature/Inventory/ProductCompositionReportTest.php tests/Feature/Inventory/StocktakeReportTest.php` passed: 22 tests, 184 assertions.
2. `npm run build` passed.

Closure accepted with boundaries preserved:

1. No stock mutation workflow was added.
2. No stocktake posting/review decision behavior changed.
3. No new report engine or aggregation service was introduced.
4. No new export format or BIR certification/accreditation claim was added.
