# BIR Compliance Gap Analysis

Last updated: 2026-05-13
Status: Planning baseline

## Purpose

This document compares the current IPOS repository against the practical BIR-oriented POS baseline identified during Epic 14 planning.

It is not a legal certification. It is a repository-level implementation gap analysis used to prioritize Epic 14 work.

## Research Baseline Used

Primary rule and implementation inputs:

- BIR RMO 24-2023 digest on CRM/POS and similar sales machines/software
- current invoice-first implementation expectations after the EOPT transition
- RR 8-2023 treatment of SC/PWD online substantiation
- common PH POS implementation patterns for statutory discount evidence, e-journal style logs, and audit-ready reports

## Current Strengths

### Immutable sale evidence already exists

- `sales` prevents mutation of financial totals after creation.
- `sale_items` are immutable snapshots of sold product, price, and tax fields.
- `RefundService` and `VoidService` use additive reversal records instead of mutating original sale evidence destructively.

Repository anchors:

- `app/Models/Sale.php`
- `app/Models/SaleItem.php`
- `app/Services/POS/RefundService.php`
- `app/Services/POS/VoidService.php`

### Generic tax snapshots already exist

- `SaleCreationService` copies `tax_category_id`, `tax_type`, `tax_rate`, and `tax_amount` into `sale_items`.
- `PaymentRecordingService` groups item tax totals by tax category for downstream accounting payloads.

Repository anchors:

- `app/Services/POS/SaleCreationService.php`
- `app/Services/POS/PaymentRecordingService.php`

### Basic receipt identity exists

- `ReceiptService` already surfaces branch code, business registration number, line items, totals, and payment rows.

Repository anchor:

- `app/Services/POS/ReceiptService.php`

## Major Gaps

### Gap 1: No machine accreditation or permit data model

BIR-oriented POS implementations normally need repository support for machine identification, serial or software identifiers, supplier accreditation data, and PTU or ATG references.

Current state:

- no model or persisted fields for machine profile, MIN, PTU, ATG, supplier accreditation, or machine serial identity

Impact:

- invoice outputs cannot be reconstructed into a BIR-style machine or permit evidence trail
- later receipt and export features would have to invent these fields dynamically or ignore them

### Gap 2: No invoice-first principal document contract

Current PH practice is invoice-first, but the current repository still exposes a generic receipt data shape.

Current state:

- `ReceiptService` returns generic receipt data
- no explicit principal invoice number, invoice label, invoice type, or invoice issuance timestamp on `sales`

Impact:

- Epic 14 reporting cannot safely assume whether the principal document is a compliant invoice versus a generic receipt representation

### Gap 3: No statutory discount evidence model

Current state:

- `discount_amount` and `discount_total` exist, but POS sale creation still uses a zero-value placeholder for discounts
- no beneficiary name, ID number, TIN, discount type, VAT-exempt amount, or statutory discount metadata are persisted

Repository anchors:

- `app/Services/POS/SaleCreationService.php`
- `database/migrations/2026_05_10_164800_create_sales_table.php`
- `database/migrations/2026_05_10_164810_create_sale_items_table.php`

Impact:

- SC/PWD-style compliance reporting is impossible without adding first-class evidence tables or fields
- later discount implementation risks mixing statutory and commercial discounts in one bucket

### Gap 4: No PH mixed-sale bucket persistence

Current state:

- line items store generic tax fields only
- headers store only subtotal, tax total, discount total, and grand total
- no persisted transaction-level buckets for VATable, VAT-exempt, zero-rated, or non-VAT sales

Impact:

- Story 14.3 would need to derive PH reporting buckets from generic fields on the fly, which is brittle once discount and statutory rules expand

### Gap 5: No compliance-aware reversal timing contract

Current state:

- settlement summaries use `confirmed_at`, `paid_at`, `refunded_at`, and `voided_at`
- reversals are additive, which is good, but there is no compliance-specific indication of whether a reversal affects the current period or a previously reviewed period

Repository anchor:

- `app/Services/Settlement/SettlementSummaryQueryService.php`

Impact:

- reopened-period disclosure and prior-period tax adjustments remain ambiguous

### Gap 6: No BIR-style audit-report layer

Current state:

- observability and audit logging exist for application events
- no X/Z reading layer, no e-journal export contract, and no statutory discount books or backend BIR report surface

Impact:

- the repository can support internal controls, but not yet an audit-ready BIR-style reporting package

### Gap 7: No reprint tracking or receipt evidence lineage

Current state:

- `ReceiptService` is read-only but does not track reprints or issue lineage metadata

Impact:

- later audit surfaces cannot explain whether a printed output is original, reprinted, or superseded

## Medium Gaps

### Gap 8: Tenant and branch identity fields are incomplete for invoice-grade output

Current state:

- tenant stores `business_registration_number`
- branch stores `branch_code`
- no explicit VAT/NON-VAT registration marker, seller TIN with branch code, or supplier footer details are modeled in the sale evidence path

Repository anchors:

- `app/Models/Tenant.php`
- `app/Models/Branch.php`

### Gap 9: No explicit separation between statutory and commercial discounts

Current state:

- discount fields are generic only
- Epic 19 owns commercial discount governance, but the repository does not yet have a persistence boundary separating statutory compliance discounts from commercial promotions

Impact:

- future reporting can become polluted by promotional logic unless Story 14.2 defines separate structures first

## Alignment Summary

### Already aligned enough to build on

- immutable sales and sale items
- append-only reversal model
- generic tax snapshots
- branch and tenant scoping
- existing reporting and export patterns in settlement services

### Must be addressed before Epic 14 reporting UI or export work

1. machine and permit profile modeling
2. principal invoice contract
3. statutory discount evidence model
4. PH mixed-tax bucket persistence
5. compliance-aware reversal impact rules

## Recommended Implementation Order

1. Use the completed Story 14.1 scope lock as the controlling Epic 14 planning baseline.
2. Execute Story 14.2 to add immutable compliance header, line, beneficiary, and machine-profile fields.
3. Build Story 14.3 queries only after the repository can reconstruct PH buckets without reading presentation-layer receipt text.
4. Build Story 14.4 through 14.6 on top of the hardened data contract rather than re-encoding compliance rules in the UI.

## Decision Rule

Do not treat Epic 14 as only a reporting-dashboard effort.

The repository can support PH tax reporting only if invoice evidence, statutory discount evidence, and mixed-bucket persistence are defined as source-of-truth data first.