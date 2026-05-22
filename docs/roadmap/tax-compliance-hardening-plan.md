# Tax Compliance Hardening Plan (BIR Alignment)

**Status:** CLOSED & VALIDATED (May 2026)

## 1. Tax Category Contract
The existing `TaxCategory` schema and `tax_type` field already support the 3-tier Philippine tax matrix. We will formalize the following canonical values and their BIR-facing labels:

| System Value | BIR Label | Description |
| :--- | :--- | :--- |
| `vatable` | **VAT** | Vatable Sale (Standard 12% VAT) |
| `exempt` | **EXM** | VAT-Exempt Sale (e.g., SC/PWD covered items, basic necessities) |
| `zero-rated` | **ZRO** | Zero-Rated Sale (0% VAT export/qualified) |
| `non-vat` | **NONVAT** | Non-VAT Sale (For Non-VAT registered entities) |

**Seeder Requirement**: The `ConfigurationService` or `TaxCategorySeeder` must ensure these standard categories exist for every tenant during onboarding.

## 2. VAT-Inclusive Pricing Rule
To align with standard Philippine retail and F&B practices (and Mosaic/UTAK), IPOS will enforce **VAT-Inclusive Pricing** as the default.

*   **Rule**: The `selling_price` field in the database is the **Gross Price** the customer pays.
*   **Calculation (12% VAT)**:
    *   `Net of VAT = Gross Selling Price / 1.12`
    *   `VAT Amount = Gross Selling Price - Net of VAT`
*   **Current implementation check**: `SaleCreationService` currently uses VAT-exclusive (tax on top). **This must be corrected in Slice D.**

## 3. Margin Calculator Contract
The Product Edit/Create UI will be enhanced with an advisory "Margin Calculator" card. This provides immediate visibility into actual earnings after taxes.

**Fields to Display (Calculated in Frontend):**
*   **Selling Price (Gross)**: User input.
*   **Net of VAT Price**: `Gross / 1.12` (if VATable).
*   **VAT Amount**: `Gross - Net`.
*   **Cost Price**: Existing user input.
*   **Gross Margin**: `Gross - Cost`.
*   **Net-of-VAT Margin**: `Net of VAT - Cost`.
*   **Margin %**: `(Net-of-VAT Margin / Net of VAT) * 100`.

> [!IMPORTANT]
> This UI display is advisory for the owner. Server-side calculations in `SaleCreationService` and `SalesTaxReportingQueryService` remain the source of truth for financial records.

## 4. SC/PWD Guardrail Contract
Statutory discounts for Senior Citizens and PWDs require both a VAT-exemption and a 20% discount on the net-of-VAT amount.

*   **Representation**: Existing `is_discountable` (boolean) field.
*   **UI Label**: "Eligible for SC/PWD statutory discount"
*   **Helper Text**: "When enabled, this item may be included in Senior Citizen/PWD statutory discount computation, subject to applicable Philippine rules and transaction context."
*   **Guardrail**: Items marked `is_discountable = false` (e.g., promotional bundles, alcohol, tobacco) will be excluded from the 20% discount calculation even if an SC/PWD ID is presented.

## 5. Impact Analysis

| Module | Impact | Rationale |
| :--- | :--- | :--- |
| **Product CRUD** | **Directly Affected** | UI updates for margin calculator and clearer tax labeling. |
| **POS Checkout** | **Directly Affected** | Must correct `SaleCreationService` to use VAT-inclusive logic and implement SC/PWD placeholders. |
| **Sales Tax Computation** | **Directly Affected** | BIR reporting requires exact mapping to VAT/EXM/ZRO buckets. |
| **SC/PWD Discounts** | **Directly Affected** | New logic required to handle VAT removal + 20% discount. |
| **Sales Reports** | **Indirectly Affected** | Aggregations must reflect corrected VAT-inclusive math. |
| **BIR Exports** | **Directly Affected** | Data must match the 3-tier classification exactly. |
| **Inventory** | **Not Affected** | Tax does not impact stock quantities. |
| **Accounting** | **Indirectly Affected** | Mapping to External IDs (QuickBooks) may need adjustment for tax codes. |

## 6. Implementation Slices & Status

### Slice A — Tax Category Contract & Seeder Hardening [COMPLETED - VALIDATED]
*   **Objective**: Establish stable BIR-aligned tax category canonical contract, model helper methods, and default seeder.
*   **Result**: 
    *   Added model helpers to `TaxCategory`: `isVatable()`, `isExempt()`, `isZeroRated()`, `isNonVat()`, `isVatBearing()`, `birCode()`, `displayLabel()`.
    *   Hardened `ConfigurationService::seedDefaults()` to idempotently seed all four canonical categories: `VAT`, `EXEMPT`, `ZERO-RATED`, `NON-VAT` (mapping to `vatable`, `exempt`, `zero-rated`, `non-vat` types).
    *   Validated via 11 passing tests with 70 assertions in `tests/Feature/TaxAndPaymentConfigTest.php`.

### Slice B — Checkout VAT-Inclusive Calculation Correction [COMPLETED - VALIDATED]
*   **Objective**: Correct POS checkout calculations from VAT-exclusive (tax-on-top) to VAT-inclusive pricing.
*   **Result**:
    *   Refactored `SaleCreationService` and associated tax/discount calculations to enforce standard Philippine VAT-inclusive extraction (`Net = Gross / 1.12`, `VAT = Gross - Net`).
    *   Hardened `CheckoutController` draft and sale creation processes to save tax breakdowns into the database matching these extraction rules.
    *   Validated via comprehensive POS and Epic 14 integration test runs.

### Slice C — Product Edit Margin Calculator UI [COMPLETED - VALIDATED]
*   **Objective**: Add advisory margins and standardized statutory discount labeling to Product CRUD forms.
*   **Result**:
    *   Implemented advisory margin calculation card in `Create.jsx` and `Edit.jsx` showing Gross price, Net-of-VAT price, VAT, cost, and net-of-VAT margins dynamically.
    *   Standardized the `is_discountable` form label to **"Eligible for SC/PWD statutory discount"** with clear helper tooltips explaining Philippine statutory rules.

### Slice D — Checkout Tax/Discount Consistency Tests [COMPLETED - VALIDATED]
*   **Objective**: Add comprehensive feature tests covering the full VAT-inclusive calculation space, mixed cart separation, and SC/PWD boundary logic.
*   **Result**:
    *   Wrote exact 100 pesos gross-price extraction and multiplication tests verifying standard 12% VAT splits.
    *   Added four-way mixed-cart category tests (Vatable, Exempt, Zero-Rated, Non-VAT) to confirm non-collapse boundary constraints.
    *   Added `is_discountable` flag preservation safety tests for statutory senior citizen / PWD exemptions.
    *   Added zero-mutation validation tests to guarantee checkout has no inventory or payment side effects.
    *   Validated via 7 passing tests with 125 assertions in `tests/Feature/Epic14/CheckoutTaxHardeningTest.php`.

### Slice E — Reporting & Export Alignment [COMPLETED - VALIDATED]
*   **Objective**: Align final sales and BIR reporting modules and exports with the stored VAT-inclusive data structures.
*   **Result**:
    *   Verified `SalesTaxReportingQueryService` consumes the stored VAT-inclusive sale and item values without dynamic exclusive re-calculations.
    *   Proved CSV export files produced by `ComplianceExportPackageService` and `ComplianceCsvExportService` retain matching columns for all categories without mutating state.
    *   Created [TaxReportingInclusiveAlignmentTest.php](file:///Users/teamsolo/Documents/Dev/IPOS/tests/Feature/Epic14/TaxReportingInclusiveAlignmentTest.php) validating all 10 alignment metrics with **8 tests / 42 assertions** passing.

### Final Verification Test Evidence
*   **TaxReportingInclusiveAlignmentTest**: **8 tests / 42 assertions** passed.
*   **Epic 14 Regression Suite**: **59 tests / 598 assertions** passed.
*   **POS CheckoutFlowTest Suite**: **5 tests / 35 assertions** passed.

## 7. Risks
1.  **Rounding Divergence**: Differences between frontend (JS) and backend (PHP/BCMath) rounding could lead to ₱0.01 discrepancies.
2.  **Historical Data**: Changing from Exclusive to Inclusive logic may affect existing test sales (needs migration or cleanup in dev).
3.  **Owner Misunderstanding**: Owners might accidentally underprice items if they don't realize the system now treats prices as VAT-inclusive.
4.  **SC/PWD Complexity**: Mixed carts (some items discountable, some not) require precise line-level tax adjustments.
