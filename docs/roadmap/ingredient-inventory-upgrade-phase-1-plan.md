# Implementation Plan: IPOS Ingredient Inventory Upgrade — Phase 1

**Status: PLANNED / PROPOSED**

## 1. Overview & Objectives

Phase 1 of the IPOS Ingredient Inventory Upgrade focuses on introducing dynamic unit conversions and flexible stock deduction rules to prevent cashiers from being blocked during transaction checkout when theoretical inventory levels are out of sync with physical stock.

### Key Objectives:
1.  **Dynamic Unit Conversion Foundation**: Transition from static, hardcoded unit conversion ratios to a tenant-scoped, product-overridable configuration table.
2.  **Configurable Inventory Deduction Policy**: Provide a branch-level setting to define whether a checkout transaction should fail on inventory shortage (`strict_block`) or proceed with a warning/variance log (`allow_negative_with_warning`).
3.  **Variance & Warning Logging**: Implement a structured variance ledger to capture inventory shortages, facilitating auditing and reconciliations.
4.  **Regression Protection**: Ensure existing direct and recipe-based deduction behaviors remain intact and fully covered by feature tests.

---

## 2. Execution Boundaries & Guardrails

To prevent data contamination and ensure compliance with BIR regulations and multi-tenant constraints, the following rules must be strictly enforced:
1.  **Multi-Tenant Fail-Closed Isolation**: All queries against the `unit_conversions` and `inventory_variance_logs` tables must be automatically scoped by `TenantContext`. Cross-tenant reads/writes are strictly prohibited.
2.  **Statutory / Audit Immutability**: Variance log entries are historical audit records and must be **strictly read-only** once written. They may never be mutated or deleted.
3.  **Non-Disruptive Fallbacks**: If a conversion factor is missing or invalid, the system must fail gracefully in strict mode and fallback safely to default metric rules if applicable.

---

## 3. Database Schema Extensions

### A. Add Policy Setting to Branches Table
Extend the `branches` table:
*   `inventory_deduction_policy`: string (default `'strict_block'`) - Allowed values: `'strict_block'`, `'allow_negative_with_warning'`.

### B. Unit Conversions Table (`unit_conversions`)
This table holds the custom conversion ratios from one unit of measure to another.
*   `id`: UUID, primary key
*   `tenant_id`: UUID, foreign key referencing `tenants.id` (cascade delete)
*   `product_id`: UUID, foreign key referencing `products.id` (cascade delete), nullable - If set, this conversion applies specifically to the selected product. If null, it acts as a tenant-wide global conversion.
*   `from_unit`: string - The source unit code (e.g., `'crate'`, `'bag'`, `'piece'`).
*   `to_unit`: string - The target base unit code (e.g., `'kg'`, `'gram'`).
*   `conversion_factor`: decimal(19, 4) - The multiplier factor (e.g., if 1 crate = 10 kg, factor is `10.0000`).
*   `is_active`: boolean (default `true`)
*   `timestamps`

**Constraints**:
*   Unique composite index on `['tenant_id', 'product_id', 'from_unit', 'to_unit']` (treating product-specific null fields properly) to prevent duplicate definitions.

### C. Inventory Variance Logs Table (`inventory_variance_logs`)
Tracks instances where stock deduction encountered shortages when the branch policy allows negative stock.
*   `id`: UUID, primary key
*   `tenant_id`: UUID, foreign key referencing `tenants.id` (cascade delete)
*   `branch_id`: UUID, foreign key referencing `branches.id` (cascade delete)
*   `sale_id`: UUID, foreign key referencing `sales.id` (cascade delete)
*   `product_id`: UUID, foreign key referencing `products.id` (cascade delete) - The composite product sold (nullable if direct deduction).
*   `ingredient_id`: UUID, foreign key referencing `products.id` (cascade delete) - The raw ingredient or product that was short.
*   `required_quantity`: decimal(19, 4) - The theoretical quantity required.
*   `available_quantity`: decimal(19, 4) - The actual quantity available in stock before deduction.
*   `shortage_quantity`: decimal(19, 4) - The calculated deficit amount (`required - available`).
*   `unit`: string - The unit of measure at which the shortage was logged.
*   `policy_used`: string - The active deduction policy at checkout (e.g., `'allow_negative_with_warning'`).
*   `created_by`: UUID, foreign key referencing `users.id` (nullable)
*   `timestamps`

---

## 4. Model Specifications

### A. `UnitConversion` Model
*   Uses `HasFactory`, `HasUuids`, `BelongsToTenant`.
*   Mass assignable fields: `tenant_id`, `product_id`, `from_unit`, `to_unit`, `conversion_factor`, `is_active`.
*   Relationships:
    *   `product()`: BelongsTo `Product` (nullable).
*   Casts:
    *   `conversion_factor` => `decimal:4`.
    *   `is_active` => `boolean`.

### B. `InventoryVarianceLog` Model
*   Uses `HasFactory`, `HasUuids`, `BelongsToTenant`.
*   Mass assignable fields: `tenant_id`, `branch_id`, `sale_id`, `product_id`, `ingredient_id`, `required_quantity`, `available_quantity`, `shortage_quantity`, `unit`, `policy_used`, `created_by`.
*   Relationships:
    *   `branch()`: BelongsTo `Branch`.
    *   `sale()`: BelongsTo `Sale`.
    *   `product()`: BelongsTo `Product`.
    *   `ingredient()`: BelongsTo `Product`.
    *   `creator()`: BelongsTo `User` (foreign key `created_by`).
*   Casts:
    *   `required_quantity` => `decimal:4`.
    *   `available_quantity` => `decimal:4`.
    *   `shortage_quantity` => `decimal:4`.
*   **Immutability Rule**: Prevent modifications/deletions on model boot.
    ```php
    protected static function booted()
    {
        static::updating(function ($log) {
            throw new \RuntimeException('Inventory variance logs are historical records and cannot be modified.');
        });
        static::deleting(function ($log) {
            throw new \RuntimeException('Inventory variance logs cannot be deleted.');
        });
    }
    ```

---

## 5. Service Layer Refactoring (`InventoryService`)

Refactor [`InventoryService::deductFromSale()`](file:///Users/teamsolo/Documents/Dev/IPOS/app/Services/InventoryService.php#L261) to incorporate the following:

### A. Dynamic Unit Conversion Logic
Update `convertUnit(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null)`:
1.  Query the `unit_conversions` table:
    *   First check for an active product-specific rule: `product_id = $productId`, `from_unit = $fromUnit`, `to_unit = $toUnit`.
    *   If not found, check for an active tenant-wide global rule: `product_id IS NULL`, `from_unit = $fromUnit`, `to_unit = $toUnit`.
2.  If a database rule is found:
    *   Multiply `$quantity` by `conversion_factor`.
3.  If no database rule is found:
    *   Fallback to standard metric conversions (e.g., `kg` $\leftrightarrow$ `gram`, `liter` $\leftrightarrow$ `ml`).
    *   If units match, return quantity as-is.
    *   If units differ and no conversion exists, throw a `RuntimeException` in `strict_block` mode.

### B. Policy-Based Deduction Guard
Update `performDeduction(BranchInventory $inventory, float $quantityChange, \App\Models\Sale $sale, ?string $extraRemarks = null, ?string $parentProductId = null)`:
1.  Retrieve the branch policy: `$policy = $sale->branch->inventory_deduction_policy ?? 'strict_block'`.
2.  Calculate potential stock levels:
    *   `$quantityBefore = $inventory->current_stock`
    *   `$quantityAfter = $quantityBefore - $quantityChange`
3.  Evaluate Policy:
    *   **If `$quantityAfter < 0`**:
        *   **Strict Block (`strict_block`)**:
            *   Throw `RuntimeException` (triggers transaction rollback).
        *   **Soft Negative (`allow_negative_with_warning`)**:
            *   Calculate shortage: `$shortage = abs($quantityAfter)`.
            *   Deduct stock (allowing negative value) and update `BranchInventory`.
            *   Write to `inventory_variance_logs` with all details.
            *   Log an audit trail event warning of negative stock deduction.
    *   **If `$quantityAfter >= 0`**:
        *   Proceed with standard deduction and write normal `inventory_movements`.

---

## 6. Security, Audit, and Compliance
*   **Tenant Isolation**: All queries for unit conversions and variance records are automatically scoped to the active tenant via `BelongsToTenant`.
*   **Audit Trail Logs**:
    *   Soft deductions will append a dedicated audit event `inventory_negative_deduction_warning` detailing the shortage quantity and affected item to `audit_logs`.

---

## 7. Verification & Test Plan

Add new feature tests to `tests/Feature/POS/InventoryDeductionPolicyTest.php`:

### Test Coverage Matrix:
1.  **Strict Mode Enforcement**:
    *   Verify checkout fails and database transactions rollback when an ingredient is out of stock in `strict_block` mode.
2.  **Soft Mode Deduction**:
    *   Verify checkout completes successfully when stock is insufficient under `allow_negative_with_warning`.
    *   Verify the branch inventory level goes negative.
    *   Verify a record is created in `inventory_variance_logs` containing the exact deficit amount, policy name, and transaction identifiers.
    *   Verify a negative `inventory_movements` record is recorded for traceability.
3.  **Database Unit Conversions**:
    *   Define a custom conversion (e.g., `1 bag = 2.5 kg`) on the `unit_conversions` table. Verify that deducting 2 bags of ingredient correctly decrements the inventory by 5 kg.
    *   Define a product-specific override (e.g., `1 bag = 5 kg` for Espresso Beans, while global is `1 bag = 2.5 kg`). Verify product override takes precedence.
    *   Ensure fallback metric rules (e.g. `kg` $\rightarrow$ `gram`) continue to function when no database rule is defined.
4.  **Regression Protections**:
    *   Run the complete existing POS checkout and stock adjustment suite to verify zero impact on normal sale deductions and stocktaking.
