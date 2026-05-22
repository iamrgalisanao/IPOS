# Story 31.1 Scope Lock - Product Catalog Admin UX Review and Gap Lock

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Governance Ref: G-068

---

## 0. Story Intent

Story 31.1 defines the current Product/Catalog/Inventory admin surfaces, identifies UX and operational gaps, and locks acceptance boundaries for implementation stories 31.2+.

This story does not authorize runtime implementation.

---

## 1. Current Surface Inventory

### 1.1 Routes (Catalog and Inventory Admin)

Catalog and product admin routes:
- GET `/admin/product-categories` -> `Admin\\ProductCategoryController@index`
- POST `/admin/product-categories` -> `Admin\\ProductCategoryController@store`
- PUT `/admin/product-categories/{productCategory}` -> `Admin\\ProductCategoryController@update`
- DELETE `/admin/product-categories/{productCategory}` -> `Admin\\ProductCategoryController@destroy`
- GET `/admin/products` -> `Admin\\ProductController@index`
- GET `/admin/products/create` -> `Admin\\ProductController@create`
- POST `/admin/products` -> `Admin\\ProductController@store`
- GET `/admin/products/{product}/edit` -> `Admin\\ProductController@edit`
- PUT `/admin/products/{product}` -> `Admin\\ProductController@update`
- DELETE `/admin/products/{product}` -> `Admin\\ProductController@destroy`
- POST `/admin/products/{product}/branch-pricing` -> `Admin\\ProductController@updateBranchPricing`
- DELETE `/admin/products/{product}/branch-pricing/{branchPricing}` -> `Admin\\ProductController@destroyBranchPricing`
- POST `/admin/products/{product}/recipe` -> `Admin\\ProductController@updateRecipe`

Branch inventory policy admin routes:
- GET `/admin/branches` -> `Admin\\BranchPolicyController@index`
- PUT `/admin/branches/{branch}/inventory-policy` -> `Admin\\BranchPolicyController@update`

Inventory operations/support routes relevant to Epic 31 UX:
- GET `/inventory/movements` -> `Inventory\\InventoryMovementController@index` (JSON API)
- GET `/inventory/unit-conversions` -> `Inventory\\UnitConversionController@index`
- POST `/inventory/unit-conversions` -> `Inventory\\UnitConversionController@store`
- PUT `/inventory/unit-conversions/{unitConversion}` -> `Inventory\\UnitConversionController@update`
- DELETE `/inventory/unit-conversions/{unitConversion}` -> `Inventory\\UnitConversionController@destroy` (soft deactivate)
- GET `/inventory/reports/variance-logs` -> `Inventory\\VarianceLogController@index`
- GET `/inventory/reports/variance-logs/export` -> `Inventory\\VarianceLogController@export`

### 1.2 Controllers

Core controllers in current surface:
- `app/Http/Controllers/Admin/ProductController.php`
- `app/Http/Controllers/Admin/ProductCategoryController.php`
- `app/Http/Controllers/Admin/BranchPolicyController.php`
- `app/Http/Controllers/Inventory/UnitConversionController.php`
- `app/Http/Controllers/Inventory/VarianceLogController.php`
- `app/Http/Controllers/Inventory/InventoryMovementController.php`

### 1.3 React/Inertia Pages

Catalog and admin pages:
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`
- `resources/js/Pages/Admin/ProductCategories/Index.jsx`
- `resources/js/Pages/Admin/Branches/Index.jsx`

Inventory support/admin pages:
- `resources/js/Pages/Inventory/UnitConversions/Index.jsx`
- `resources/js/Pages/Inventory/VarianceLogs/Index.jsx`

Note:
- Inventory movement currently has JSON endpoint coverage via controller and tests, but no dedicated Inertia page in the current Back Office surface.

### 1.4 Services

Relevant service layer currently available:
- `app/Services/CatalogService.php`
  - category/product creation + tenant isolation checks + audit logging + POS-oriented search shaping
- `app/Services/InventoryService.php`
  - adjustment, stock-in, movement recording, sale deduction, isolation and immutability checks
- `app/Services/Inventory/StocktakePostingService.php`
- `app/Services/Inventory/StocktakeVarianceCsvExportService.php`

Observation:
- Admin product/category controllers currently perform direct model operations rather than delegating to a unified Back Office orchestration service for all mutations.

### 1.5 Tests

Catalog and pricing foundations:
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/CatalogSearchTest.php`

Feature-gate and entitlement behavior:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`

Branch policy and inventory support admin:
- `tests/Feature/Admin/BranchInventoryPolicyManagementTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`
- `tests/Feature/InventoryMovementTest.php`
- `tests/Feature/POS/InventoryMovementVisibilityTest.php`

---

## 2. Product/Catalog Route and Component Map

### 2.1 Product Categories
- Route group: `/admin/product-categories`
- Controller: `ProductCategoryController`
- Primary UI: `Admin/ProductCategories/Index.jsx`
- Current UX pattern: single page list + modal create/edit + delete

### 2.2 Products
- Route group: `/admin/products`
- Controller: `ProductController`
- Primary UI:
  - `Admin/Products/Index.jsx` (search/category filter/list/delete)
  - `Admin/Products/Create.jsx` (full create form)
  - `Admin/Products/Edit.jsx` (edit + branch pricing + recipe management)

### 2.3 Branch Pricing and Availability-adjacent Controls
- Branch pricing endpoints mounted under product routes
- Branch policy endpoint mounted under `/admin/branches`
- Primary UI:
  - `Admin/Products/Edit.jsx` for branch price overrides
  - `Admin/Branches/Index.jsx` for deduction policy

### 2.4 Recipe and Ingredient Mapping
- Endpoint: POST `/admin/products/{product}/recipe`
- Controller method: `ProductController@updateRecipe`
- UI: embedded in `Admin/Products/Edit.jsx`

### 2.5 Inventory Support Surfaces
- Unit conversion admin: `Inventory/UnitConversions/Index.jsx`
- Variance log viewer/export: `Inventory/VarianceLogs/Index.jsx`
- Inventory movements: API surface only (`InventoryMovementController@index`), no dedicated admin Inertia page yet

---

## 3. Current UX Gaps

### 3.1 Product List
- No explicit inventory availability snapshot (per-branch or aggregate) in product list rows.
- No bulk actions (status update, category reassignment, export) for product operations.
- No saved filter presets or operator-specific list states.

### 3.2 Create/Edit Forms
- Create/edit forms are feature-rich but visually dense; no progressive disclosure for advanced fields.
- Validation error ergonomics are mostly field-level; no grouped summary for fast remediation.
- Edit flows combine multiple concerns (metadata, pricing, recipe) in one surface, which can increase operational friction.

### 3.3 Category Management
- Category management is modal-driven only; no dedicated detail/history surface.
- No explicit dependency insight before destructive actions beyond immediate delete prevention.

### 3.4 Branch Pricing
- Branch pricing exists only in product edit context; no branch-centric or matrix-style maintenance view.
- No clear bulk update workflow for multi-branch price alignment.

### 3.5 Availability
- No unified Back Office availability dashboard that combines product status, inventory-tracked flags, and branch-level stock visibility in one operational page.
- Inventory movement is API-only in current surface; lacks a dedicated admin UX for cross-role investigation.

### 3.6 Recipe / Ingredient Links
- Recipe editing is embedded and functional, but lacks dedicated recipe management workspace, versioning cues, and impact preview.
- No centralized ingredient dependency map for change-impact checks.

### 3.7 Import / Export
- No catalog import/export routes are present for `/admin/products` or `/admin/product-categories`.
- Existing CSV export support is available for variance logs and other domains, but not yet for catalog bulk administration.

---

## 4. Acceptance Boundaries

### 4.1 What Stories 31.2+ May Implement

Allowed implementation direction for next stories:
- 31.2: Product create/edit UX hardening (form structure, validation UX, workflow clarity)
- 31.3: Branch pricing and availability management UI improvements
- 31.4: Inventory overview and stock visibility dashboard surfaces
- 31.5: Dedicated recipe/ingredient admin management enhancements
- 31.6: Catalog import/export pathways with audit hardening and safety controls

### 4.2 What Remains Deferred

Deferred unless separately approved:
- POS runtime checkout behavior changes
- Billing/subscription engine redesign
- Offline sync/posting engine changes
- Tax/receipt/GCT/Z-read/e-journal engine changes
- Persona schema redesign and cross-tenant privilege model changes

---

## 5. RBAC and Feature-Gate Expectations

### 5.1 RBAC Expectations

Minimum role/permission enforcement expectations:
- `manage_products` required for product/category write actions and catalog admin interaction.
- `manage_unit_conversions` required for conversion rule management.
- `view_inventory_reports|audit_inventory` required for variance log viewing/export.
- `edit_branch_policy` required for branch deduction policy updates.
- `view_branch_inventory` required for inventory movement visibility API.

### 5.2 Feature-Gate Expectations

Subscription entitlements must continue to fail closed:
- `catalog.view` gates list/index reads for product/category admin surfaces.
- `catalog.edit` gates all write and edit form routes.
- Mixed entitlement scenario (`catalog.view=true`, `catalog.edit=false`) must allow view-only behavior and block writes.

### 5.3 Isolation Expectations

Must remain intact across all follow-up stories:
- tenant isolation for reads/writes
- branch isolation where branch context is required
- no cross-tenant assignment leakage for categories, products, pricing, or recipe links

---

## 6. Audit and Data Integrity Expectations

Audit/integrity requirements to preserve in 31.2+:
- Append-only behavior for inventory movement and variance evidence remains unchanged.
- CSV export safety controls (formula-injection mitigation) remain enforced for export features.
- Critical catalog mutation operations should remain auditable and attributable.
- Validation invariants (e.g., uniqueness in scope, allowed enums, non-negative monetary values) remain fail-closed.

Implementation note for future stories:
- Where controller-level model mutations are expanded, ensure audit coverage remains explicit and testable.

---

## 7. Test Strategy (Execution Target for 31.2+)

### 7.1 Role Checks
- Verify allowed/forbidden behavior for owner/admin, branch manager, and cashier-equivalent roles where applicable.

### 7.2 Tenant Isolation
- Verify catalog/category/pricing/recipe operations never cross tenant boundaries.

### 7.3 Branch Isolation
- Verify branch-scoped pricing, policy, and movement views are constrained to authorized branch context.

### 7.4 Validation Failures
- Verify server-side validation for create/edit forms, conversion rules, and policy updates returns expected errors and preserves safe state.

### 7.5 Feature-Gate Behavior
- Verify `catalog.view` and `catalog.edit` behavior for read/write routes and edit forms remains fail-closed.

### 7.6 Build Validation
- Require frontend build (`npm run build`) and targeted feature suites for affected surfaces before story closure.

Suggested targeted suite baseline for Epic 31 follow-up stories:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Admin/BranchInventoryPolicyManagementTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 8. Governance Lock

Story 31.1 is planning and gap-lock only.
No implementation beyond documentation is approved by Story 31.1.
