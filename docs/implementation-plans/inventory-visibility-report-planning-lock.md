# Slice R5 Planning Lock: Inventory Visibility Report

## 1. Status

Implemented and locally validated.

Closure evidence is recorded in `docs/validation/inventory-visibility-report-closure.md`.

## 2. Purpose

Create a read-only Inventory Visibility Report to provide managers with a real-time view of current stock levels, low stock/reorder alerts, and unsold/slow-moving items, using only existing stored inventory and product data. No mutation or automation is introduced.

## 3. Scope

### Minimum Deliverables

1. Inventory table:
   - product name, SKU/barcode
   - category
   - current stock/quantity on hand
   - reorder level (if available)
   - low stock indicator
   - expiry date (if tracked)
   - last movement date
   - unsold/slow-moving indicator
2. Filters:
   - branch
   - category
   - product search
   - low stock only
   - expiry/soon-to-expire only
3. Summary cards:
   - total SKUs tracked
   - SKUs below reorder
   - SKUs with expiry risk
   - slow-moving/unsold SKUs
4. Outputs:
   - CSV export
   - print-friendly layout
5. Navigation:
   - Add under Inventory Reports
   - Link back to Inventory Dashboard

## 4. Hard Boundaries (Explicit Non-Goals)

- No inventory mutation
- No automated reorder or procurement
- No recipe deduction or stock forecasting
- No scheduled report automation
- No PDF generation
- No BIR/compliance-format claim

## 5. Data Source Review

Review and document current data sources for:
- Product and ProductCategory models
- InventoryMovement, StocktakeSession, ExpiryLot
- SaleItem for movement/unsold detection
- Branch and tenant scoping
If any required field is unavailable, mark as `Deferred / unavailable from current source`.

## 6. Permission and Tenant Boundary

1. Report must remain tenant-scoped.
2. Branch filtering must respect user permissions and branch assignments.
3. Unauthorized users must not access the report or export.
4. Exports must use the same permission boundary as the screen report.

## 7. Acceptance Criteria

1. All required tables and summary cards are present and accurate.
2. Filters and navigation match scope.
3. Permission and branch/tenant boundaries are strictly enforced.
4. CSV export matches on-screen data and boundaries.
5. No mutation or compliance-format changes.
6. All new/clarified behaviors are covered by tests.

## 8. Recommended Tests

- unauthorized users cannot access Inventory Visibility Report
- report is tenant-scoped
- branch-limited users only see assigned branch inventory
- low stock/expiry/slow-moving logic is correct
- CSV export respects filters and permissions
- print layout does not expose cross-branch data

## 9. Planning-Task Boundary Confirmation

Confirmed for this planning lock task:
- No runtime mutation logic is changed.
- No schema changes are introduced.
- No scheduled reporting or automation is implemented.
- No inventory/procurement logic is changed.

## 10. Next Gate

Slice R5 implementation is complete and locally validated.

## 11. Implementation Closure Evidence

Implemented:

1. New read-only route group:
   - `inventory.reports.visibility.index`
   - `inventory.reports.visibility.export`
2. New controller:
   - `app/Http/Controllers/Inventory/InventoryVisibilityReportController.php`
3. New service:
   - `app/Services/Inventory/InventoryVisibilityReportService.php`
4. New Inertia page:
   - `resources/js/Pages/Inventory/Visibility/Index.jsx`
5. New focused tests:
   - `tests/Feature/Inventory/InventoryVisibilityReportTest.php`

Validation:

1. `php artisan test tests/Feature/Inventory/InventoryVisibilityReportTest.php tests/Feature/Inventory/InventoryDashboardTest.php tests/Feature/Inventory/ProductCompositionReportTest.php tests/Feature/Inventory/VarianceLogAuditingTest.php`
   - 29 tests passed.
   - 353 assertions passed.
2. `npm run build`
   - passed.

Closure report:

1. `docs/validation/inventory-visibility-report-closure.md`
