# Implementation Plan: Epic 20 — Supplier & Purchase Receiving

**Status: CLOSED & VALIDATED**

## 1. Epic Overview
The objective of Epic 20 is to establish a robust, supply chain and procurement foundation for IPOS. While previous epics handled sales-driven stock deductions (Epic 6), stocktakes (Epic 16), and cashier accountability (Epic 17), Epic 20 introduces the **Primary Stock Ingestion (Inbound Flow)**.

This epic establishes:
1.  **Supplier Profiles**: Centralized vendor directory with branch-scoped assignments.
2.  **Purchase Orders (PO)**: Formally requested inventory requirements with multi-step review cycles.
3.  **Purchase Receiving (Vouchers)**: Verification of incoming deliveries (linked to POs or standalone), capturing variances, batch/lot numbers, and perishability parameters (expiry dates).
4.  **Inventory Valuation & COGS foundation**: Recalculating weighted average cost (WAC) upon stock ingestion to feed precise cost-of-goods-sold analysis.

---

## 2. Scope Lock & Governance Decisions

### In-Scope
*   **Supplier Directory**: Multi-tenant CRUD with active/inactive status, terms, and primary contacts.
*   **Purchase Order (PO) Lifecycle**: Standardized workflow: `draft` -> `pending_approval` -> `approved` -> `sent` -> `completed` / `cancelled`.
*   **Goods Receiving Voucher (GRV)**: Record physical intake of goods with ordered vs. received count variance tracking.
*   **Standalone Receiving**: Capability to receive inventory directly without an existing PO (direct store delivery).
*   **Batch & Expiry Support**: Storage of lot numbers and expiry dates for perishable/food items (Enforcement: *Deferred*).
*   **Atomic Inventory Increments**: Real-time positive stock adjustments linked to `supplier_receiving` movement types.
*   **Valuation Engine**: Automatic recalculation of product unit cost using **Weighted Moving Average Cost** method on receiving.
*   **Multi-Tenant / Branch Isolation**: Branch managers are restricted to managing their own branch POs and receipts; owners maintain tenant-wide visibility.

### Out-of-Scope (Explicitly Deferred / Blocked)
*   **Perishable Enforcement**: *DEFERRED — Requires Product/Category Metadata Enhancement*. The product schema currently lacks `is_perishable` and `requires_expiry_tracking` flags. Do not add product schema changes in Epic 20 yet. Capturing lot and expiry fields in receiving lines is in scope, but validation constraints (mandatory checking) are deferred.
*   **Accounts Payable / Invoicing**: Direct supplier payment processing, bank reconciliations, or supplier credit/debit ledger tracking.
*   **Supplier Returns / RMA**: Reverse logistics and outbound supplier claims (deferred).
*   **Automatic Stock Reordering**: Predictive purchase recommendations or threshold-based auto-POs (deferred).
*   **Multi-Branch Split POs**: A single PO is legally tied to a single branch delivery address.

---

## 3. Story Breakdown & Status

### Story 20.1 — Supplier & Purchase Foundation Scope Lock
*   **Goal**: Validate architecture assumptions, costing model, schema design, and RBAC posture before writing functional backend code.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: Scope lock completed and validated.

### Story 20.2 — Supplier Directory Foundation
*   **Goal**: Establish supplier CRUD APIs and contracts.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: Supplier directory CRUD backend APIs and Inertia UI workspace completed and validated.

### Story 20.3 — Purchase Order Backend & Lifecycle
*   **Goal**: Deliver the stateful PO persistence layer and review triggers.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: PO lifecycle states (`draft`, `pending_approval`, `approved`, `sent`, `completed`, `cancelled`) completed and validated.

### Story 20.4 — Purchase Receiving Draft Workspace
*   **Goal**: Create goods receiving drafts (both standalone and PO-linked).
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: Goods receiving draft workbook UI and PO matching/variance indicator indicators completed and validated.

### Story 20.5 — Atomic Receiving Posting & WAC Valuation
*   **Goal**: Execute atomic stock ingestion and recalculate inventory valuations.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: Atomic receiving posting and branch-level WAC valuation with pessimistic locking completed and validated.

### Story 20.6 — Procurement UI & CSV Security Hardening
*   **Goal**: Expose procurement registers, printable receipts, and CSV reports with secure protection.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: Procurement UI exports, CSV security protection against Excel injection, and audit logging completed and validated.

### Story 20.7 — RBAC, Audit, and Closure Hardening
*   **Goal**: Apply strict access isolation barriers, compile proof matrices, and complete Epic 20 closure.
*   **Status**: COMPLETED & VALIDATED.
*   **Evidence**: RBAC enforcement, cashier lockout, branch/tenant isolation, state immutability constraints, and comprehensive test suite completed and validated.

---

## 4. Final Validation Evidence

### Test Execution Metrics
All tests in the procurement suite run cleanly and pass without errors:
*   `SupplierDirectoryTest`: included in procurement suite
*   `PurchaseOrderLifecycleTest`: included in procurement suite
*   `PurchaseReceivingDraftTest`: included in procurement suite
*   `PurchaseReceivingPostingTest`: included in procurement suite
*   `ProcurementCsvExportTest`: included in procurement suite
*   `ProcurementHardeningTest`: **9 tests / 66 assertions**
*   **Full Procurement Feature Suite**: **48 tests / 263 assertions**
*   **Asset Compiler Check**: `npm run build` compiled successfully.

---

## 5. Architectural Costing & Posting Validation

### Costing Model Decision
1.  **Cost Location**: In IPOS, a global base cost lives on `products.cost_price` but this does not handle multi-branch variation.
2.  **Branch-level WAC**: Dynamic costing updates will be stored as `average_cost` (decimal 19,4) on the `branch_inventories` table. 
3.  **Fallback Logic**: During inventory calculations or sales reporting, the query service will fetch `branch_inventories.average_cost`. If missing or set to `0.0000`, it falls back to the default `products.cost_price`. This prevents costing data corruption across branches while maintaining backward compatibility.

### WAC Calculation Rule Lock
All calculations must utilize `bcmath` operations to 4 decimal places. The WAC formula is formally locked as follows:
$$\text{New WAC} = \frac{(\text{Current Qty} \times \text{Current WAC}) + (\text{Received Qty} \times \text{Received Cost})}{\text{Current Qty} + \text{Received Qty}}$$

*   **Rule 1 (Negative Validation)**: `Received Qty` must be strictly positive ($> 0$). Any negative or zero quantity fails validation.
*   **Rule 2 (Zero/Negative Stock Fallback)**: If `Current Qty` is less than or equal to $0$ (e.g. out of stock or negative due to timing), the calculation ignores current negative stock and sets:
    $$\text{New WAC} = \text{Received Cost}$$
*   **Rule 3 (Zero Sum Prevention)**: If `Current Qty + Received Qty` would sum to zero (e.g. current stock is $-5$ and received quantity is $5$), new WAC is set directly to the `Received Cost` to prevent division by zero.

### Inventory Ingest Flow
When a Goods Receiving Voucher is committed (`status = 'posted'`):
1.  **Row Locking**: `PurchaseReceiving` row is locked via `lockForUpdate()`.
2.  **Double-Post Protection**: Check that the status is strictly `draft`. If already `posted`, abort the transaction.
3.  **Stock Increment**: Execute atomic DB statement:
    ```sql
    UPDATE branch_inventories 
    SET current_stock = current_stock + :received_qty,
        average_cost = :new_wac
    WHERE branch_id = :branch_id AND product_id = :product_id
    ```
4.  **Movement Write**: Insert a positive record in `inventory_movements` with:
    *   `movement_type`: `'supplier_receiving'`
    *   `quantity_change`: `received_quantity`
    *   `source_type`: `'App\\Models\\PurchaseReceiving'`
    *   `source_id`: `purchase_receiving_id`
    *   `reference_number`: `receiving_number` (GRV number)
    *   `user_id`: `auth()->id()`

---

## 6. Schema Design Specifications

### `suppliers`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `tenant_id` | UUID | FK to `tenants` |
| `code` | String | Unique supplier shortcode (e.g. VEND-NESTE) |
| `name` | String | Company/Supplier Name |
| `contact_name` | String | Contact Person |
| `email` | String | Primary Contact Email |
| `phone` | String | Contact Phone |
| `address` | Text | Primary Warehouse/Billing Address |
| `payment_terms` | String | e.g. COD, NET_15, NET_30 |
| `is_active` | Boolean | Directory visibility switch |
| `created_at` | Timestamp | |
| `updated_at` | Timestamp | |

### `purchase_orders`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `tenant_id` | UUID | FK to `tenants` |
| `branch_id` | UUID | FK to `branches` |
| `supplier_id` | UUID | FK to `suppliers` |
| `po_number` | String | Unique human reference (`PO-BRANCH-YYYYMMDD-SEQ`) |
| `status` | String | `draft`, `pending_approval`, `approved`, `sent`, `completed`, `cancelled` |
| `order_date` | Date | Expected date of placement |
| `expected_delivery_date`| Date | Target arrival date |
| `total_estimated_amount`| Decimal | Total value at PO cost |
| `notes` | Text | Internal delivery notes |
| `created_by` | UUID | FK to `users` |
| `approved_by` | UUID | FK to `users` (nullable) |
| `approved_at` | Timestamp | (nullable) |
| `created_at` | Timestamp | |
| `updated_at` | Timestamp | |

### `purchase_order_lines`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `purchase_order_id` | UUID | FK to `purchase_orders` |
| `product_id` | UUID | FK to `products` |
| `ordered_quantity` | Decimal | Quantity ordered |
| `received_quantity` | Decimal | Cumulative quantity received (updated via GRVs) |
| `unit_cost` | Decimal | Expected unit cost |
| `line_total` | Decimal | `ordered_quantity * unit_cost` |
| `created_at` | Timestamp | |

### `purchase_receivings`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `tenant_id` | UUID | FK to `tenants` |
| `branch_id` | UUID | FK to `branches` |
| `supplier_id` | UUID | FK to `suppliers` (nullable) |
| `purchase_order_id` | UUID | FK to `purchase_orders` (nullable) |
| `receiving_number` | String | Unique voucher ref (`GRV-BRANCH-YYYYMMDD-SEQ`) |
| `status` | String | `draft`, `posted`, `cancelled` |
| `delivery_ref_number` | String | Supplier invoice or delivery receipt number |
| `received_at` | Timestamp | Physical date of ingestion |
| `total_received_amount` | Decimal | Aggregated actual cost |
| `notes` | Text | Intake anomalies notes |
| `received_by` | UUID | FK to `users` |
| `posted_at` | Timestamp | Date inventory and costs were frozen |
| `created_at` | Timestamp | |

### `purchase_receiving_lines`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Primary Key |
| `purchase_receiving_id`| UUID | FK to `purchase_receivings` |
| `product_id` | UUID | FK to `products` |
| `received_quantity` | Decimal | Actual physical count received |
| `unit_cost` | Decimal | Actual unit cost billed by supplier |
| `line_total` | Decimal | `received_quantity * unit_cost` |
| `lot_number` | String | Expiry verification batch number (nullable) |
| `expiry_date` | Date | Safety compliance date (nullable) |
| `created_at` | Timestamp | |

*Note: In alignment with standard IPOS normalized tables (such as `stocktake_lines`), `purchase_receiving_lines` does not denormalize `tenant_id` and `branch_id` columns since they are fully resolvable through the parent relationship, minimizing database redundancy.*

---

## 7. RBAC Governance Model

Required application permissions:
*   `procurement.suppliers.view`: Access vendor listing.
*   `procurement.suppliers.manage`: Create, edit, toggle active status.
*   `procurement.purchase-orders.view`: View PO history and states.
*   `procurement.purchase-orders.create`: Draft and request approvals.
*   `procurement.purchase-orders.approve`: Approve in-flight POs and send to vendor.
*   `procurement.receiving.view`: View goods receiving vouchers.
*   `procurement.receiving.create`: Open intakes (standalone or linked).
*   `procurement.receiving.post`: Commit stock, lock WAC calculations, update inventories.

| Role | Supplier CRUD | PO Draft | PO Approve | Receiving Draft | Receiving Post | Scope |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **Cashier** | Blocked | Blocked | Blocked | Blocked | Blocked | Blocked |
| **Store Clerk / Receiving** | View Only | YES | Blocked | YES | Blocked | Assigned Branch |
| **Branch Manager** | View Only | YES | YES | YES | YES | Assigned Branch |
| **Owner / Admin** | YES | YES | YES | YES | YES | Tenant-Wide |
| **Auditor** | View Only | View Only | Blocked | View Only | Blocked | Tenant-Wide |

---

## 8. Operational Risk Management

We track specific risks related to dynamic pricing ingestion:

*   **R-029: Inventory Valuation Cost Drift** (High) - Recalculation rounding errors in multi-step WAC. *Mitigation*: Enforce `bcmath` with a precision scale of 4 for WAC computation, validating outputs against precise manual arithmetic vectors in test. (Status: **Mitigated**)
*   **R-030: Double-Receiving Race Conditions** (High) - Concurrent posted vouchers for the same product leading to overlapping valuation writes. *Mitigation*: Apply `pessimisticLock()` inside atomic database transactions before updating `current_stock` levels. (Status: **Mitigated**)
*   **R-031: Procurement Cross-Branch Leakage** (High) - Store clerks reading POs or receiving sheets for other branches. *Mitigation*: Enforce Laravel global scopes filtering by the session's active branch. (Status: **Mitigated**)
*   **R-032: Missing Expiry Safety Parameters** (Medium) - Physical ingestion of perishable items without tracking. *Mitigation*: Perishable tracking is *Deferred* pending product category schema updates. Capturing dates is optionally supported. (Status: **Open / Deferred**)
*   **R-033: Unauthorized Inbound Adjustments** (Medium) - Fraudulent receiving updates bypassing PO review. *Mitigation*: Gated strictly behind the server-side `procurement.receiving.post` permission check. (Status: **Mitigated**)
*   **R-034: Procurement CSV Injection** (Low) - Formula cell injection on export files. *Mitigation*: Enforce cell-level sanitization for all Excel executable prefixes (`=`, `+`, `-`, `@`). (Status: **Mitigated**)
