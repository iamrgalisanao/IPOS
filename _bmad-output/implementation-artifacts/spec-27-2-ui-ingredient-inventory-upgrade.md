---
title: 'Epic 27 Phase 1 — Ingredient Inventory Upgrade (UI)'
type: 'feature'
created: '2026-05-19T16:58:18+08:00'
status: 'in-progress'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The backend core for policy-based inventory deduction and dynamic unit conversions is ready, but administrative users lack a web interface to toggle branch-level deduction policies, manage global or product-specific unit conversion rules, and view read-only inventory variance audit logs.

**Approach:** Implement the React components, Inertia route controllers, validation, and permissions required to manage deduction policies, edit unit conversion rules, and view variance audit reports with CSV streaming.

## Boundaries & Constraints

**Always:**
- Apply the `BelongsToTenant` scope to all database queries (conversions, branches, variance logs) to prevent cross-tenant exposure.
- Enforce strict RBAC permissions:
  - `edit_branch_policy` or `manage_branches` to toggle branch policies.
  - `manage_unit_conversions` or `manage_inventory` to manage (create/edit/deactivate) conversion rules.
  - `view_inventory_reports` or `audit_inventory` to view variance logs.
- Fails closed: invalid, null, or corrupted deduction policies resolve as `strict_block`.
- Conversions must enforce composite uniqueness: `(tenant_id, product_id, from_unit, to_unit)` and `(tenant_id, from_unit, to_unit) where product_id is null`.
- Variance logs are append-only; no POST/PUT/DELETE API endpoints or interface mutations are allowed.
- Keep Branch Policy UI small: Only expose or support the inventory deduction policy field. Do not redesign the full Branch Management UI.
- Use deactivation (`is_active = false`) instead of hard deletion for unit conversions to preserve historical traceability.
- Variance CSV Export formula-injection safety: prefix any dangerous spreadsheet values beginning with `=`, `+`, `-`, or `@` with a single quote `'`.

**Ask First:**
- None.

**Never:**
- Allow modifying or deleting variance logs.
- Allow cross-tenant data leaks.
- Expose conversion rules or policy endpoints without appropriate permission middleware gates.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Edit Branch Policy - Success | PUT `/admin/branches/{branch}/inventory-policy` with valid policy | Updates branch in DB, returns 302 redirect with flash success | Returns 403 if unauthorized, 422 if invalid policy |
| Add Global Conversion - Success | POST `/inventory/unit-conversions` (product_id: null, from_unit: "Crate", to_unit: "Piece", conversion_factor: 10) | Creates conversion record in database | Returns 422 on negative/zero factors, or if duplicate rule exists |
| Add Override Conversion - Success | POST `/inventory/unit-conversions` (product_id: "uuid-beans", from_unit: "Bag", to_unit: "kg", conversion_factor: 2.5) | Creates product-specific override conversion record | Returns 422 on invalid/missing uuid-beans |
| View Variance Logs - Paginated | GET `/inventory/reports/variance-logs` with filters | Returns paginated logs list with applied filters (date range, branch, ingredient) | Returns 403 if unauthorized |
| Export Variance CSV | GET `/inventory/reports/variance-logs/export` | Streams filtered variance log data in CSV format with formula injection protection | Streams empty CSV if no match found |

</frozen-after-approval>

## Code Map

- `routes/web.php` -- Define routing endpoints for policy updates, unit conversion CRUD, and log reviews.
- `app/Services/RbacSeeder.php` -- Register new permissions (`edit_branch_policy`, `manage_unit_conversions`, `audit_inventory`, `view_inventory_reports`) and associate them with Owner/Admin, Branch Manager, and Accountant.
- `app/Http/Controllers/Admin/BranchPolicyController.php` -- Branch settings toggle action handler.
- `app/Http/Controllers/Inventory/UnitConversionController.php` -- CRUD controller for managing global/override rules (using deactivation instead of hard delete).
- `app/Http/Controllers/Inventory/VarianceLogController.php` -- Read-only reporting and CSV export.
- `resources/js/Pages/Inventory/UnitConversions/Index.jsx` -- React UI for listing and editing conversion rules.
- `resources/js/Pages/Inventory/VarianceLogs/Index.jsx` -- React UI for auditing shortages and trigger export.
- `resources/js/Layouts/AuthenticatedLayout.jsx` -- Navigation links for Settings/Logs gated by permission checks.

## Tasks & Acceptance

**Execution:**
- [ ] `app/Services/RbacSeeder.php` -- Register permission keys (`edit_branch_policy`, `manage_unit_conversions`, `audit_inventory`, `view_inventory_reports`) and map them to roles:
  - `Owner/Admin`: All permissions
  - `Branch Manager`: `edit_branch_policy`, `view_inventory_reports`, `audit_inventory`
  - `Accountant`: `view_inventory_reports`, `audit_inventory`
- [ ] `routes/web.php` -- Add route definitions gated by proper RBAC middlewares.
- [ ] `app/Http/Controllers/Admin/BranchPolicyController.php` -- Implement branch deduction policy update endpoint.
- [ ] `app/Http/Controllers/Inventory/UnitConversionController.php` -- Implement CRUD actions and validation for unit conversions, ensuring `destroy` deactivates the record via `is_active = false`.
- [ ] `app/Http/Controllers/Inventory/VarianceLogController.php` -- Implement filtered lists and CSV export action with formula injection safety (prefix values starting with `=`, `+`, `-`, or `@` with `'`).
- [ ] `resources/js/Pages/Inventory/UnitConversions/Index.jsx` -- Add unit conversion UI with creation/edition modals.
- [ ] `resources/js/Pages/Inventory/VarianceLogs/Index.jsx` -- Add variance log audit table with CSV export trigger.
- [ ] `resources/js/Layouts/AuthenticatedLayout.jsx` -- Gate Unit Conversions and Variance Logs menu items under correct permissions.
- [ ] `tests/Feature/Admin/BranchInventoryPolicyManagementTest.php` -- Create feature tests for branch inventory policy update validation, RBAC, and tenant boundary.
- [ ] `tests/Feature/Inventory/UnitConversionManagementTest.php` -- Create feature tests for validation, RBAC, and tenant boundary.
- [ ] `tests/Feature/Inventory/VarianceLogAuditingTest.php` -- Create feature tests verifying read-only logging and CSV export streaming with formula protection.

**Acceptance Criteria:**
- Given an administrator user, when updating a branch's policy, then the change is written and POS behavior follows immediately.
- Given a cashier, when trying to create/edit/delete a conversion rule, then a 403 status is returned.
- Given conversion rules with identical scope and units, when trying to store them, then a 422 validation error is raised.
- Given variance logs on branch A, when a user from tenant B queries logs or triggers a CSV export, then only tenant B data is visible.
- When exporting variance logs as CSV, any field value beginning with `=`, `+`, `-`, or `@` must be prefixed with a single quote `'`.

## Spec Change Log

None.

## Design Notes

None.

## Verification

**Commands:**
- `vendor/bin/phpunit tests/Feature/Admin/BranchInventoryPolicyManagementTest.php` -- expected: green/passing
- `vendor/bin/phpunit tests/Feature/Inventory/UnitConversionManagementTest.php` -- expected: green/passing
- `vendor/bin/phpunit tests/Feature/Inventory/VarianceLogAuditingTest.php` -- expected: green/passing
