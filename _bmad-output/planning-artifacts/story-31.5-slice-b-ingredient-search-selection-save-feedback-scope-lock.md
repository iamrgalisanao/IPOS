# Story 31.5 Slice B Scope Lock - Ingredient Search / Selection and Save Feedback Hardening

Status: Planning / Scope Locked
Date: 2026-05-22
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.5 - Recipe / Ingredient Admin Management UI
Slice: B - Ingredient Search / Selection and Save Feedback Hardening
Governance Ref: G-068
Predecessor: Story 31.5 Slice A - Implemented & Locally Validated

---

## 0. Slice Intent

Story 31.5 Slice B defines approved planning boundaries for improving ingredient
search, ingredient selection, row editing feedback, and recipe save feedback
inside the existing Product Edit recipe workspace.

This slice authorizes planning only. Implementation requires explicit approval.

---

## 1. Goal

Improve ingredient search, selection, row editing, and save feedback inside the
existing Product Edit recipe workspace without changing recipe persistence
semantics, recipe/BOM computation, inventory deduction behavior, costing/WAC/FEFO
behavior, POS checkout behavior, tax/accounting behavior, backend contracts,
subscription gates, RBAC checks, or tenant/branch isolation.

---

## 2. Current Surface Baseline

Targeted frontend surface:
- `resources/js/Pages/Admin/Products/Edit.jsx`
  - ingredient search input
  - ingredient result list
  - selected ingredient rows
  - quantity and unit row editing
  - recipe save action

Existing route and controller behavior:
- POST `/admin/products/{product}/recipe`
- route name: `admin.products.recipe.update`
- `app/Http/Controllers/Admin/ProductController.php@updateRecipe`

Existing models:
- `app/Models/Product.php`
- `app/Models/ProductRecipe.php`

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 3. In Scope

Slice B implementation may include frontend-only improvements for:

- Clearer ingredient search and selection behavior.
- Search helper copy and behavior-accurate result messaging.
- No-result messaging for unmatched searches.
- Empty-selection messaging when no ingredients are selected.
- Duplicate ingredient guidance if already handled by current UI/state.
- Row-level validation/error display using existing Inertia/server error
  responses.
- Save success/failure feedback using existing request outcomes.
- Processing-state and disabled-state clarity for recipe save actions.
- Preserve current `updateRecipe` endpoint and request payload shape.

---

## 4. Out of Scope

Not approved under Slice B:

- Recipe/BOM computation changes.
- Inventory deduction or posting changes.
- Costing, WAC, FEFO, valuation, or COGS changes.
- POS checkout or POS runtime changes.
- Tax or accounting behavior changes.
- Backend endpoint contract changes.
- Controller persistence semantics changes.
- Server-side or model-level validation rule changes.
- New recipe management module or dedicated workspace.
- Production, batch-build, commissary, or prep-workflow modules.
- Subscription entitlement, RBAC, or middleware changes.
- Tenant or branch isolation model changes.

---

## 5. Acceptance Boundaries

Slice B may modify:

- Frontend JSX in `Edit.jsx` for recipe search, selected-row feedback, error
  display, and save feedback.
- Existing helper copy, labels, empty states, and processing/success/failure
  affordances.
- Inertia form options that improve UX continuity, such as `preserveScroll`,
  without changing request semantics.

Slice B must not modify:

- `ProductController@updateRecipe` behavior.
- Recipe validation rule definitions.
- ProductRecipe persistence semantics.
- Inventory deduction/posting logic.
- Costing, WAC, FEFO, POS, tax, or accounting behavior.
- RBAC, subscription gates, tenant isolation, or branch isolation.

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

- Recipe UI feedback must reflect actual existing request outcomes.
- Duplicate ingredient guidance must not introduce new client-side validation
  rules that bypass or supplement server rules.
- Row-level errors must be sourced from existing Inertia/server error responses.
- No silent mutation of unrelated product, pricing, inventory, tax, branch, or
  recipe data is approved.

---

## 8. Test Strategy Lock

Required validation for Slice B implementation:

- Frontend build passes (`npm run build`).
- Ingredient search/select UI renders without breaking Product Edit.
- Recipe save feedback reflects success/failure outcomes.
- Product catalog write behavior and tenant isolation remain intact.
- Recipe update route remains behavior-preserving.
- Unit conversion and variance log surfaces remain unaffected where relevant.

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 9. Governance Lock

Story 31.5 Slice B is planning and scope-lock only.

No implementation beyond this document is approved until explicit Slice B
implementation approval is received.
