---
title: 'Epic 27 Phase 1 — Ingredient Inventory Upgrade'
type: 'feature'
created: '2026-05-19T14:23:26+08:00'
status: 'in-progress'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Standard cashiers are blocked from completing transactions at the POS if theoretical stock levels for a recipe ingredient fall below zero, even if physical stock is available. Additionally, unit conversion values are currently hardcoded, preventing dynamic configurations.

**Approach:** Implement a dynamic unit conversion engine with product-specific overrides. Provide a branch-level setting `inventory_deduction_policy` allowing branches to choose between strict block and negative stock deduction with warning/variance logging.

## Boundaries & Constraints

**Always:**
- All dynamic unit conversions and inventory variance logs must be strictly tenant-scoped via the `BelongsToTenant` trait.
- Log exact shortage details to `inventory_variance_logs` when a shortage is encountered in `allow_negative_with_warning` mode.
- Prevent modification or deletion of variance logs by throwing a `RuntimeException` in boot events to ensure they remain strictly append-only.
- **Fail-Closed Application Guard:** If `inventory_deduction_policy` is invalid, null, or corrupted, the system must fail-closed as `strict_block` inside the application.
- **Scope Restriction:** This policy applies only to POS inventory deduction during sale/payment posting. It does not change procurement receiving, RMA, IBT, stocktake, or manual adjustment logic.
- **Precedence Lookup Order for Unit Conversions:**
  1. Product-specific conversion rule for `tenant_id` + `product_id` + `from_unit` + `to_unit`
  2. Tenant/global conversion rule where `product_id` is null for `tenant_id` + `from_unit` + `to_unit`
  3. Metric fallback conversion logic
  4. Fail transaction if no conversion is resolvable
- **Uniqueness Constraints for `unit_conversions`:**
  - Unique composite index on `(tenant_id, product_id, from_unit, to_unit)`
  - Unique composite index on `(tenant_id, from_unit, to_unit)` where `product_id` is null

**Ask First:**
- N/A

**Never:**
- Do not implement multi-level BOMs.
- Do not implement recursive recipe deductions.
- Do not implement weighted average costing (WAC) changes or COGS analytics.
- Do not bypass warning/variance logging in `allow_negative_with_warning` mode.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Strict Block Shortage | Policy = `strict_block`, Stock = 1.0, Required = 2.0 | Transaction rolls back, throws `RuntimeException` | Caught by POS payment handlers to return user-friendly validation error |
| Soft Deduction Shortage | Policy = `allow_negative_with_warning`, Stock = 1.0, Required = 2.0 | Transaction completes, stock decremented to -1.0, entry in `inventory_variance_logs` | deficit recorded, negative movement tracked |
| Invalid Policy | Policy = `corrupt_value`, Stock = 1.0, Required = 2.0 | Falls back to `strict_block`, transaction rolls back | Throws `RuntimeException` |
| Dynamic Conversion override | Unit conversion rule (1 Bag = 2.5 kg) exists for Product A | Quantities converted using 2.5 factor | Throws `RuntimeException` if unit conversion fails and no metric fallback exists in strict mode |
| Product Override | Global: 1 Bag = 2.5 kg, Product override: 1 Bag = 5.0 kg | Product override factor (5.0) takes precedence | N/A |

</frozen-after-approval>

## Code Map

- `app/Services/InventoryService.php` -- Contains the main `deductFromSale` and `convertUnit` logic.
- `app/Models/Branch.php` -- Will add the `inventory_deduction_policy` attribute to `$fillable`.
- `app/Models/UnitConversion.php` -- New model for the `unit_conversions` table.
- `app/Models/InventoryVarianceLog.php` -- New model for the `inventory_variance_logs` table.
- `database/migrations/2026_05_19_100001_add_deduction_policy_to_branches_table.php` -- Migration to add deduction policy to branches.
- `database/migrations/2026_05_19_100002_create_unit_conversions_table.php` -- Migration to create the unit conversions table.
- `database/migrations/2026_05_19_100003_create_inventory_variance_logs_table.php` -- Migration to create the inventory variance logs table.
- `tests/Feature/POS/InventoryDeductionPolicyTest.php` -- Feature tests verifying the new logic.

## Tasks & Acceptance

**Execution:**
- [x] `database/migrations/2026_05_19_100001_add_deduction_policy_to_branches_table.php` -- Create migration to add `inventory_deduction_policy` (default `'strict_block'`) to `branches` -- Ensures branch-level policy configuration.
- [x] `database/migrations/2026_05_19_100002_create_unit_conversions_table.php` -- Create migration for `unit_conversions` table with uniqueness constraints -- Prepares unit conversion schema.
- [x] `database/migrations/2026_05_19_100003_create_inventory_variance_logs_table.php` -- Create migration for `inventory_variance_logs` table with refined fields (`available_quantity_before`, `resulting_quantity`, `policy`, `reason`, `metadata`) -- Prepares variance tracking schema.
- [x] `app/Models/Branch.php` -- Add `inventory_deduction_policy` to fillable attributes -- Allows policy settings mutation.
- [x] `app/Models/UnitConversion.php` -- Implement `UnitConversion` model with multi-tenant scoping and uniqueness validation rules -- Provides unit conversion definition.
- [x] `app/Models/InventoryVarianceLog.php` -- Implement `InventoryVarianceLog` model with multi-tenant scoping and immutability controls -- Safeguards historical records.
- [x] `app/Services/InventoryService.php` -- Update unit conversion logic to search database rules before metric fallback. Update deduction logic to support soft negative stock deductions and log warnings/variances -- Implements core business logic.
- [x] `tests/Feature/POS/InventoryDeductionPolicyTest.php` -- Add comprehensive scenario validation -- Verifies policy-aware deductions.

**Acceptance Criteria:**
- Given branch has `strict_block` policy and insufficient stock, when payment is posted, then payment fails and database changes are rolled back.
- **Rollback Verification:** Given `strict_block` shortage occurs, verify no sale, payment, stock movement, or inventory deduction is committed (everything is completely rolled back).
- Given branch has `allow_negative_with_warning` policy and insufficient stock, when payment is posted, then payment succeeds, stock balance is negative, and a log is written to `inventory_variance_logs`.
- **Variance Log Minimal Fields:** Ensure that `inventory_variance_logs` records: `tenant_id`, `branch_id`, `product_id`, `sale_id`, `required_quantity`, `available_quantity_before`, `shortage_quantity`, `resulting_quantity`, `policy`, `reason`, and `metadata`.
- Given database unit conversion rules are configured, when recipe ingredient is deducted, then dynamic conversion ratio is applied.
- Given product-specific and global conversion rules exist, when recipe ingredient is deducted, then product-specific ratio is applied.
- **Fail-Closed Application Guard:** If `inventory_deduction_policy` is invalid or null, then it must fail-closed as `strict_block`.

## Verification

**Commands:**
- `./vendor/bin/phpunit tests/Feature/POS/InventoryDeductionPolicyTest.php` -- expected: Tests pass successfully.
- `./vendor/bin/phpunit tests/Feature/POS/InventoryDeductionTest.php` -- expected: Baseline tests continue to pass.
- `./vendor/bin/phpunit tests/Feature/POS/InventoryDeductionFailureUXTest.php` -- expected: Baseline UX tests continue to pass.
