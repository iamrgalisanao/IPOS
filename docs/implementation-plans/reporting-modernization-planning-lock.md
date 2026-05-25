# Reporting Modernization Planning Lock

## 1. Status

Accepted - Slice R1 Implemented and Locally Validated

Date: 2026-05-25

## 2. Purpose

The current reporting experience is directionally useful for operational review
and auditability, but it is too narrow to function as a management-facing
reporting center.

The existing `Transaction History` surface must be preserved and repositioned as
an audit-first surface (`Transaction Audit Log`) rather than treated as the main
reporting module.

This planning lock prepared the first implementation slice:

`Slice R1: Sales Summary Report`

Slice R1 has now been implemented and locally validated. Closure evidence is recorded in `docs/validation/sales-summary-report-closure.md`.

## 3. Current State Assessment

Verified current reporting-related baseline:

1. Current page name: `Transaction History`
2. Current apparent function: transaction listing and audit review
3. Current routes:
   - `sales.history.index`
   - `sales.history.export`
   - `sales.history.show`
4. Current controller:
   - `app/Http/Controllers/Sales/SalesHistoryController.php`
5. Current query and export services:
   - `app/Services/Sales/SalesHistoryQueryService.php`
   - `app/Services/Sales/SalesHistoryExportService.php`
6. Current page/component:
   - `resources/js/Pages/Sales/History/Index.jsx`

Current visible filters (verified in UI component):

1. search
2. start date
3. end date
4. status

Current visible export:

1. CSV export

Current visible table fields:

1. transaction details and timestamp
2. status
3. branch
4. total amount
5. detail action

Assessment:

This surface is useful for traceability and audit investigation, but it is
insufficient as the primary management reporting surface.

## 4. Reclassification Decision

Decision:

`Transaction History` will be repositioned as `Transaction Audit Log`.

Purpose of this page:

1. transaction traceability
2. audit review
3. status review
4. detail inspection
5. exception investigation

It must not remain the only sales reporting surface.

## 5. Reports Center Information Architecture

Target information architecture only (planning target; not approved for full
implementation in this task):

```text
Reports Center
|-- Sales Reports
|   |-- Sales Summary
|   |-- Daily Sales
|   |-- Sales by Hour / Weekday
|   |-- Product Mix
|   |-- Category Sales
|   |-- Payment Type Summary
|   `-- Branch Sales Comparison
|
|-- Inventory Reports
|   |-- Stock Visibility
|   |-- Low Stock / Reorder
|   |-- Inventory Movement Summary
|   |-- Stocktake Variance
|   |-- Unsold / Slow-Moving Items
|   `-- Product Composition Planning
|
|-- Cashier Accountability
|   |-- Shift Sales
|   |-- Drawer Declaration
|   |-- Cash Variance
|   |-- Refunds / Voids by Cashier
|   `-- Discount Usage
|
|-- Tax & Compliance Reports
|   |-- VAT Summary
|   |-- Senior/PWD Discount Summary
|   |-- Z-Reading Summary
|   |-- Settlement Summary
|   `-- Compliance Export Package
|
`-- Audit Reports
    |-- Transaction Audit Log
    |-- Void / Refund Audit
    |-- Price Change Audit
    |-- Inventory Adjustment Audit
    `-- Integration Failure Audit
```

## 6. Slice R1: Sales Summary Report Scope

### Slice Name

`Slice R1: Sales Summary Report`

### Objective

Create a manager-facing sales report that summarizes sales performance without
altering transactions, tax logic, settlement logic, or accounting sync behavior.

### Minimum Deliverables

1. KPI cards:
   - gross sales
   - net sales
   - transaction count
   - paid count
   - pending/created count
   - void/refund count (if available from existing data)
   - average transaction value
2. Filters:
   - date range
   - branch
   - status
   - payment type
   - cashier (if available from existing schema/data)
3. Breakdown sections:
   - payment breakdown
   - status breakdown
4. Outputs:
   - CSV export
   - print-friendly layout
5. Safety:
   - read-only/reporting behavior only
   - no transaction mutation
   - no settlement mutation
   - no tax recomputation behavior change
   - no accounting sync behavior change

## 7. Data Source Review Required Before Implementation

Before Slice R1 implementation, inspect and document current available data for:

1. sales
   - Available from `Sale` model and `SalesHistoryQueryService`.
2. sale items
   - Available from `SaleItem` relationship (`Sale::items()`).
3. payments
   - Available from `SalePayment` relationship (`Sale::payments()`).
4. branches
   - Available from `Sale::branch()` and branch-scoped query filtering.
5. users/cashiers
   - Available from `Sale::user()` and `cashier_id` filter support.
6. terminals
   - `sales_machine_profile_id` appears available on `Sale`; terminal-facing
     reporting linkage must be validated in R1 discovery.
7. transaction statuses
   - Available (`status` is currently used in filters and UI badges).
8. void/refund indicators
   - Available from status values and reversal fields (`is_reversal`,
     `reversal_reason`) where applicable.
9. settlement links
   - Deferred / unavailable from current source in this planning lock; explicit
     linkage must be verified before R1 if required.
10. tax fields
   - Available as stored values on `Sale` (`tax_total`, aggregated tax fields).

Rules:

1. Do not add new schema in this planning lock.
2. If a required field is not available from current sources, mark it as:
   `Deferred / unavailable from current source`.

## 8. Permission and Tenant Boundary

Required access behavior:

1. Reports must remain tenant-scoped.
2. Branch filtering must respect user permissions and branch assignments.
3. Unauthorized users must not access reports.
4. Users without broader branch access must not see other branch data.
5. Exports must use the same permission boundary as the screen report.

Current baseline references:

1. `sales.history.index` uses `permission:view_sales_history`.
2. `sales.history.export` uses `permission:export_sales_history`.
3. `sales.history.show` uses `permission:view_sale_details`.
4. `SalesHistoryQueryService::applyBranchScoping()` enforces fail-closed branch
   isolation based on user permissions and assigned branches.

## 9. Explicit Non-Goals

1. No tax engine changes.
2. No settlement mutation.
3. No accounting sync changes.
4. No BIR certification claim.
5. No scheduled report automation yet.
6. No PDF generation unless separately approved later.
7. No receipt, Z-read, GCT, e-journal, or official compliance format changes.
8. No transaction editing, deleting, reposting, or recalculation.
9. No inventory mutation.
10. No procurement automation.
11. No recursive recipe deduction.

## 10. Acceptance Criteria For This Planning Lock

The planning lock is complete when:

1. Current `Transaction History` is formally reclassified as `Transaction Audit
   Log`.
2. Reports Center information architecture is documented.
3. Slice R1 scope is defined.
4. Non-goals are explicitly listed.
5. Required data source review is listed.
6. Permission and tenant boundaries are listed.
7. No runtime code is changed.
8. No test run is required unless governance requires documentation validation.

## 11. Recommended Next Step After Acceptance

After this planning lock is reviewed and accepted, proceed to:

`Slice R1: Sales Summary Report Implementation`

Do not proceed to scheduled reports until core report surfaces are stable.

## 12. Before Execution Checklist (Completed for This Planning Task)

Reviewed:

1. Existing reporting routes.
2. Existing sales/transaction controllers.
3. Existing transaction history page/component.
4. Existing CSV export logic.
5. Existing report permissions.
6. Existing tenant/branch scoping patterns.
7. Existing roadmap and governance files.

## 13. Planning-Task Boundary Confirmation

Confirmed for this planning lock task:

1. No runtime routes were modified.
2. No React/Inertia runtime pages were modified.
3. No controllers/services were created or modified.
4. No schema changes were introduced.
5. No tax/settlement/accounting behavior was altered.
6. No scheduling/report automation was implemented.

## 14. Slice R1 Implementation Closure Evidence

Implemented:

1. New read-only route group:
   - `reports.sales-summary.index`
   - `reports.sales-summary.export`
2. New controller:
   - `app/Http/Controllers/Reports/SalesSummaryReportController.php`
3. New service:
   - `app/Services/Sales/SalesSummaryReportService.php`
4. New Inertia page:
   - `resources/js/Pages/Reports/SalesSummary/Index.jsx`
5. New focused tests:
   - `tests/Feature/Reports/SalesSummaryReportTest.php`

Validation:

1. `php artisan test tests/Feature/Reports/SalesSummaryReportTest.php tests/Feature/Sales/SalesHistoryControllerTest.php tests/Feature/Sales/SalesHistoryExportTest.php`
   - 10 tests passed.
   - 114 assertions passed.
2. `npm run build`
   - passed.

Closure report:

1. `docs/validation/sales-summary-report-closure.md`

Next recommended gate:

1. `Slice R2: Transaction Audit Log Hardening`

## 15. Slice R2 Implementation Closure Evidence

Implemented:

1. Repositioned the visible sales history UI as `Transaction Audit Log`.
2. Added read-only audit helper text.
3. Added a link from the audit log to the Sales Summary Report.
4. Added existing transaction UUID, cashier, and terminal/profile context to the audit table.
5. Preserved existing sales history routes, permissions, filters, export behavior, and detail behavior.

Validation:

1. `php artisan test tests/Feature/Sales/SalesHistoryControllerTest.php tests/Feature/Sales/SalesHistoryExportTest.php tests/Feature/Reports/SalesSummaryReportTest.php`
   - 10 tests passed.
   - 126 assertions passed.
2. `npm run build`
   - passed.

Closure report:

1. `docs/validation/transaction-audit-log-hardening-closure.md`

Next recommended gate:

1. `Slice R3: Product Mix Report`
