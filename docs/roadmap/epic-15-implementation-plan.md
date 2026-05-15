# Implementation Plan: Epic 15 — Sales & Transaction History Back Office
**Status: Slices A, B, C, D — COMPLETED & VALIDATED**

## Objective
Establish a comprehensive back-office interface for auditing and reviewing historical transactions (sales, voids, refunds, payments) with strict tenant isolation and role-based access controls.

---

## 1. Scope and Strategy
*   **Target Domain**: Transactional History (`Sales`, `SalePayments`, `SaleVoids`, `SaleRefunds`).
*   **Architecture**: Read-only query services and repositories, Inertia-driven UI, and append-only audit logging for sensitive views.
*   **Constraints**:
    *   Fail-closed tenant and branch isolation.
    *   No mutation of historical records (reversals are already append-only).
    *   Redaction of sensitive data in exports where applicable.

---

## 2. Story Breakdown & Slicing

### Slice A: Query Foundation & Access Rules (Story 15.1, 15.2)
*   **Goal**: Create a unified query service for historical transactions.
*   **Tasks**:
    *   Define `view_transaction_history` and `view_transaction_details` permissions.
    *   Implement `SalesHistoryQueryService` with support for:
        *   Pagination.
        *   Filtering: `date_range`, `branch_id`, `status` (completed, voided, refunded, partial), `payment_method`, `cashier_id`.
        *   Search: `sale_number`, `client_request_uuid`.
    *   Ensure strict `BelongsToTenant` enforcement in the query builder.

### Slice B: History Index UI (Story 15.3)
*   **Goal**: Deliver a filterable transaction list.
*   **Status: COMPLETED (Baseline UI Accepted Early)**
*   **Tasks**:
    *   Create `SalesHistoryController@index`.
    *   Build `SalesHistory/Index.jsx` using the IPOS design system (React).
    *   Implement high-density table with status chips.
    *   Add responsive filter bar.
*   **Note**: Delivered early and formally accepted after scope-drift review on 2026-05-15.

### Slice C: Detail Drill-Down & Reversal Timeline (Story 15.4, 15.6)
*   **Goal**: Detailed view of a single transaction lifecycle.
*   **Status: COMPLETED (Baseline UI Accepted Early)**
*   **Tasks**:
    *   Implement `SalesHistoryController@show`.
    *   Build `SalesHistory/Show.jsx` detail page (React).
    *   **Reversal Timeline**: Show linked voids/refunds with reasons and actor metadata.
    *   **Receipt Preview**: Read-only render of the original receipt data.
*   **Note**: Delivered early and formally accepted after scope-drift review on 2026-05-15.

### Slice D: Export & Audit (Story 15.5)
*   **Goal**: Audit-safe CSV exports.
*   **Status: COMPLETED (Validated)**
*   **Tasks**:
    *   Implement `SalesHistoryExportService` with formula injection protection.
    *   Add `export` action to `SalesHistoryController`.
    *   **Audit**: Log `transaction_history_exported` with filter metadata.
    *   Enforce `export_sales_history` permission.
*   **Note**: Formally validated on 2026-05-15 with focused and regression suites.

---

## 3. Technical Considerations
*   **Performance**: Use indexed columns (`created_at`, `sale_number`, `tenant_id`, `branch_id`) for history lookups.
*   **Isolation**: The `SalesHistoryQueryService` must automatically apply branch scoping if the actor is not a `view_multi_branch_dashboard` holder.
*   **Statutory Compliance**: Ensure that reversed (voided/refunded) sales are clearly distinguished from active sales in totals to match tax reporting logic from Epic 14.

---

## 4. Validation Plan
1.  **Security Tests**: Verify that a user in Tenant A cannot guess a `sale_number` or UUID from Tenant B.
2.  **Scope Tests**: Verify that a Branch Manager can only see history for their assigned branch(es).
3.  **Data Integrity**: Confirm that totals in the History UI match the `SalesTaxReportingQueryService` outputs for the same period.
