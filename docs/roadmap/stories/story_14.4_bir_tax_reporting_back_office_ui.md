# Story 14.4: BIR Tax Reporting Back-Office UI

Status: Completed

## Goal

Provide accountants, owners, and other authorized reporting users a read-only back-office surface for PH/BIR tax summaries backed entirely by `SalesTaxReportingQueryService`.

## Slice A: Read-Only UI Foundation

Implemented on 2026-05-13.

Delivered:

- read-only tax reporting route at `reports.tax.index`
- `TaxReportingController` using `SalesTaxReportingQueryService` as the only summary source of truth
- Inertia page at `Reports/Tax/Index` with basic date and branch filters
- summary cards covering the current reporting contract without frontend recomputation
- tenant-safe and branch-safe scope handling aligned to existing reporting conventions
- authorization using existing `view_reports` permission because no dedicated `view_tax_reports` permission exists yet
- tenant-wide branch visibility controlled by existing `view_multi_branch_dashboard` permission
- focused UI/backend feature coverage for auth, authorization, service usage, tenant isolation, branch isolation, safe filters, and read-only behavior

Slice A contract surfaced in UI:

- `gross_sales`
- `net_sales`
- `vatable_sales`
- `vat_exempt_sales`
- `zero_rated_sales`
- `non_vat_sales`
- `vat_amount`
- `statutory_discount_amount`
- `regular_discount_amount`
- `void_adjustment_amount`
- `refund_adjustment_amount`
- `reversal_adjustment_amount`
- `net_adjustment_amount`
- `transaction_count`
- `reversal_count`
- `void_count`
- `refund_count`
- `reviewed_period_count`
- `locked_period_count`
- `has_reviewed_period`
- `has_locked_period`

Scope and permission note:

- route access uses `permission:view_reports`
- tenant-wide branch selection is available only when the actor has `view_multi_branch_dashboard`
- branch-scoped users are restricted to their allowed branch set and receive `404` for disallowed branch filters
- no RBAC redesign was introduced in this slice

Out of scope for Slice A:

- export generation
- PDF or CSV download
- drill-down transaction detail pages
- checkout write-path wiring
- tax computation logic changes
- VAT reclassification logic
- statutory discount computation changes
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review or lock workflow
- broad UI redesign

Validation:

- `php artisan test tests/Feature/Epic14/TaxReportingBackOfficeUiTest.php` -> 6 passed / 92 assertions
- `php artisan test tests/Feature/Epic14` -> 28 passed / 278 assertions
- `php artisan test` -> 747 passed / 3535 assertions / 1 risky baseline unchanged
- `npm run build` -> passed

Current execution note:

- Slice A UI foundation is complete.
- Story 14.4 remains in progress for safe detail and breakdown presentation work.
- export generation remains unstarted.

## Slice B: Tax Reporting UI Detail Sections and Safe Breakdown Presentation

Implemented on 2026-05-13.

Delivered:

- grouped read-only UI sections for sales summary, tax buckets, discounts, adjustments/reversals, and review/lock awareness
- safer accountant-friendly labels for reviewed and locked period indicators
- section metadata passed from the controller using only the existing reporting contract fields
- frontend presentation that formats values only and does not recompute any tax totals
- clearer visual separation between base sales totals, discounts, adjustment totals, and review/lock indicators
- focused UI/backend feature coverage proving grouped section metadata, grouped contract usage, branch limits, and absence of export actions

Slice B presentation sections:

- `Sales Summary`
- `Tax Bucket Breakdown`
- `Discount Breakdown`
- `Adjustment / Reversal Breakdown`
- `Review / Lock Awareness`

Slice B contract usage note:

- all displayed values still come from `SalesTaxReportingQueryService`
- controller changes were limited to passing section metadata for presentation structure
- no query-service expansion was required

Out of scope for Slice B:

- PDF export
- CSV export
- print export
- transaction drill-down page
- transaction detail timeline
- checkout write-path wiring
- database writes
- tax computation logic changes
- statutory discount computation logic changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review or lock workflow
- broad UI redesign

Validation:

- `php artisan test tests/Feature/Epic14/TaxReportingBackOfficeUiTest.php` -> 6 passed / 131 assertions
- `php artisan test tests/Feature/Epic14` -> 28 passed / 317 assertions
- `php artisan test` -> 747 passed / 3574 assertions / 1 risky baseline unchanged
- `npm run build` -> passed

Current execution note:

- Slice A UI foundation is complete.
- Slice B grouped breakdown presentation is complete.
- Story 14.4 remains in progress for UI closure and permission/access hardening checkpoint work.
- export generation remains unstarted.

## Slice C: UI Closure and Permission/Access Hardening Checkpoint

Implemented on 2026-05-13.

Delivered:

- closure review over route protection, tenant scope, branch scope, grouped UI contract usage, and read-only behavior
- focused feature coverage confirming branch-scoped users only receive authorized branch options
- focused feature coverage confirming safe empty/zero-value rendering for the grouped tax reporting UI
- validation that `view_reports` remains the accepted access permission for Story 14.4
- confirmation that no dedicated `view_tax_reports` permission or RBAC redesign was introduced
- closure confirmation that no export actions, drill-down pages, or write-side behavior exist in Story 14.4

Slice C closure notes:

- unauthenticated users remain redirected to login
- users without `view_reports` remain blocked
- tenant-wide branch selection remains gated by `view_multi_branch_dashboard`
- unauthorized branch query parameters remain blocked with `404`
- branch filter options do not expose unauthorized branches
- frontend still formats only values returned by the backend reporting contract
- zero-value responses render safely across all grouped sections

Out of scope for Slice C:

- PDF export
- CSV export
- print export
- transaction drill-down page
- transaction detail timeline
- checkout write-path wiring
- database writes
- tax computation logic changes
- statutory discount computation logic changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review or lock workflow
- broad UI redesign
- new permissions unless a blocker exists

Validation:

- `php artisan test tests/Feature/Epic14/TaxReportingBackOfficeUiTest.php` -> 7 passed / 169 assertions
- `php artisan test tests/Feature/Epic14` -> 29 passed / 355 assertions
- `php artisan test` -> 748 passed / 3612 assertions / 1 risky baseline unchanged
- `npm run build` -> passed

Closure note:

- Story 14.4 is complete.
- The back-office now has a read-only BIR/PH tax reporting UI foundation with safe grouped breakdown presentation, permission-gated access, tenant/branch scoping, and no export or write-side behavior.