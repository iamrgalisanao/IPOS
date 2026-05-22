# Epic 27 Context: Ingredient Inventory Upgrade

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Provide a dynamic unit conversion engine and branch-level inventory deduction policy configurations (strict block vs. allow negative stock) to prevent cashiers from being blocked during POS transactions while maintaining strict traceability and audit controls.

## Stories

- Story 27.1: Ingredient Inventory Upgrade (Phase 1) Backend Core
- Story 27.2: Ingredient Inventory Upgrade (Phase 1) UI & Admin Management

## Requirements & Constraints

- All dynamic unit conversions and inventory variance logs must be strictly tenant-scoped.
- Branch policy toggles between `strict_block` and `allow_negative_with_warning`.
- Product-specific conversion overrides take priority over global conversion rules, which fall back to metric system checks, then checkout failure.
- Variance logs are append-only. Modifying or deleting them raises a `RuntimeException`.
- POS checkout in `strict_block` mode must perform database transaction rollback if inventory is insufficient.

## Technical Decisions

- Database table `unit_conversions` with columns `tenant_id`, `product_id`, `from_unit`, `to_unit`, `conversion_factor`, `is_active`.
- Unique indexes on `(tenant_id, product_id, from_unit, to_unit)` and `(tenant_id, from_unit, to_unit) where product_id is null`.
- Database table `inventory_variance_logs` with columns `tenant_id`, `branch_id`, `product_id`, `sale_id`, `required_quantity`, `available_quantity_before`, `shortage_quantity`, `resulting_quantity`, `policy`, `reason`, `metadata`.
- Custom global query scope / BelongsToTenant trait applied to both.
- Immutability boot listeners on `InventoryVarianceLog` model.

## UX & Interaction Patterns

- Branch Settings UI: Toggles for deduction policy with descriptive helper text.
- Unit Conversions Management UI: Table listing with filters, pagination, creation modal (with global/override scope radio buttons, UOM input fields, and conversion factor), edit/deactivate actions.
- Variance Logs Viewer: Strictly read-only audit log dashboard with date/branch/ingredient filters and a CSV export action.
