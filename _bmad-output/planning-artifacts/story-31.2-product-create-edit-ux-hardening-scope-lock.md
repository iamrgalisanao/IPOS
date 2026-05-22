# Story 31.2 Scope Lock - Product Create/Edit UX Hardening

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Governance Ref: G-068

---

## 0. Story Intent

Story 31.2 defines the approved UX hardening boundaries for Product Create/Edit admin flows and locks implementation expectations before code changes.

This story authorizes planning only.

---

## 1. Goal

Improve and harden the product create/edit admin experience without changing POS runtime behavior, checkout behavior, tax engine behavior, inventory deduction logic, or pricing/recipe computation engines.

---

## 2. Current Surface Baseline

Current implementation surfaces targeted by Story 31.2:

Routes:
- GET `/admin/products/create`
- POST `/admin/products`
- GET `/admin/products/{product}/edit`
- PUT `/admin/products/{product}`
- POST `/admin/products/{product}/branch-pricing` (entry point only; no pricing engine changes)
- POST `/admin/products/{product}/recipe` (entry point only; no recipe engine changes)

Controllers:
- `app/Http/Controllers/Admin/ProductController.php`

Pages:
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`
- Supporting navigation/list entry: `resources/js/Pages/Admin/Products/Index.jsx`

Related evidence and guardrails:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Story 31.2 implementation may include UX hardening for:
- Product create/edit form review and interaction consistency.
- Validation clarity and field grouping.
- Required field UX and inline guidance.
- Tax category selection clarity and fail-safe messaging.
- Product category selection clarity.
- SKU/barcode/name handling UX hardening.
- Status (`active`/`inactive`) visibility and intent clarity.
- Branch pricing entry points if already linked in edit UX.
- Recipe/ingredient entry points if already linked in edit UX.
- Error display consistency and save feedback behavior.
- RBAC and feature-gate behavior preservation for:
  - `manage_products`
  - `catalog.view`
  - `catalog.edit`

---

## 4. Out of Scope

Not approved under Story 31.2:
- POS checkout or POS runtime behavior changes.
- Inventory deduction logic changes.
- Tax computation or tax posting behavior changes.
- Branch pricing engine logic changes.
- Recipe/BOM engine logic changes.
- Import/export implementation.
- Bulk product creation/import.
- Subscription engine changes.
- Tenant or branch isolation model redesign.

---

## 5. Acceptance Boundaries for 31.2 Implementation

31.2 may modify:
- Frontend create/edit form layout, grouping, copy, and interaction behavior.
- Validation presentation and feedback affordances.
- Non-breaking controller response and redirect messaging where needed for UX clarity.

31.2 must not modify:
- Core pricing calculations.
- Core tax calculations.
- Core inventory deduction and movement posting logic.
- Recipe quantity/consumption engine semantics.
- Subscription feature entitlement rules.

---

## 6. RBAC and Feature-Gate Lock

Permission and feature-gate expectations are mandatory:
- Users without `manage_products` are denied create/edit write pathways.
- Users without `catalog.edit` entitlement are denied create/edit form and write operations.
- Users with `catalog.view` but without `catalog.edit` remain view-only.
- Existing tenant and branch isolation boundaries remain fail-closed.

No relaxation of existing middleware or authorization checks is approved in Story 31.2.

---

## 7. Audit and Data Integrity Expectations

31.2 must preserve data integrity and audit posture:
- Product create/update persistence remains validated server-side.
- Existing SKU/barcode uniqueness and tenant isolation constraints remain enforced.
- No silent mutation of unrelated product, pricing, inventory, or recipe records.
- User-facing save/error feedback must accurately reflect persistence outcomes.
- No expansion of mutation surface outside approved create/edit UX scope.

---

## 8. Test Strategy Lock

Required validation for Story 31.2 implementation:
- Authorized tenant admin with permission can access create/edit forms.
- User without `manage_products` is denied.
- Tenant without `catalog.edit` is denied from create/edit forms and writes.
- Validation errors are clearly displayed and actionable.
- Product create succeeds with valid payload.
- Product update succeeds with valid payload.
- Tenant isolation is preserved in reads/writes.
- No POS checkout or inventory deduction behavior regressions are introduced.
- Frontend build passes (`npm run build`).

Recommended target suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Suggested Delivery Slices (Post-Approval)

Implementation sequencing recommendation after Story 31.2 planning approval:
1. Slice A: Field grouping and required-field clarity.
2. Slice B: Validation/error feedback hardening.
3. Slice C: Save/success interaction and navigation consistency.
4. Slice D: Branch pricing and recipe entry-point UX polish (entry points only).

Each slice should be UI-safe and regression-checked.

---

## 10. Governance Lock

Story 31.2 is planning and scope-lock only.
No implementation beyond documentation is approved by Story 31.2.
