---
title: 'Story 37.6 Promotion Receipt and X/Z Reporting Integration'
type: 'feature'
created: '2026-07-14'
status: 'review'
context:
  - '{project-root}/docs/roadmap/epic-37-38-39-proposed-specifications.md'
  - '{project-root}/_bmad-output/implementation-artifacts/story-37.5-offline-promotion-cache-sync-validation.md'
---

## Intent

Close Epic 37 Story 37.6 by surfacing persisted commercial promotion snapshots in cashier receipts and reporting summaries. This slice is read-only over already-posted sales and does not change calculation, posting, refund, void, tax, or settlement behavior.

## Scope

**Included**

- Receipt API payload includes applied commercial promotions, promotion lines, and statutory/commercial discount totals.
- Receipt UI prints named promotion discounts and avoids double-counting them in the generic discount line.
- Shift X/Z summary payload includes promotion breakdown by promotion name and transaction count.
- Printable shift report displays statutory, commercial, and named promotion discount lines.
- Sales summary report exposes statutory/commercial discount KPI splits and promotion breakdown, including CSV export rows.

**Excluded**

- Refund/void reversal behavior for promotions; reserved for Story 37.7.
- New promotion calculation rules.
- Schema changes.
- Certified BIR Z-read format changes beyond existing stored commercial discount totals.

## Acceptance Criteria

- Given a sale with `sale_promotions`, when the receipt endpoint is called, then the payload includes promotion name, discount amount, and linked line details.
- Given a shift with promoted sales, when the X/Z shift summary is generated, then commercial discount totals remain separate and named promotion totals are included.
- Given a sales summary report, when promoted sales are visible, then statutory and commercial discount totals are split and promotion totals are grouped by promotion.
- Existing receipt, statutory discount, shift, and sales summary behavior remains unchanged.

## File List

- `app/Models/Sale.php`
- `app/Services/POS/ReceiptService.php`
- `app/Services/Shift/ShiftReportService.php`
- `app/Services/Sales/SalesSummaryReportService.php`
- `resources/js/Pages/POS/Components/Receipt.jsx`
- `resources/js/Pages/Shift/ZReport.jsx`
- `resources/js/Pages/Reports/SalesSummary/Index.jsx`
- `tests/Feature/POS/ReceiptTest.php`
- `tests/Feature/POS/StatutoryDiscountComplianceTest.php`
- `tests/Feature/Reports/SalesSummaryReportTest.php`

## Verification

- `php artisan test tests/Feature/POS/ReceiptTest.php tests/Feature/POS/StatutoryDiscountComplianceTest.php tests/Feature/Reports/SalesSummaryReportTest.php`

