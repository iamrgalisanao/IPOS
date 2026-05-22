# Story 28.9: Epic 28 Phase 2 Slice E — Offline Import Posting Readiness & Admin Conflict Review

**Date**: 2026-05-20  
Status: ACCEPTED WITH GOVERNANCE NOTES
Story: 28.9 — Epic 28 Phase 2 Slice E
Scope: Offline Import Posting Readiness & Admin Conflict Review
Result: Implemented & Locally Validated

---

## 1. Goal

Define the precise business rules, constraints, and test matrix for **Epic 28 Phase 2 Slice E — Offline Import Posting Readiness & Admin Conflict Review**.

Before offline imports can be posted as official `Sale` records, we must introduce an administrative review surface. This allows authorized tenant users (e.g., Owners, Auditors) to inspect imports marked as `conflict`, `rejected`, or `duplicate`, review the server recalculation snapshot against the client-submitted totals, and eventually flag them for override or manual remediation.

This slice deliberately **stops before creating any official Sale record**. It focuses exclusively on visibility, auditability, and conflict management state.

**Official Sale posting, GCT update, Z-read, and e-journal finalization remain out of scope until Story 28.10+.**

---

## 2. Story Scope Boundaries

### In Scope:

1. **Admin Controller & Routes**: Add administrative endpoints to list, filter, and view details of `OfflineSalesImport` records.
   - `GET /api/admin/offline-sync/imports` (List with filters)
   - `GET /api/admin/offline-sync/imports/{id}` (Detailed view)
   - `PATCH /api/admin/offline-sync/imports/{id}/review` (Status transition)

2. **Filtering Capabilities (Listing)**: 
   - Filter by status (`pending`, `server_verified`, `conflict`, `rejected`, `duplicate`, `validated`, `posted`, `hold`, `override_approved`).
   - Filter by `batch_id` / `batch_reference`.
   - Filter by `sales_machine_profile_id`.
   - Filter by date range (`submitted_at`).
   - Must enforce tenant isolation, branch isolation, and RBAC permissions.

3. **Detail View**: 
   - Return import ID, status, offline sequence number, raw payload, server recalculation, conflict notes, rejection reason, reviewed by, reviewed at, review notes, batch metadata, and terminal metadata.

4. **New Statuses & Transitions (Review Action)**:
   - Introduce `hold` and `override_approved` statuses.
   - Allowed transitions:
     - `conflict` → `hold`
     - `conflict` → `override_approved`
     - `hold` → `override_approved`
     - `hold` → `conflict` (if admin reopens review)
   - Blocked transitions:
     - `server_verified` → `override_approved`
     - `rejected` → `override_approved`
     - `duplicate` → `override_approved`
     - `posted` → any review status

5. **Audit Tracking**:
   - Add tracking fields to `offline_sales_imports`: `reviewed_by_user_id` (nullable), `reviewed_at` (nullable), and `review_notes` (nullable).

6. **RBAC Rules**:
   - Add new permission: `review_offline_sync_conflicts`. Only users with this permission can access the endpoints.

7. **Test Matrix**:
   - Feature tests covering Admin API access, filtering correctness, status transition rules, and audit constraints.

### Out of Scope:

- Creating official `Sale` records.
- Deducting inventory or calling `FefoAllocationService`.
- Creating payment records.
- Updating `grand_cumulative_total` (GCT) on `SalesMachineProfile`.
- Updating `offline_terminal_journals`.
- Official Z-read, official e-journal finalization.
- Frontend UI implementation.
- Accepting client totals as official truth.

---

## 3. Revised Status Definitions

| Status | Meaning |
| :--- | :--- |
| `hold` | Admin marked the import for further investigation. It is not eligible for future posting. |
| `override_approved` | Admin approved the import to proceed to a future posting eligibility check despite recalculation conflict. This does not mean client totals are trusted, does not create a Sale, and does not bypass server-side posting rules. |

---

## 4. Test Matrix

1. Admin can list imports filtered by conflict status.
2. Admin can view import details with raw payload and server recalculation.
3. Admin can transition conflict to hold.
4. Admin can transition conflict to override_approved.
5. Admin can transition hold back to conflict.
6. server_verified cannot transition to override_approved.
7. rejected cannot transition to override_approved.
8. duplicate cannot transition to override_approved.
9. cashier receives 403.
10. cross-tenant import access is blocked.
11. review fields are populated after transition.
12. reconciled_sale_id and reconciled_at remain null.
13. no Sale row is created.

---

## 5. Validation & Results

- **Feature Tests**: `OfflineImportReviewTest.php` created with 13/13 scenarios passing.
- **Admin Feature Suite**: 33/33 tests passed.
- **POS Feature Suite**: 274/274 tests passed.

## 6. Governance Notes

Story 28.9 creates only the administrative review surface for offline imports. It does not post imports as official sales and does not mutate inventory, payment, GCT, Z-read, or e-journal records.

