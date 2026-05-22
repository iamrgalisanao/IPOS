# Implementation Plan: Epic 16 — Inventory Stocktake & Stock Adjustment UI

**Status: CLOSED & VALIDATED**

## 1. Epic Overview
The objective of Epic 16 is to establish a robust, branch-scoped inventory management interface for IPOS. This module will allow authorized personnel to perform formal stock counting (stocktake) and record controlled stock adjustments.

This epic follows the successful completion of:
*   **Epic 15 (Sales History)**: Providing the historical context for transactional stock movements.
*   **Epic 22 (Visual POS Layout Builder)**: Standardizing administrative UI patterns and deployment governance.
*   **Shift Operations Hardening**: Establishing persistent operational HUDs and cash reconciliation patterns.

Epic 16 introduces the "Physical Truth" layer to the inventory system, reconciling the "Book Truth" (automated deductions) with actual physical counts.

---

## 2. Scope Lock
The scope of Epic 16 is strictly bounded to physical inventory reconciliation and manual adjustments within a single branch.

### Stocktake Lifecycle
1.  **Draft**: Session initialized, but no counts recorded yet. Products for the count are identified.
2.  **Counting**: Active state where quantities are being recorded against products.
3.  **Review**: Counting is finished; variance is calculated. Awaiting supervisor/manager review and reason enforcement.
4.  **Posted**: Final terminal state. Inventory movements are generated, and `branch_inventories` are updated.
5.  **Cancelled**: Session aborted before posting; no inventory impact.
6.  **Rejected**: Reviewer identified issues and declined the count; no inventory impact.

### Out of Scope
*   Multi-branch stock transfers (Planned for future epics).
*   Supplier purchase orders (Planned for Epic 20).
*   Automatic inventory reordering (Deferred).

---

## 3. Proposed Stories / Slices

### Story 16.1 — Stocktake Session Foundation [VALIDATED]
*   **Goal**: Create the persistence and state machine foundation.
*   **Tasks**:
    *   [x] Schema design and migration for `stocktake_sessions` and `stocktake_lines`.
    *   [x] State machine implementation for the lifecycle (`Draft` -> `Counting` -> `Review` -> `Posted`).
    *   [x] Tenant and branch isolation enforcement at the model/global scope level.
    *   [x] RBAC permission definitions.

### Story 16.2 — Stocktake Counting UI [VALIDATED]
*   **Goal**: Deliver the primary counting workspace.
*   **Tasks**:
    *   [x] List and Detail pages for stocktake sessions (Inertia/React).
    *   [x] Implementation of "Initialize Count" workflow (snapshotting expected quantity).
    *   [x] High-fidelity counting grid with physical quantity entry and progress saving.
    *   [x] Blind-count logic (expected quantity visibility control via server-side payload shaping).

### Story 16.3 — Review and Variance Handling [VALIDATED]
*   **Goal**: Reconcile counts and enforce accountability.
*   **Tasks**:
    *   [x] Automatic variance calculation (server-side).
    *   [x] Reason code enforcement for non-zero variance.
    *   [x] High-fidelity Review UI (Expected vs Physical vs Variance).
    *   [x] Session submission and rejection workflows.

### Story 16.4 — Posting and Inventory Adjustment [VALIDATED]
*   **Goal**: Atomically update the system of record.
*   **Tasks**:
    *   [x] `StocktakePostingService` for atomic adjustments.
    *   [x] Inventory movement generation (1 movement per variance line).
    *   [x] Branch inventory quantity updates.
    *   [x] Terminal locking (Status = `posted`).
    *   [x] Double-posting protection via row locking and status checks.
    *   [x] Audit log generation for the final posting event.

### Story 16.5 — Approval and RBAC Hardening [VALIDATED]
*   **Goal**: Enforce organizational control.
*   **Tasks**:
    *   [x] RBAC hardening in `RbacSeeder`.
    *   [x] Lifecycle state machine hardening (Terminal = Immutable).
    *   [x] Explicit permission checks in all controller actions.
    *   [x] UI visibility hardening (Buttons gated by permissions).
    *   [x] Comprehensive audit logging for all state transitions (Started, Submitted, Rejected, Cancelled).
    *   [x] Adversarial tests for branch-crossing and unauthorized access.
    *   [x] Self-approval prevention policy (Documented: Deferred for tenant-configurable policy).

### Story 16.6 — Export & Operational Reporting [VALIDATED]
*   **Goal**: Document the stocktake for physical audits.
*   **Tasks**:
    *   [x] Print-ready Stocktake Summary (React View).
    *   [x] CSV export of variance reports for accounting reconciliation.
    *   [x] Historical stocktake register (Index View).
    *   [x] Blind-count payload protection in reporting.

---

## 4. Schema Design Proposal

### `stocktake_sessions`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `tenant_id` | UUID | FK to `tenants` |
| `branch_id` | UUID | FK to `branches` |
| `stocktake_number` | String | Human-readable ref (e.g. ST-202405-001) |
| `status` | String | draft, counting, review, posted, cancelled, rejected |
| `started_by` | UUID | FK to `users` |
| `reviewed_by` | UUID | FK to `users` (nullable) |
| `approved_by` | UUID | FK to `users` (nullable) |
| `posted_by` | UUID | FK to `users` (nullable) |
| `started_at` | Timestamp | |
| `submitted_at` | Timestamp | |
| `reviewed_at` | Timestamp | |
| `approved_at` | Timestamp | |
| `posted_at` | Timestamp | |
| `cancelled_at` | Timestamp | |
| `notes` | Text | Admin/Reviewer notes |
| `created_at` | Timestamp | |
| `updated_at` | Timestamp | |

### `stocktake_lines`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `stocktake_session_id` | UUID | FK to `stocktake_sessions` |
| `product_id` | UUID | FK to `products` |
| `expected_quantity` | Decimal | Book stock at time of count start |
| `counted_quantity` | Decimal | Actual physical count |
| `variance_quantity` | Decimal | Counted - Expected |
| `reason_code` | String | e.g. DAMAGED, THEFT, EXPIRED, MISCOUNT |
| `remarks` | Text | Line-level notes |
| `counted_by` | UUID | FK to `users` |
| `counted_at` | Timestamp | |

---

## 5. RBAC Planning
New permissions to be introduced:
*   `inventory.stocktake.view`: Access the stocktake history.
*   `inventory.stocktake.create`: Initialize a new count.
*   `inventory.stocktake.count`: Record quantities in an active session.
*   `inventory.stocktake.review`: Perform supervisor review and variance analysis.
*   `inventory.stocktake.approve`: Final authorization for inventory posting.
*   `inventory.stocktake.post`: Trigger the inventory movement generation.
*   `inventory.stocktake.cancel`: Abort a non-posted session.
*   `inventory.adjustment.view`: View manual stock adjustment history.
*   `inventory.adjustment.create`: Record a one-off stock adjustment (outside full stocktake).

---

## 6. Guardrails
*   **Tenant/Branch Isolation**: Mandatory global scoping; stocktake sessions must never bridge branches.
*   **Immutability**: Once `Posted`, a session and its lines become read-only. Corrections require a new session or adjustment.
*   **Atomicity**: Posting must occur within a single database transaction. If one line fails to post, the entire session remains unposted.
*   **No Double-Posting**: The status must be checked and locked before movement generation.
*   **Reason Enforcement**: No line with variance can be submitted for review without a `reason_code`.
*   **In-Flight Sales (MVP)**: Sales and inventory movements are NOT blocked during the counting phase.
*   **Snapshot-Based Counting**: `expected_quantity` is captured at the moment a session transitions from `Draft` to `Counting`. Variance is calculated against this baseline.
*   **Blind Counting**:
    *   `expected_quantity` is HIDDEN for the Counter role.
    *   `expected_quantity` is VISIBLE for Reviewer and Approver roles.

---

## 7. Testing Plan

### Functional Testing
*   Verify draft creation and product inclusion rules.
*   Validate real-time variance calculation.
*   Ensure mandatory `reason_code` enforcement on variance.
*   Test the transition from `Review` to `Posted`.

### Security & Isolation
*   Attempt to access a stocktake session from a different branch (Should 403/404).
*   Attempt to approve a session without `inventory.stocktake.approve` permission.
*   Attempt to edit a `Posted` session via API (Should fail).

### Data Integrity
*   Verify `branch_inventories.current_stock` matches exactly after posting.
*   Verify `InventoryMovements` are correctly linked to the `StocktakeSession`.
*   Check for race conditions if two users post the same session simultaneously.

---

## 8. Risk Register
| Risk | Level | Impact | Mitigation |
| :--- | :--- | :--- | :--- |
| Inventory Corruption | High | Financial loss / incorrect records | Transaction-safe posting and immutable logs. |
| Double-Posting | High | Multiplied stock impact | Status check locking and unique transaction IDs. |
| Branch Scoping Breach | High | Multi-tenant/branch data leak | Strict `BelongsToTenant` and `BelongsToBranch` traits. |
| Approval Bypass | Medium | Lack of accountability | Server-side permission checks on the `post` and `approve` actions. |
| Negative Inventory | Low | Operational confusion | Configurable guard to prevent adjustments from dropping stock below zero. |
