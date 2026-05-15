# Story 14.5: Compliance Export Package

Status: In Progress

## Goal

Provide accountants and authorized reporting users with the ability to generate compliance-ready export packages (CSV/PDF) for PH/BIR tax summaries and supporting schedules, including audit metadata and filter criteria.

## Slice A: Compliance Export Scope Lock and Export Contract Foundation

Implemented on 2026-05-13.

Delivered:

- compliance export package service foundation (`ComplianceExportPackageService`)
- export package contract/payload preparation backed by `SalesTaxReportingQueryService`
- export metadata shape including `generated_at`, `tenant_id`, `branch_id`, `date_from`, `date_to`, `generated_by`, and `source`
- filter criteria snapshot preservation
- summary contract implementation using the reporting query service output
- redaction-safe headers and metadata preparation (no secrets, no raw provider payloads)
- non-certification note included in the export package
- focused backend feature coverage for package preparation, metadata, filter preservation, summary contract, and redaction rules

Slice A Export Contract Shape:

```json
{
    "metadata": {
        "generated_at": "...",
        "tenant_id": "...",
        "branch_id": "...",
        "date_from": "...",
        "date_to": "...",
        "generated_by": "...",
        "source": "sales_tax_reporting_query_service"
    },
    "filters": {
        "date_from": "...",
        "date_to": "...",
        "branch_id": "..."
    },
    "summary": {
        "gross_sales": "...",
        "net_sales": "...",
        "vatable_sales": "...",
        "vat_exempt_sales": "...",
        "zero_rated_sales": "...",
        "non_vat_sales": "...",
        "vat_amount": "...",
        "statutory_discount_amount": "...",
        "regular_discount_amount": "...",
        "void_adjustment_amount": "...",
        "refund_adjustment_amount": "...",
        "reversal_adjustment_amount": "...",
        "net_adjustment_amount": "...",
        "transaction_count": "..."
    },
    "notes": [
        "This export is system-generated from IPOS reporting data.",
        "This does not represent standalone BIR certification."
    ]
}
```

Redaction / Safety Rules:

- no raw provider payloads
- no OAuth tokens
- no Authorization headers
- no Bearer tokens
- no client secrets
- no raw customer sensitive details
- no raw cashier/session secrets
- no internal exception traces
- no debug/config values

Out of scope for Slice A:

- actual CSV file generation
- actual PDF generation
- actual file download route
- frontend export button
- print export
- transaction drill-down page
- checkout write-path wiring
- database writes
- tax computation changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review/lock workflow

Validation:

- `php artisan test tests/Feature/Epic14/ComplianceExportPackageServiceTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test`

Current execution note:

- Slice A export contract foundation is complete.
- Slice B CSV generation is complete.
- Slice C protected CSV download route and UI action is complete.
- Slice D closure and hardening is complete.
- Story 14.5 CSV baseline is complete.
- Story 14.5 remains in progress for PDF generation work.

## Slice D: Compliance Export Closure Checkpoint Before PDF Work

Implemented on 2026-05-13.

Delivered:

- export contract closure validation
- CSV generation safety confirmation (escaping, formula injection protection)
- protected CSV download route confirmation
- tenant and branch scoping hardening
- verified CSV redaction of sensitive internal data
- verified unauthorized branch/tenant access is blocked
- verified export link preserves filters
- full regression validation (764 tests / 3688 assertions)

CSV Export Closure Statement:

Story 14.5 CSV export baseline is complete. The system now has a safe compliance export package contract, CSV generation service, protected CSV download route, and UI export action backed by the read-only sales tax reporting query service.

Validation:

- `php artisan test tests/Feature/Epic14/ComplianceExportPackageServiceTest.php`
- `php artisan test tests/Feature/Epic14/ComplianceCsvExportServiceTest.php`
- `php artisan test tests/Feature/Epic14/ComplianceCsvDownloadTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test`
- `npm run build`

## Slice C: Protected CSV Download Route and Optional UI Export Action

Implemented on 2026-05-13.

Delivered:

- protected CSV download route (`reports.tax.export.csv`)
- controller action `exportCsv` in `TaxReportingController`
- permission gating (`view_reports`) and branch/tenant scoping
- safe filename generation (`ipos-tax-compliance-summary-YYYY-MM-DD-to-YYYY-MM-DD.csv`)
- "Export CSV" button on the tax reporting index page
- preservation of current filters (date range, branch) in the export link
- focused backend feature coverage for download access, scoping, and headers

Access Rules:

- unauthenticated users blocked
- unauthorized users (no `view_reports`) blocked
- branch-scoped users limited to their assigned branches
- tenant-wide authorized users can export all branches

Out of scope for Slice C:

- PDF generation
- print export
- transaction drill-down page
- transaction-level line export
- checkout write-path wiring
- database writes
- tax computation changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review/lock workflow
- broad UI redesign

Validation:

- `php artisan test tests/Feature/Epic14/ComplianceCsvDownloadTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test`
- `npm run build`

## Slice B: CSV Export Generation Using Approved Export Package Contract

Implemented on 2026-05-13.

Delivered:

- CSV export generation service (`ComplianceCsvExportService`)
- CSV content generation using only the approved `ComplianceExportPackageService` package contract
- clear CSV sections for Header, Metadata, Filters, Summary, and Notes
- CSV escaping and formula injection protection (prefixing risky characters with `'`)
- focused backend feature coverage for CSV generation, section formatting, escaping, and injection protection

Slice B CSV Section Layout:

- `IPOS Compliance Export` (Header)
- `Metadata` (Key, Value)
- `Filters` (Key, Value)
- `Summary` (Metric, Value)
- `Notes` (List)

CSV Safety Rules:

- escaped commas, quotes, and newlines
- formula injection protection for values starting with `=`, `+`, `-`, or `@`
- no secrets, tokens, or raw payloads included in CSV

Out of scope for Slice B:

- actual PDF generation
- actual file download route
- frontend export button
- print export
- transaction drill-down page
- transaction-level line export
- checkout write-path wiring
- database writes
- tax computation changes
- VAT reclassification logic
- settlement mutation
- accounting sync changes
- POS payload changes
- backfill scripts
- new review/lock workflow

Validation:

- `php artisan test tests/Feature/Epic14/ComplianceCsvExportServiceTest.php`
- `php artisan test tests/Feature/Epic14`
- `php artisan test`
