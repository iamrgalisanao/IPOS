  # Story 26.4-A: 3-Way AP Document Matching Planning Scope Lock

  **Date**: 2026-05-18  
  **Status**: Planning Only / Scope Locked  
  **Implementation Phase**: Not Started  
  **Target Epic**: Epic 26 — Advanced Supply Chain, Expiry Tracking & Automated Procurement

  ---

  ## 1. Goal

  Define the precise business rules, data assumptions, reconciliation algorithms, variance thresholds, matching state transitions, and QuickBooks Online (QBO) outbox payload structures for **3-Way Accounts Payable (AP) Document Matching**. 

  This planning lock establishes a secure, non-mutating framework to reconcile **Purchase Orders (POs)**, **Goods Receipt Vouchers (GRVs / Purchase Receivings)**, and **Supplier Invoices** before any financial liabilities are posted to the system or queued for accounting synchronization.

  ---

  ## 2. Scope Lock Boundaries

  To prevent scope creep into cash management, actual payment processing, or general ledger refactoring, this story enforces strict boundaries:

  ### 📥 In Scope for Story 26.4-A:
  *   **Supplier Invoice Schema Assumptions**: Designing the database structure and relational fields needed to store supplier-provided billing documents.
  *   **3-Way Reconciliation Algorithm**: Specifying how the matching engine cross-references line-by-line quantities and unit costs between the PO, the GRV, and the Supplier Invoice.
  *   **Variance Thresholds & Hard Bounds**: Setting maximum allowable tolerances (percentage and absolute amount) for unit price and quantity variations.
  *   **Match Status State Machine**: Defining the lifecycle states (`pending`, `matched`, `discrepant`, `posted`) and programmatic rules for moving between them.
  *   **QBO `Bill` Payload Structure**: Outlining the precise translation mapping from a posted, matched Supplier Invoice to a QBO `Bill` entity utilizing `ItemBasedExpenseLineDetail`.
  *   **Idempotency & Deduplication Logic**: Defining database and API-level constraints to prevent duplicate bills from queueing or syncing.
  *   **Adversarial Test Matrix**: Drafting comprehensive validation scenarios and edge cases to drive future implementation.

  ### 🚫 Explicitly Out of Scope:
  *   **Invoice Posting & DB Insertion Code**: No active migration, controller routes, or posting execution services will be implemented in this planning stage.
  *   **AP Disbursements & Payment Logic**: The system will **never** authorize bank drafts, print checks, or manage cash payments to suppliers.
  *   **Direct QBO API Communications**: No immediate network transmission of billing data to external QBO services.
  *   **Purchase Order creation or Inventory Mutation Changes**: Retaining active replenishment and receiving tables without altering stock mutations.
  *   **Broad Accounting Refactor**: Reusing existing `AccountingOutboxService` models and mapper structures without modifying core database architectures.

  ---

  ## 3. Supplier Invoice Schema Assumptions

  To support high-fidelity 3-way matching, the future database schema will record the Supplier Invoice as a distinct entity linked back to the original Purchase Order and Purchase Receivings (GRVs):

  ```
                ┌────────────────────────┐
                │  PurchaseOrder (PO)    │
                └──────────┬─────────────┘
                          │
          ┌────────────────┴────────────────┐
          │                                 │
  ┌───────▼─────────────────┐     ┌─────────▼───────────────┐
  │ PurchaseReceiving (GRV) │     │     SupplierInvoice     │
  └─────────────────────────┘     └─────────────────────────┘
  ```

  ### Table Structure Design (Assumed):
  *   `supplier_invoices`
      *   `id` (UUID, Primary Key)
      *   `tenant_id` (UUID, FK, Indexed)
      *   `branch_id` (UUID, FK, Indexed)
      *   `supplier_id` (UUID, FK, Indexed)
      *   `purchase_order_id` (UUID, FK, Nullable - for direct PO matching)
      *   `invoice_number` (String, Supplier's document reference, unique per supplier)
      *   `invoice_date` (Date)
      *   `subtotal` (Decimal 15,4)
      *   `tax_total` (Decimal 15,4)
      *   `total_amount` (Decimal 15,4)
      *   `match_status` (Enum: `pending`, `matched`, `discrepant`, `posted`)
      *   `posted_at` (Timestamp, Nullable)
      *   `posted_by` (UUID, FK, Nullable)
  *   `supplier_invoice_lines`
      *   `id` (UUID, Primary Key)
      *   `supplier_invoice_id` (UUID, FK)
      *   `purchase_receiving_line_id` (UUID, FK, Nullable - links to specific GRV line)
      *   `product_id` (UUID, FK)
      *   `quantity_billed` (Decimal 12,4)
      *   `unit_cost_billed` (Decimal 15,4)
      *   `line_total` (Decimal 15,4)

  ---

  ## 4. 3-Way Matching Rules & Reconciliation Engine

  The reconciliation engine systematically compares three values for each line item:

  1.  **Ordered Quantity & Cost** (from `purchase_order_lines`)
  2.  **Received Quantity** (from `purchase_receiving_lines`)
  3.  **Billed Quantity & Cost** (from `supplier_invoice_lines`)

  ### A. The Matching Rules
  A document achieves a perfect `matched` status when it satisfies the following hard conditions:

  $$\text{Quantity Rule: } \text{Qty Billed} \le \text{Qty Received} \le \text{Qty Ordered}$$
  $$\text{Cost Rule: } \text{Unit Cost Billed} = \text{Unit Cost Ordered}$$

  *   **Under-billing** ($\text{Qty Billed} < \text{Qty Received}$): Allowed. The matching status becomes `matched` (representing partial billing, where the supplier may bill the remainder in a subsequent invoice).
  *   **Over-billing** ($\text{Qty Billed} > \text{Qty Received}$): Rejected. Status transitions to `discrepant`.
  *   **Price Mismatch** ($\text{Unit Cost Billed} \neq \text{Unit Cost Ordered}$): If difference exceeds variance thresholds, status transitions to `discrepant`.

  ---

  ## 5. Variance Thresholds & Match Statuses

  ### A. Variance Threshold Configurations
  To prevent minor mathematical rounding discrepancies from blocking operational workflows, the system will support tenant-level variance configuration thresholds:

  | Threshold Name | Type | Value | Enforcement Rule |
  | :--- | :--- | :--- | :--- |
  | **Unit Price Tolerance** | Percentage | $\le 1.0\%$ | If $(\text{Unit Cost Billed} - \text{Unit Cost Ordered}) / \text{Unit Cost Ordered} \le 0.01$, auto-resolve as matching. |
  | **Absolute Invoice Tolerance** | Fixed Amount | $\le 5.00$ PHP | Maximum cumulative invoice variance allowed before triggering human supervisor override. |

  ### B. Match Status State Machine

  ```mermaid
  stateDiagram-v2
      [*] --> Pending : Invoice Created
      Pending --> Matched : Matching Checks Pass (Qty & Price within tolerance)
      Pending --> Discrepant : Matching Checks Fail (Out of tolerance / Over-billed)
      
      Discrepant --> Pending : Adjust Invoice Quantities / Costs
      Discrepant --> Matched : Supervisor Override (Manual Sign-Off)
      
      Matched --> Posted : User Commits Post Transaction
      Posted --> [*] : Accounting Outbox Event Dispatched
  ```

  *   **`pending`**: Invoice details captured; matching logic has not yet been triggered.
  *   **`matched`**: Quantities and prices align with both PO and GRV within configured limits.
  *   **`discrepant`**: Variances detected (e.g., price increase from original PO, or billing for units not yet received at branch). Auto-blocks posting.
  *   **`posted`**: Locked transaction; locks lines from future edits, triggers accounting outbox row creation.

  ---

  ## 6. QuickBooks Online `Bill` Outbox Payload

  In QuickBooks Online, accounts payable liabilities are tracked using the **`Bill`** entity. Once a Supplier Invoice is programmatically marked `posted`, a `supplier_invoice_posted` outbox event is generated.

  ### Field-Level Translation to QBO Bill:
  | QBO Bill Field | Source Field / Resolver | Mapping Logic |
  | :--- | :--- | :--- |
  | **DocNumber** | `payload.invoice_number` | Direct map (Supplier's Invoice ID). |
  | **VendorRef** | `payload.supplier_id` | Resolved via `AccountingMapperInterface->mapSupplier($supplierId)`. |
  | **APAccountRef** | System Tenant Mapping | Maps to active Accounts Payable liabilities. |
  | **TxnDate** | `payload.invoice_date` | Direct ISO date string mapping. |
  | **TotalAmt** | `payload.total_amount` | Rounded to 2 decimal places using `round($total, 2)`. |
  | **Line** | Array of lines | Maps as **`ItemBasedExpenseLineDetail`** lines. |

  ### Product Line Translation (ItemBasedExpenseLineDetail):
  ```json
  {
    "DetailType": "ItemBasedExpenseLineDetail",
    "Amount": 1500.00,
    "ItemBasedExpenseLineDetail": {
      "ItemRef": {
        "value": "QBO_PRODUCT_ITEM_ID_MAPPED"
      },
      "Qty": 10.0000,
      "UnitPrice": 150.00
    }
  }
  ```
  *   `ItemRef` must be resolved using `AccountingMapperInterface->mapProduct($productId)`. If missing, the outbox sync attempt transitions to `failed` (`validation_failure`).

  ---

  ## 7. Idempotency & Retry Rules

  To protect the accounting ledger against duplicate bill entries during sync retries or daemon timeouts:

  1.  **Database Level**:
      *   Enforce a unique database key on `supplier_invoices`:
          ```sql
          UNIQUE(tenant_id, supplier_id, invoice_number)
          ```
          This hard boundary prevents identical invoice numbers from the same supplier from registering.
  2.  **Outbox Level**:
      *   Composite index on the `accounting_outbox` table:
          ```sql
          UNIQUE(tenant_id, event_type, source_type, source_id)
          ```
  3.  **QuickBooks Level**:
      *   Sync builder generates a predictable `idempotency_key`:
          ```
          ipos:{tenant_id}:qbo_bill:{supplier_invoice_uuid}
          ```

  ---

  ## 8. Adversarial Test Matrix

  This matrix guides the validation coverage for the subsequent implementation phase:

  | Test ID | Level | Target Domain | Scenario Description | Expected Outcome |
  | :--- | :--- | :--- | :--- | :--- |
  | **TC-26.4A-01** | Unit | Match Logic | Quantity Billed == Quantity Received == Quantity Ordered | Status = `matched`. |
  | **TC-26.4A-02** | Unit | Match Logic | Quantity Billed > Quantity Received (Over-Billing) | Match rejected; Status = `discrepant`. |
  | **TC-26.4A-03** | Unit | Match Logic | Quantity Billed < Quantity Received (Under-Billing / Partial) | Match accepted; Status = `matched`. |
  | **TC-26.4A-04** | Unit | Price Match | Unit Cost Billed matches PO Cost perfectly | Status = `matched`. |
  | **TC-26.4A-05** | Unit | Price Match | Unit Cost Billed is higher than PO Cost but within 1.0% variance | Status = `matched` (warning flag logged). |
  | **TC-26.4A-06** | Unit | Price Match | Unit Cost Billed is higher than PO Cost exceeding 1.0% variance | Status = `discrepant`. |
  | **TC-26.4A-07** | Integration | Posting Handoff | Matched Invoice is posted by manager | Status transitions to `posted`, immutable `accounting_outbox` record with `supplier_invoice_posted` payload created inside transaction. |
  | **TC-26.4A-08** | Integration | QBO Payload | Compile outbox row to QBO Bill format | Valid QBO Bill schema compiled with `ItemBasedExpenseLineDetail` lines and mapped `VendorRef` / `ItemRef`. |
  | **TC-26.4A-09** | Integration | Idempotency | Try posting twice or inserting duplicate supplier/invoice combination | SQL integrity error caught, prevented gracefully. |
  | **TC-26.4A-10** | Integration | Rollback Safety | Failure during outbox write or ledger locking during post | Full rollback: Invoice returns to `matched`, outbox record is not created. |
