# Story 17.1: Cashier Accountability Scope Lock

Status: Completed 2026-05-17

Implementation status summary:
- Slice 1: Completed 2026-05-17 (Vocabulary & Reporting Boundary Lock)
- Slice 2: Completed 2026-05-17 (Transaction & Reversal Alignment Lock)
- Slice 3: Completed 2026-05-17 (CSV Export & Shift Detail Schema Lock)
- Slice 4: Completed 2026-05-17 (RBAC & Multi-Tenant Access Lock)
- Slice 5: Completed 2026-05-17 (Approval & Audit Trail Lock)

## 1. Goal and Purpose

Freeze the reporting contract, source-of-truth mapping, and high-precision formulas for cashier accountability and shift reporting before implementing any database query services or CSV export packages.

This scope lock guarantees that the reporting layer strictly adheres to a **read-only boundary**, maintaining perfect tenant and branch isolation, preventing any financial/operational data mutations, and ensuring complete alignment with settlement-locked periods (Epic 9).

## 2. Why This Story Is Next

With the closure of the **Tax Compliance Hardening (Slices A–E)**, the application has successfully aligned checkout calculations, sale records, and back-office exports around a Philippine VAT-inclusive model. The next logical operational priority is **Cashier Accountability & Shift Report Export (Epic 17)**. 

To prevent architectural drift, mathematical inaccuracies, or role-based leakage, we must lock down the reporting schemas, data aggregation contracts, and formulas first.

Current anchor surfaces to inspect:
- `app/Models/Shift.php` (Core shift records, status, and cash details)
- `app/Models/CashDrawerEvent.php` (Immutable operational events: pay-ins, pay-outs, drops)
- `app/Models/SalePayment.php` (Sale payments with direct `shift_id` mapping)
- `app/Models/Sale.php` (Financial aggregates and gross/net breakdown)
- `app/Services/Shift/ShiftService.php` (DRAWER event recording and basic expected cash computation)

---

## 3. Scope Review Outcome & Boundaries

### In-Scope (Approved)
1. **Immutable Shift Accountability Contract**: Standardized data structure for both live (open) and historical (closed/approved) shifts.
2. **Formula Lock**: High-precision, server-side `bcmath` aggregation formulas covering Cash-In, Cash-Out, Cash Sales, Non-Cash Sales, Gross Sales, Discounts, Refunds, Voids, and Net Sales.
3. **CSV Schema & Header Contract**: Strict field definitions for accounting ingestion with automatic header/field sanitization.
4. **Temporal Reversal Mapping**: Method for matching refunds and voids to their active operational shift based on creation timestamps, solving the `PaymentReversal` lack of direct `shift_id` mapping.
5. **Multi-Tenant / Multi-Branch Security Matrix**: Explicit RBAC boundaries ensuring Branch Managers and Cashiers are strictly isolated to their assigned resources.
6. **Settlement Lock Coupling**: Visual representation of settlement periods, ensuring adjustments and reopens are blocked if locked.

### Out-of-Scope (Explicitly Blocked)
- **Financial/Stock Mutation**: The reporting layer must be **100% read-only**; no transaction, shift state, or stock level may be altered.
- **Accounting Outbox Triggers**: Reports must never create, modify, or retry outbox logs.
- **Direct QuickBooks Queries**: No direct API fetches to QBO; the system database remains the sole source of truth.
- **BIR Certification / Accreditation Claims**: All reports are for internal operational audit only.
- **Direct Mutation of Closed/Locked Shifts**: Adjustments must follow existing stocktake/adjustment or reversal workflows.

---

## 4. Final Reporting Contract (L-36-48 Locked)

| Section | Purpose | Source Table/Model | Required Fields | Formula (if applicable) | Visibility |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Shift Header** | Identification & Status | `shifts`, `branches` | `id`, `status`, `branch_id` | N/A | All Roles |
| **Cashier Info** | Accountability Identity | `users` | `name`, `username` | N/A | All Roles |
| **Branch Info** | Location Context | `branches` | `name`, `code` | N/A | All Roles |
| **Shift Timeline** | Operational Duration | `shifts` | `opened_at`, `closed_at`, `approved_at` | N/A | All Roles |
| **Sales Summary** | Revenue Overview | `sales` | `gross_sales_amount`, `discount_total`, `total`, `is_reversal` | See Formula Lock | All Roles |
| **Payment Mix** | Audit of Revenue Mix | `sale_payments` | `payment_type`, `amount` | Sum by type | All Roles |
| **Drawer Summary** | Cash Flow Traceability | `cash_drawer_events` | `event_type`, `amount` | Sum by type | Manager/Owner |
| **Cash Variance** | Reconciliation Audit | `shifts` | `opening_cash_amount`, `expected_cash_amount`, `counted_cash_amount`, `variance_amount` | See Formula Lock | Manager/Owner |
| **Drawer Timeline** | Sequential Audit Log | `cash_drawer_events` | `occurred_at`, `event_type`, `amount`, `reason_code` | N/A | Manager/Owner |
| **Metadata** | Document Provenance | System | `generated_at`, `generated_by` | N/A | All Roles |

---

## 5. Final CSV Ingestion Contract (L-51-75 Locked)

| Column Name | Status | Source / Notes | Sanitization Rule |
| :--- | :--- | :--- | :--- |
| `shift_id` | Supported | `shifts.id` | Standard alphanumeric UUID validation |
| `branch_name` | Supported | `branches.name` | Excel injection sanitization (prepend `'` if starting with `=`, `+`, `-`, `@`) |
| `cashier_name` | Supported | `users.name` | Excel injection sanitization |
| `opened_at` | Supported | `shifts.opened_at` | ISO 8601 UTC timestamp format |
| `closed_at` | Supported | `shifts.closed_at` | ISO 8601 UTC timestamp format |
| `status` | Supported | `shifts.status` | Plain string value (`open`, `closed`, etc.) |
| `opening_cash` | Supported | `shifts.opening_cash_amount` | Formatted decimal string (4 decimal places) |
| `cash_in` | Supported | `cash_drawer_events` (CASH_IN + CASH_TOP_UP) | Formatted decimal string (4 decimal places) |
| `cash_out` | Supported | `cash_drawer_events` (CASH_DROP + CASH_OUT) | Formatted decimal string (4 decimal places) |
| `cash_sales` | Supported | `sale_payments` (CASH only) | Formatted decimal string (4 decimal places) |
| `non_cash_sales` | Supported | `sale_payments` (non-CASH methods) | Formatted decimal string (4 decimal places) |
| `gross_sales` | Supported | `sales` (Sum of gross values) | Formatted decimal string (4 decimal places) |
| `discounts` | Supported | `sales` (Sum of discounts) | Formatted decimal string (4 decimal places) |
| `refunds` | Supported | `payment_reversals` (Temporal overlap mapping) | Formatted decimal string (4 decimal places) |
| `voids` | Supported | `payment_reversals` (Temporal overlap mapping) | Formatted decimal string (4 decimal places) |
| `net_sales` | Supported | Computed: Gross - Discounts - Voids - Refunds | Formatted decimal string (4 decimal places) |
| `expected_cash` | Supported | Stored/Computed cash expected in drawer | Formatted decimal string (4 decimal places) |
| `declared_cash` | Supported | `shifts.counted_cash_amount` | Formatted decimal string (4 decimal places) |
| `cash_variance` | Supported | `shifts.variance_amount` | Formatted decimal string (4 decimal places) |
| `drawer_event_count` | Supported | Count of matching cash drawer event records | Numeric string |
| `generated_at` | Supported | Generation timestamp (Asia/Manila) | ISO 8601 Format |
| `generated_by` | Supported | Logged-in actor executing the export | Excel injection sanitization |

---

## 6. High-Precision Mathematical Formula Lock (L-78-90 Locked)

All calculations must be performed using **server-side `bcmath` operations with a scale of 4** to eliminate floating-point drift:

1. **Cash In** = Sum of `cash_drawer_events.amount` where `event_type` in (`opening_cash`, `cash_in`, `cash_top_up`) during shift window.
2. **Cash Out** = Sum of `cash_drawer_events.amount` where `event_type` in (`cash_drop`, `cash_out`) during shift window.
3. **Cash Sales** = Sum of `sale_payments.amount` where `payment_type = 'cash'` AND `status = 'paid'` and `shift_id = target_shift_id`.
4. **Non-Cash Sales** = Sum of `sale_payments.amount` where `payment_type != 'cash'` AND `status = 'paid'` and `shift_id = target_shift_id`.
5. **Gross Sales** = Sum of `sales.gross_sales_amount` where `is_reversal = false` for sales matching the shift.
6. **Discounts** = Sum of `sales.discount_total` where `is_reversal = false` for sales matching the shift.
7. **Refunds** = Sum of `payment_reversals.amount` where `reversal_type = 'refund_reversal'` and `reversed_at` overlaps shift timeline.
8. **Voids** = Sum of `payment_reversals.amount` where `reversal_type = 'void_reversal'` and `reversed_at` overlaps shift timeline.
9. **Net Sales** = `Gross Sales` - `Discounts` - `Refunds` - `Voids` (calculated using `bcsub`).
10. **Expected Cash** = `Opening Cash` + `Cash In` - `Cash Out` + `Cash Sales` - `Refunds (Cash only)`.
11. **Declared Cash** = `shifts.counted_cash_amount` (when closed).
12. **Cash Variance** = `Declared Cash` - `Expected Cash` (calculated using `bcsub`).

---

## 7. Reversal Resolution: Temporal Overlap Mapping

Because `payment_reversals` do not contain a native `shift_id` field, they must be matched to a shift using a strict **chronological overlap rule**:

A reversal record $R$ belongs to shift $S$ if:
$$S.\text{opened\_at} \le R.\text{reversed\_at}$$

AND
$$\text{either } R.\text{reversed\_at} < S.\text{closed\_at} \quad (\text{if shift is closed})$$
$$\text{or } R.\text{reversed\_at} < S.\text{closing\_submitted\_at} \quad (\text{if closing submitted})$$
$$\text{or } S.\text{status} = \text{'open'} \quad (\text{using current system timestamp } \text{now}() \text{ as upper bound})$$

This is operationally correct because if a cashier initiates a refund or void, the cash is taken out of their *active drawer* during the active shift hours.

---

## 8. Role-Based Access Control (RBAC) Matrix (L-104-118 Locked)

| Role | Own Shift Views | Branch Report View | CSV Export | Multi-Branch Access |
| :--- | :--- | :--- | :--- | :--- |
| **Cashier** | **View Only** | Blocked (403) | Blocked (403) | Blocked (403) |
| **Branch Manager** | **View Only** | **View / Export** | **Authorized** | Branch Scope Only |
| **Owner / Admin** | **View Only** | **View / Export** | **Authorized** | Tenant-Wide |
| **Auditor** | **View Only** | **View Only** | **Authorized** | Branch/Tenant |

Required application permissions:
- `reports.cashier-accountability.view`: Allows cashiers to view their own active/past shifts.
- `reports.cashier-accountability.export`: Gated to managers/owners/auditors for CSV downloads.
- `reports.shift-summary.view`: Allows managers/owners/auditors to view summaries across cashiers.
- `reports.shift-summary.export`: Gated for consolidated downloads.

---

## 9. Design Guardrails & Test Planning

### Architecture Alignment
- **Strict Read-Only**: Ensure no HTTP methods other than `GET` are defined under the reporting routes.
- **Fail-Closed Isolation**: All database queries must automatically invoke the `tenant_id` global scope and force a strict `branch_id` check where role constraints apply.
- **CSV Injection Prevention**: Standardize header sanitization to strip or escape any potential Excel formula symbols.

### Test Matrix Requirement
- **Aggregation Accuracy Tests**: Verify total calculations against manual SQL seed values.
- **Multi-Tenant / Branch Separation Tests**: Prove a cashier in Branch A receives a 403 trying to view Branch B shifts.
- **Cross-Midnight Shift Rollovers**: Verify temporal mapping handles shifts spanning across calendar dates correctly.
- **Reversal Overlaps**: Validate that refunds completed after a shift is submitted are correctly counted in the next cashier's shift.

---

## 10. Exit Criteria

Story 17.1 is complete because:
- The cashier accountability report schema is frozen.
- High-precision aggregation formulas are locked.
- Reversal temporal resolution rules are formally documented.
- Export formats and RBAC permissions are standardized.
- The repository has a unified execution contract to build the Query Service (`ShiftAccountabilityQueryService.php`) against in Story 17.2.

---

### Story 17.1 Closure Attestation
Story 17.1 has formally locked the cashier accountability planning. This document serves as the absolute baseline for Story 17.2 execution.
