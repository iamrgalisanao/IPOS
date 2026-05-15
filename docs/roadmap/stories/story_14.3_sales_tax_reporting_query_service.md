# Story 14.3: Sales Tax Reporting Query Service

Status: Completed

## Goal

Build the read-only backend query foundation for PH tax summaries so later Epic 14 reporting, export, and UI slices consume a stable source-of-truth reporting contract.

## Slice A: Query Contract and Read-Only Foundation

Implemented on 2026-05-13.

Delivered:

- `SalesTaxReportingQueryService` read-only summary contract
- tenant-scoped tax summary support
- branch-scoped tax summary support
- date-range filtering using `COALESCE(reporting_basis_at, confirmed_at)` for sale-period boundaries
- VAT bucket totals sourced from `sale_items`
- statutory discount totals sourced from `sale_statutory_discounts`
- refund and void adjustment totals exposed as read-only summary fields
- focused feature coverage proving the query remains read-only and does not mutate records

Current contract fields:

- `tenant_id`
- `branch_id`
- `date_from`
- `date_to`
- `gross_sales`
- `net_sales`
- `vatable_sales`
- `vat_exempt_sales`
- `zero_rated_sales`
- `non_vat_sales`
- `vat_amount`
- `statutory_discount_amount`
- `regular_discount_amount`
- `refund_adjustment_amount`
- `void_adjustment_amount`
- `transaction_count`

Out of scope for Slice A:

- checkout write-path wiring
- writes to Epic 14 tax fields
- tax computation logic changes
- statutory discount computation changes
- VAT reclassification logic
- export generation
- UI pages

Validation:

- `php artisan test tests/Feature/Epic14/SalesTaxReportingQueryServiceTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test tests/Feature/Settlement/SettlementVarianceQueryTest.php`
- `php artisan test`

## Slice B: Adjustments, Reversals, and Reviewed-Period Read-Only Coverage

Implemented on 2026-05-13.

Delivered:

- read-only `reversal_adjustment_amount` support from `payment_reversals`
- read-only `net_adjustment_amount` support combining refund, void, and reversal adjustments
- adjustment counts for `refund_count`, `void_count`, and `reversal_count`
- reviewed/locked-period awareness from overlapping `settlement_periods`
- boolean flags `has_reviewed_period` and `has_locked_period`
- focused feature coverage proving adjustment totals and counts remain tenant-safe, branch-safe, date-range-scoped, and read-only

Current contract additions:

- `reversal_adjustment_amount`
- `net_adjustment_amount`
- `refund_count`
- `void_count`
- `reversal_count`
- `reviewed_period_count`
- `locked_period_count`
- `has_reviewed_period`
- `has_locked_period`

Reviewed-period contract note:

- `reviewed_period_count` currently counts overlapping settlement periods in `in_review` or `approved` status.
- `locked_period_count` currently counts overlapping settlement periods in `locked` status.
- No new review or lock workflow was introduced; the query only reads existing settlement state.

Out of scope for Slice B:

- settlement mutation
- checkout or write-path changes
- export generation
- UI pages

Validation:

- `php artisan test tests/Feature/Epic14/SalesTaxReportingQueryServiceTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test tests/Feature/Settlement/SettlementVarianceQueryTest.php`
- `php artisan test`

Current execution note:

- Slice A query foundation is complete.
- Slice B adjustment, reversal, and reviewed/locked-period read-only coverage is complete.
- Story 14.3 remains read-only and query-service focused.
- UI, export, and checkout/write-path behavior remain unstarted.

## Slice C: Reporting Query Contract Hardening and Closure

Implemented on 2026-05-13.

Delivered:

- explicit zero-default reporting contract shape for empty result sets
- focused contract-key coverage proving the summary returns the complete expected field set
- empty-state coverage proving no-match windows return safe zeroed values and false boolean flags
- date-boundary coverage proving exact start/end boundaries remain inclusive for sale-period filtering
- focused coverage proving adjustment totals remain separate from base sales totals when adjustments fall outside the queried window
- regression closure for tenant-safe, branch-safe, read-only reporting behavior across Slices A through C

Final contract fields:

- `tenant_id`
- `branch_id`
- `date_from`
- `date_to`
- `gross_sales`
- `net_sales`
- `vatable_sales`
- `vat_exempt_sales`
- `zero_rated_sales`
- `non_vat_sales`
- `vat_amount`
- `statutory_discount_amount`
- `regular_discount_amount`
- `refund_adjustment_amount`
- `void_adjustment_amount`
- `reversal_adjustment_amount`
- `net_adjustment_amount`
- `refund_count`
- `void_count`
- `reversal_count`
- `reviewed_period_count`
- `locked_period_count`
- `has_reviewed_period`
- `has_locked_period`
- `transaction_count`

Out of scope for Slice C:

- UI pages
- export generation
- checkout write-path wiring
- writes to sales or sale item tax fields
- tax computation behavior changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review or lock workflow

Validation:

- `php artisan test tests/Feature/Epic14/SalesTaxReportingQueryServiceTest.php` -> 6 passed / 97 assertions
- `php artisan test tests/Feature/Epic14` -> 22 passed / 186 assertions
- `php artisan test tests/Feature/Settlement/SettlementVarianceQueryTest.php` -> 14 passed / 45 assertions
- `php artisan test` -> 741 passed / 3443 assertions / 1 risky baseline unchanged

Closure note:

- Story 14.3 is complete.
- The backend now has a read-only sales tax reporting query service covering tenant, branch, date-range, VAT bucket, statutory discount, adjustment/reversal, and reviewed/locked-period summary behavior.