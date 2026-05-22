# Implementation Plan: Epic 17 — Cashier Accountability & Shift Report Export

**Status: CLOSED & VALIDATED**

## 1. Epic Overview
The objective of Epic 17 is to establish a comprehensive reporting and accountability layer for IPOS operations. Following the successful implementation of Shift Operations (Epic 12), Sales History (Epic 15), and Inventory Stocktake (Epic 16), Epic 17 consolidates this operational data into audit-ready reports. This module is strictly a reporting layer and will not introduce new mutation logic for financial transactions or inventory.

## 2. Scope Lock

### In-Scope
*   **Cashier Accountability Report**: High-density summary of individual cashier performance.
*   **Shift Summary Report (Z-Report style)**: Consolidated financial and operational snapshot for closed shifts.
*   **Cash Drawer Event Summary**: Timeline and aggregation of drops, top-ups, and in/out events.
*   **Cash Variance Summary**: Clear visibility into expected vs. counted vs. variance amounts.
*   **Payment Method Breakdown**: Distribution of sales across Cash, GCash, Maya, Card, etc.
*   **Sales & Reversal Summary**: Aggregation of gross sales, discounts, voids, and refunds.
*   **Exportable CSV Reports**: Standardized data format for back-office accounting ingestion.
*   **Print-friendly HTML Views**: Optimized layouts for physical archiving and physical shift logs.
*   **Branch & Tenant Isolation**: Strict multi-tenant and multi-branch data boundaries.

### Out-of-Scope
*   **Modifying Transaction Data**: No updates to `sales`, `sale_payments`, or `inventory_movements`.
*   **Modifying Shift State**: No ability to re-open or change closed shifts from this module.
*   **Payroll / Commissions**: No direct payroll computation or commission engine.
*   **Tax Certification**: This module is for operational auditing, not official BIR reporting (Epic 14).
*   **Manual Adjustments**: Any required adjustments must occur via existing Void/Refund/Stocktake workflows.

## 3. Proposed Stories / Slices

### Story 17.1 — Cashier Accountability Scope Lock [COMPLETED — SCOPE LOCKED]
*   **Goal**: Define the final reporting contract and authorization rules.
*   **Result**: Formulas locked, CSV columns finalized, RBAC matrix defined, and source-of-truth hierarchy established.

## 4. Final Report Contract [LOCKED]

| Section | Purpose | Source Table/Model | Required Fields | Formula (if applicable) | Visibility |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Shift Header** | Identification & Status | `shifts`, `branches` | `id`, `status`, `branch_id` | N/A | All |
| **Cashier Information** | Accountability Identity | `users` | `name`, `username` | N/A | All |
| **Branch Information** | Location Context | `branches` | `name`, `code` | N/A | All |
| **Shift Timeline** | Operational Duration | `shifts` | `opened_at`, `closed_at`, `approved_at` | N/A | All |
| **Sales Summary** | Revenue Overview | `sales` | `gross_sales_amount`, `discount_total`, `total`, `is_reversal` | See Formula Lock | All |
| **Payment Method Breakdown** | Audit of Revenue Mix | `sale_payments` | `payment_type`, `amount` | Sum by type | All |
| **Cash Drawer Event Summary** | Cash Flow Traceability | `cash_drawer_events` | `event_type`, `amount` | Sum by type | Manager/Owner |
| **Cash Variance Summary** | Reconciliation Audit | `shifts` | `opening_cash_amount`, `expected_cash_amount`, `counted_cash_amount`, `variance_amount` | See Formula Lock | Manager/Owner |
| **Drawer Event Timeline** | Sequential Audit Log | `cash_drawer_events` | `occurred_at`, `event_type`, `amount`, `reason_code` | N/A | Manager/Owner |
| **Metadata** | Document Provenance | System | `generated_at`, `generated_by` | N/A | All |

## 5. Final CSV Contract [LOCKED]

| Column | Status | Source / Notes |
| :--- | :--- | :--- |
| **Shift ID** | Supported | `shifts.id` |
| **Branch** | Supported | `branches.name` |
| **Cashier** | Supported | `users.name` |
| **Opened At** | Supported | `shifts.opened_at` |
| **Closed At** | Supported | `shifts.closed_at` |
| **Status** | Supported | `shifts.status` |
| **Opening Cash** | Supported | `shifts.opening_cash_amount` |
| **Cash In** | Supported | `cash_drawer_events` (Aggregated) |
| **Cash Out** | Supported | `cash_drawer_events` (Aggregated) |
| **Cash Sales** | Supported | `sale_payments` (Aggregated) |
| **Non-Cash Sales** | Supported | `sale_payments` (Aggregated) |
| **Gross Sales** | Supported | `sales.gross_sales_amount` (Aggregated) |
| **Discounts** | Supported | `sales.discount_total` (Aggregated) |
| **Refunds** | Supported | `payment_reversals.amount` via temporal overlap mapping |
| **Voids** | Supported | `payment_reversals.amount` via temporal overlap mapping |
| **Net Sales** | Supported | Computed: Gross - Discounts - Voids - Refunds |
| **Expected Cash** | Supported | `shifts.expected_cash_amount` (Stored) |
| **Declared Cash** | Supported | `shifts.counted_cash_amount` (Stored) |
| **Cash Variance** | Supported | `shifts.variance_amount` (Stored) |
| **Drawer Event Count** | Supported | `cash_drawer_events` (Count) |
| **Generated At** | Supported | System Timestamp |
| **Generated By** | Supported | Active User Name |

> [!NOTE]
> Although `payment_reversals` does not contain a native `shift_id`, Epic 17 resolves this using the locked temporal overlap rule from Story 17.1 and validated implementation in Story 17.2.

## 6. Formula Lock [LOCKED]

*   **Cash In**: Sum of `cash_drawer_events` where type in (`CASH_IN`, `CASH_TOP_UP`).
*   **Cash Out**: Sum of `cash_drawer_events` where type in (`CASH_DROP`, `CASH_OUT`).
*   **Cash Sales**: Sum of `sale_payments` where `payment_type = 'cash'` AND `status = 'paid'`.
*   **Non-Cash Sales**: Sum of `sale_payments` where `payment_type != 'cash'` AND `status = 'paid'`.
*   **Gross Sales**: Sum of `sales.gross_sales_amount` where `is_reversal = false`.
*   **Discounts**: Sum of `sales.discount_total` where `is_reversal = false`.
*   **Refunds**: Sum of `PaymentReversal.amount` where `reversal_type = 'refund_reversal'`. (Requires timestamp matching to shift).
*   **Voids**: Sum of `PaymentReversal.amount` where `reversal_type = 'void_reversal'`. (Requires timestamp matching to shift).
*   **Net Sales**: `Gross Sales` - `Discounts` - `Refunds` - `Voids`.
*   **Expected Cash**: `Opening Cash` + `Cash In` - `Cash Out` + `Cash Sales` - `Cash Refunds`.
*   **Declared Cash**: `shifts.counted_cash_amount`.
*   **Cash Variance**: `Declared Cash` - `Expected Cash`.

## 7. Source-of-Truth Decisions [LOCKED]

*   **Stored Shift Values**: For closed shifts, `expected_cash_amount` and `variance_amount` from the `shifts` table are the final accountability truth.
*   **Aggregated Values**: `Sales`, `Payments`, and `Drawer Events` are supporting data used for audit reconciliation and detailed breakdowns.
*   **Computed Values**: For open shifts, the report will use real-time aggregated values for "Expected Cash".

## 8. Settlement Lock Alignment [LOCKED]

*   Reports may read settlement-locked data for historical auditing.
*   Reports must not unlock or mutate any data.
*   Reports will display the **Settlement Status** if the shift date falls within a locked period.
*   Mutation paths (e.g. "Adjust Variance") are strictly blocked for locked periods.

## 9. RBAC Matrix [LOCKED]

| Role | Own Shift | Branch Reports | Export | Global Access |
| :--- | :--- | :--- | :--- | :--- |
| **Cashier** | View Only | NO | NO | NO |
| **Branch Manager** | YES | View/Export | YES | Branch Only |
| **Owner / Admin** | YES | View/Export | YES | Tenant-Wide |
| **Auditor** | YES | View Only | YES | Branch/Tenant |

**Planned Permissions**:
*   `reports.cashier-accountability.view`
*   `reports.cashier-accountability.export`
*   `reports.shift-summary.view`
*   `reports.shift-summary.export`

## 10. Guardrails [LOCKED]

*   **Read-Only**: Reporting layer will not mutate any operational or financial records.
*   **Immutability**: Closed shifts remain immutable; reports reflect captured values.
*   **Precision**: All financial aggregation must use server-side `bcmath` operations.
*   **Isolation**: Strict Tenant and Branch multi-tenancy enforcement.
*   **Sanitization**: All exports must sanitize headers and data to prevent CSV injection.

## 11. Risk Review & Mitigation

| Risk ID | Risk Description | Severity | Mitigation |
| :--- | :--- | :--- | :--- |
| **R-022** | Incorrect financial aggregation | High | Unit test query service with reversal scenarios; document `shift_id` gap. |
| **R-023** | Unauthorized cashier data exposure | Medium | Strict RBAC scope enforcement (Own-Only vs Branch-Scoped). |
| **R-024** | Cross-branch report leakage | High | Mandatory `BelongsToBranch` checks in all reporting queries. |
| **R-025** | Settlement lock bypass | Medium | Visual lock indicators; no mutation routes in reporting module. |

## 12. Story Statuses
*   **Story 17.1 — Cashier Accountability Scope Lock**: COMPLETED — SCOPE LOCKED
*   **Story 17.2 — Shift Accountability Backend Foundation**: COMPLETED — VALIDATED
*   **Story 17.3 — Cashier Accountability UI**: COMPLETED — VALIDATED
*   **Story 17.4 — Shift Report Export**: COMPLETED — VALIDATED
*   **Story 17.5 — RBAC, Audit, and Historical Integrity Hardening**: COMPLETED — VALIDATED

---

## 13. Completed Tasks Summary

### Story 17.3 — Cashier Accountability UI
*   **Status**: COMPLETED — VALIDATED
*   **Details**: Developed Shift Report Index with advanced branch/date/cashier filters. Built Show view with high-fidelity, high-density summary cards, payment method breakdowns, and chronological drawer event timelines. Integrated print-friendly utilities.

### Story 17.4 — Shift Report Export
*   **Status**: COMPLETED — VALIDATED
*   **Details**: Implemented `ShiftAccountabilityCsvExportService` with bcmath aggregation, date bounds protection, Excel injection sanitation (`=`, `+`, `-`, `@`), and UTF-8 BOM. Added permission-gated frontend action button.

### Story 17.5 — RBAC, Audit, and Historical Integrity Hardening
*   **Status**: COMPLETED — VALIDATED
*   **Details**: Hardened permission gates inside `CashierAccountabilityController` (`show` and `export`). Integrated `AuditLogger` for `'cashier_accountability_report_viewed'` and `'cashier_accountability_report_exported'`. Verified settlement lock alignment and created comprehensive adversarial and immutability test suites in `CashierAccountabilityHardeningTest`. All 11 tests / 36 assertions passed.

## 14. Final Validation & Test Evidence

### Validation Summary by Story
*   **Story 17.1 (Scope Lock)**: Scope lock completed, formulas locked, security matrix verified.
*   **Story 17.2 (Backend Query Service)**: ShiftAccountabilityQueryService implemented, temporal reversal boundary boundaries verified.
*   **Story 17.3 (Read-only UI)**: Index & Show React reporting views completed with print utility.
*   **Story 17.4 (CSV Export)**: Export integration complete with Excel injection protection & UTF-8 BOM.
*   **Story 17.5 (RBAC, Audit, & Historical Hardening)**: Custom AuditLogger integration, strict own-shift / branch restrictions, and settlement lock coupling verified.

### Automated Test Suite Execution Details
All tests passed with zero regressions:
*   **ShiftAccountabilityQueryServiceTest**: 11 tests / 34 assertions (**PASSED**)
*   **CashierAccountabilityUiTest**: 7 tests / 55 assertions (**PASSED**)
*   **CashierAccountabilityExportTest**: 6 tests / 16 assertions (**PASSED**)
*   **CashierAccountabilityHardeningTest**: 11 tests / 36 assertions (**PASSED**)
*   **Total Reporting & Hardening Suite**: 35 tests / 141 assertions (**PASSED**)

### Asset Compilation Status
*   **npm run build**: PASSED (compiled cleanly without warnings or errors).

## 15. Closure Sign-off & Ledger Updates
*   **Task Ledger**: G-023 status updated to `COMPLETED — VALIDATED` and moved to Completed.
*   **Risk Register**: Risks R-022 through R-025 fully mitigated.
*   **Release Readiness Checklist**: Cashier Accountability checklist entry resolved.
*   **Roadmap Status**: Epic 17 updated to `CLOSED & VALIDATED` in `validated-implementation-roadmap.md`.
