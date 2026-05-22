# Story 31.5 Scope Lock - Recipe / Ingredient Admin Management UI

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.5 - Recipe / Ingredient Admin Management UI
Governance Ref: G-068
Predecessor: Story 31.4 - Inventory Overview and Stock Visibility Dashboard (Closed)

---

## 0. Story Intent

Story 31.5 defines approved planning boundaries for improving recipe and
ingredient management admin UX using existing product, recipe, ingredient, and
unit-of-measure surfaces.

This story authorizes planning only.

---

## 1. Goal

Improve recipe and ingredient management usability for Back Office operators
without changing recipe/BOM computation, inventory deduction behavior,
costing/WAC/FEFO behavior, POS checkout behavior, tax/accounting behavior,
backend endpoint contracts, subscription gates, RBAC checks, or tenant/branch
isolation.

---

## 2. Current Surface Baseline

Current recipe and ingredient management surfaces in the codebase:

Pages:
- `resources/js/Pages/Admin/Products/Edit.jsx`
  - embedded Recipe / Ingredients entry point
  - ingredient search/select interaction
  - recipe row quantity and unit editing

Routes:
- POST `/admin/products/{product}/recipe`
  - route name: `admin.products.recipe.update`

Controller:
- `app/Http/Controllers/Admin/ProductController.php`
  - `edit()` loads `recipes.ingredient` and `allProducts`
  - `updateRecipe()` replaces recipe rows for the selected product

Models:
- `app/Models/Product.php`
  - `recipes()`
  - `ingredientOf()`
- `app/Models/ProductRecipe.php`
  - `product_id`
  - `ingredient_id`
  - `quantity`
  - `unit`

Related inventory surfaces:
- `resources/js/Pages/Inventory/UnitConversions/Index.jsx`
- `resources/js/Pages/Inventory/VarianceLogs/Index.jsx`

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 3. In Scope

Story 31.5 implementation may include planning and UI work for:

- Recipe/ingredient admin surface inventory and route/component ownership map.
- Current Product Edit recipe section review and gap lock.
- Recipe row clarity for ingredient name, SKU, type, quantity, and unit.
- Ingredient search/select UX hardening using existing product records.
- Empty states for products without recipe ingredients.
- Impact/usage visibility planning using existing relationships, such as where an
  ingredient is used, if already available from current models.
- Read/write affordance clarity for adding, editing, removing, and saving recipe
  rows.
- Helper copy explaining that recipe rows affect ingredient consumption but do
  not change pricing, tax, costing, or checkout rules.
- Validation/error feedback presentation using existing server responses.

---

## 4. Out of Scope

Not approved under Story 31.5:

- Recipe/BOM computation changes.
- Inventory deduction or posting changes.
- Costing, WAC, FEFO, valuation, or COGS changes.
- POS checkout or POS runtime changes.
- Tax or accounting behavior changes.
- New production, batch-build, commissary, or prep-workflow modules.
- Backend endpoint contract changes unless separately approved.
- Server-side validation rule changes unless separately approved.
- Subscription entitlement, RBAC, or middleware changes.
- Tenant or branch isolation model changes.
- Multi-level BOM, recursive recipe expansion, or recipe versioning engines.

---

## 5. Acceptance Boundaries

31.5 may modify:

- Frontend recipe/ingredient UI labels, helper text, empty states, layout, and
  interaction clarity.
- Existing recipe row presentation and read/write affordance copy.
- Existing Product Edit recipe section composition if behavior remains unchanged.
- Planning artifacts for future dedicated recipe management surfaces.

31.5 must not modify:

- Recipe/BOM calculation semantics.
- Inventory deduction/posting logic.
- Costing, WAC, FEFO, POS, tax, or accounting logic.
- Controller persistence behavior or endpoint contracts without a separate
  approved scope lock.
- Authorization, subscription, tenant isolation, or branch isolation rules.

---

## 6. RBAC and Feature-Gate Lock

Permission and feature-gate expectations remain mandatory and unchanged:

- `manage_products` required for product and recipe write pathways.
- `catalog.edit` required for recipe update interactions.
- `catalog.view` without edit permissions remains non-mutating.
- Existing tenant and branch isolation remains fail-closed.

No relaxation of middleware, RBAC, subscription gates, tenant isolation, or branch
isolation is approved.

---

## 7. Data Integrity and Behavior Expectations

Story 31.5 must preserve integrity and behavior accuracy:

- Recipe UI guidance must reflect existing backend outcomes.
- Recipe rows must remain explicit product-to-ingredient relationships.
- No silent mutation of unrelated product, pricing, inventory, tax, or branch
  data is approved.
- Ingredient usage/impact messaging must be sourced from existing relationships
  or clearly marked as planning-only.

---

## 8. Test Strategy Lock

Required validation baseline for Story 31.5 implementation:

- Frontend build passes (`npm run build`).
- Recipe management UI remains permission-gated.
- Product catalog write behavior and tenant isolation remain intact.
- Recipe update entry point remains behavior-preserving.
- Unit conversion and variance log surfaces remain unaffected where relevant.

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:

1. Baseline UX audit of the current Product Edit recipe section.
2. Recipe row clarity and ingredient search/select polish.
3. Empty-state, validation, and save-feedback hardening.
4. Ingredient usage/impact visibility planning or read-only UI if already
   derivable from existing relationships.
5. Regression validation with required guardrail suites.

---

## 10. Governance Lock

Story 31.5 is planning and scope-lock only.

No implementation beyond documentation is approved until explicit Story 31.5
implementation approval is received.
