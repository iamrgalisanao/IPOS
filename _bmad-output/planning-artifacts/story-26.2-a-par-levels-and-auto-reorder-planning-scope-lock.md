# Story 26.2-A: PAR Levels & Lead-Time Auto-Reorder Planning Scope Lock

**Date**: 2026-05-18  
**Status**: Planning Only / Scope Locked  
**Implementation Phase**: Not Started  

---

## 1. Goal
Define the exact business rules, architectural guidelines, multi-tenant safety guarantees, and automated procurement logic before any code is written for **Story 26.2: PAR Levels & Lead-Time Auto-Reorder Schedulers**. 

This planning lock ensures that subsequent stories (threshold schema, recommendation services, draft PO generation, and scheduled console commands) execute predictably without creating incorrect draft Purchase Orders, causing stock leakage, or violating multi-tenant boundaries.

---

## 2. Scope Lock Boundaries

### In Scope for Story 26.2 Series:
* **Threshold Schema**: Extending `branch_inventories` or defining explicit PAR parameters (reorder point, target/optimal stock, supplier lead time, and safety buffer).
* **Replenishment Service**: A core query service that calculates stock gaps and suggests reorder quantities.
* **Draft PO Generation**: Grouping recommendations by branch and supplier to produce draft PO records securely.
* **Console Command Scheduling**: A scheduled console command that runs daily, boots tenant contexts safely, and populates draft replenishment POs.
* **RBAC Controls**: Restricting visibility, generation, and approval of reorder suggestions to authorized roles.

### Explicitly Out of Scope:
* **Automatic Supplier Transmission**: Suggestions are generated as `draft` POs *inside the IPOS system*. The system will **never** automatically email, API-push, or transmit POs to external vendors without explicit manager review and manual "Send" actions.
* **Automated Payments / AP Accounts Payable**: Automated settlement, invoicing, or matching bank records with suppliers is deferred to Epic 26.4 (3-Way AP Matching).
* **Multi-Branch Split POs**: Splitting a single large supplier order into multiple destination branches at the corporate level is deferred to Epic 26.5.
* **Automatic Stock Adjustments**: The scheduler never alters current inventory levels or posts adjustments; it only creates draft inbound documents.

---

## 3. Core Business & Mathematical Rules

### A. Reorder Threshold Calculation
To prevent stockouts while optimizing holding costs, replenishment utilizes a standard lead-time and safety stock model:

$$\text{Reorder Point (ROP)} = (\text{Daily Consumption Rate} \times \text{Supplier Lead Time in Days}) + \text{Safety Stock Buffer}$$

$$\text{Target Stock (PAR Level)} = \text{Optimal holding amount for the branch location}$$

$$\text{Replenishment Recommendation Quantity (RRQ)} = \text{Target Stock} - (\text{Current Stock} + \text{Outstanding PO Quantity})$$

*   **ROP (Reorder Point)**: The stock level that triggers a replenishment recommendation.
*   **Daily Consumption Rate**: Derived initially from historical sales data (e.g., 30-day average daily sales) or hardcoded as a fallback product velocity.
*   **Supplier Lead Time**: The number of days between placing a PO and receiving the physical stock.
*   **Safety Stock Buffer**: A static buffer to account for delivery delays or unexpected sales spikes.
*   **PAR Level**: The upper bound target representing the max quantity to hold.

### B. Current Stock Basis
*   **Source of Truth**: `BranchInventory` `current_stock` is the canonical reference.
*   **Expiry / Perishable Inclusion Rule**: Active, unexpired inventory is counted in the stock basis. Expired inventory (`ExpiryLot` where `expiry_date < now()`) is **excluded** from the current stock calculation.
*   **Near-Expiry Warning Buffer**: Stock expiring within a specific warning threshold (e.g., less than 3 days remaining) can optionally be discounted or flagged as "unreliable" in replenishment suggestions.

### C. Outstanding PO Quantity Inclusion
To prevent double-ordering the same product before the first order arrives:
*   The system must calculate and add all **Outstanding PO Quantities** to the "current stock basis."
*   **Outstanding PO Criteria**: Quantities of the product on any Purchase Order in the same branch that has a status of `draft`, `approved`, or `sent` (but not yet `received` or `completed`).
*   **Formula Verification**: If $\text{Current Stock} + \text{Outstanding PO Qty} \ge \text{Reorder Point}$, the suggestion trigger is skipped.

### D. Preferred Supplier Resolution
Every replenishment recommendation requires a resolved supplier:
1.  **Direct Pivot Match**: Check the product's `preferred_supplier_id`.
2.  **Fallback (Historical PO)**: If no preferred supplier is linked, resolve to the supplier from the most recently completed Purchase Order for that product in the same tenant.
3.  **Critical Failure/Alert Fallback**: If no supplier can be resolved, log an alert and create a placeholder recommendation under a system-designated `"Unassigned Supplier"` to allow manual manager routing.

---

## 4. Draft PO Grouping & Duplicate Prevention

### A. Grouping Rules
Suggestions must be batched atomically to avoid supplier/branch clutter:
*   **Grouping Key**: `(tenant_id, branch_id, supplier_id)`.
*   A single Purchase Order is created for each combination, housing all depleted products as distinct line items.

### B. Duplicate Recommendation Prevention
To avoid generating overlapping draft POs if the scheduler runs multiple times:
1.  If a `draft` Purchase Order already exists for the same `(tenant_id, branch_id, supplier_id)`, the service must **append/update** the existing draft PO instead of creating a new document.
2.  Line items already present in the existing draft PO have their quantities recalculated.
3.  New items are appended.
4.  Items no longer below threshold are removed or flagged.

---

## 5. Multi-Tenant Console Command Safety

Because the reorder scheduler runs as an automated background console command, strict multi-tenant isolation is paramount:

```php
// Conceptual Scheduler Isolation Loop
public function handle(TenantContext $tenantContext)
{
    $tenants = Tenant::where('status', 'active')->get();

    foreach ($tenants as $tenant) {
        // 1. Establish tenant scope
        $tenantContext->setTenant($tenant);

        DB::transaction(function () use ($tenant) {
            // 2. Perform isolated replenishment calculations
            $replenishmentService = app(ReplenishmentService::class);
            $replenishmentService->runForTenant($tenant);
        });

        // 3. Clear tenant context before next iteration
        $tenantContext->clear();
    }
}
```

### Key Safety Rules:
1.  **Fail-Closed Environment**: If setting a tenant context fails, the job must crash immediately and log an alert rather than continue execution with a null context.
2.  **Explicit Scoping**: All Eloquent queries must hook into the tenant-scoping global filter.
3.  **Queue Isolation**: If recommendations are queued, they must pass the `tenant_id` as part of the job payload and boot the context securely upon execution.

---

## 6. RBAC Boundaries

*   **Scheduler execution**: The console command runs with system-level supervisor privileges, but writes records securely scoped to each tenant.
*   **Draft PO Visibility**: Cashiers have **zero** visibility into replenishment suggestions or draft POs.
*   **Action Permissions**:
    *   `view_replenishment_suggestions`: Allowed for Branch Managers and Tenant Admins.
    *   `create_purchase_order`: Required to convert a suggestion draft PO into an active `approved` or `sent` Purchase Order.

---

## 7. Test Matrix for Implementation Verification

To validate the subsequent development phases, the implementation must pass the following test matrix:

| Test ID | Level | Target Domain | Scenario Description | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-26.2-01** | Unit | Thresholds | Stock level drops below ROP | Replenishment suggested for exact gap up to PAR. |
| **TC-26.2-02** | Unit | Outstanding POs | Stock below ROP, but open PO exists | Suggestion is skipped or reduced to account for incoming stock. |
| **TC-26.2-03** | Unit | Perishables | Expired lots exist in inventory | Expired stock is ignored; replenishment is triggered correctly. |
| **TC-26.2-04** | Integration | Grouping | Multiple products below threshold for same supplier | Single draft PO created with multiple line items. |
| **TC-26.2-05** | Integration | Duplicates | Running scheduler twice | Existing draft PO is updated/appended; no duplicate POs created. |
| **TC-26.2-06** | Integration | Tenant Safety | Scheduler runs tenant loop | Zero leakages; Tenant A's draft POs never reference Tenant B's branches. |
| **TC-26.2-07** | Integration | RBAC | Cashier tries to query suggestions | HTTP 403 Forbidden. |
