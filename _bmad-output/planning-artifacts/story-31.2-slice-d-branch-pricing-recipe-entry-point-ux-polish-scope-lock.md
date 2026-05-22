# Story 31.2 Slice D Scope Lock — Branch Pricing and Recipe Entry-Point UX Polish

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 — Product Catalog and Inventory Admin UX Completion
Story: 31.2 — Product Create/Edit UX Hardening
Slice: D — Branch Pricing and Recipe Entry-Point UX Polish
Governance Ref: G-068
Predecessor: Story 31.2 Slice C — Implemented, Locally Validated, and Governance-Recorded

---

## 0. Slice Intent

Story 31.2 Slice D defines approved UX polish boundaries for Branch Pricing and
Recipe/Ingredients entry points on Product Edit.

It authorizes frontend-only clarity improvements to section framing, labels,
helper text, empty states, and affordance clarity, without changing pricing
calculations, recipe/BOM computation, inventory deduction behavior, POS/runtime
behavior, tax behavior, validation rules, persistence logic, subscription gates,
or RBAC checks.

---

## 1. Goal

Polish Branch Pricing and Recipe/Ingredients entry points inside the Product Edit
page to improve discoverability and operator confidence, while preserving existing
mutation semantics and backend logic.

---

## 2. Current Surface Baseline

Primary surface targeted by Slice D (frontend-only):

Pages:
- `resources/js/Pages/Admin/Products/Edit.jsx`

Related supporting surface (read-only or minor label parity only if needed):
- `resources/js/Pages/Admin/Products/Index.jsx`

Entry-point routes (behavior-preserving):
- POST `/admin/products/{product}/branch-pricing` (entry point only)
- POST `/admin/products/{product}/recipe` (entry point only)

Controller (read-only reference; no changes approved):
- `app/Http/Controllers/Admin/ProductController.php`

Test guardrails:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Slice D implementation may include:

- **Clearer section headers** for Branch Pricing and Recipe/Ingredients blocks.
- **Helper text** that explains what each section controls and does not control.
- **Empty states** when no branch pricing rows or no recipe ingredients exist.
- **Better action labels** for entry-point controls to reduce ambiguity.
- **Visual separation** between product metadata, branch pricing, and recipe
  sections for scanning clarity.
- **Read/write affordance clarity only**, including clearer messaging around
  save/update intent.

---

## 4. Out of Scope

Not approved under Slice D:

- Branch pricing engine changes.
- Recipe/BOM computation changes.
- Inventory deduction or movement logic changes.
- Tax, POS, or runtime behavior changes.
- Validation-rule changes (server-side or model-level).
- Product persistence logic changes in controller/service layers.
- New dedicated branch-pricing workspace/module.
- New dedicated recipe-management module.
- Subscription entitlement or RBAC/middleware changes.
- Tenant/branch isolation model changes.

---

## 5. Acceptance Boundaries

Slice D may modify:

- Frontend JSX and copy in Product Edit page sections related to Branch Pricing
  and Recipe/Ingredients entry points.
- Existing UI labels, helper text, empty-state content, and section grouping
  presentation.

Slice D must not modify:

- Any pricing/tax/inventory/recipe computation or posting semantics.
- Backend controller persistence logic or request validation behavior.
- Authorization, middleware, subscription gates, tenant isolation, or branch
  isolation rules.

---

## 6. RBAC and Feature-Gate Lock

No relaxation of existing permission or feature-gate constraints is approved.

The following remain mandatory and unchanged:
- `manage_products` required for write pathways.
- `catalog.edit` required for edit and write operations.
- `catalog.view` without `catalog.edit` remains view-only.
- Existing tenant and branch isolation remain fail-closed.

---

## 7. Data Integrity and Behavior Guarantees

- No mutation-surface expansion beyond existing entry points.
- No change to pricing or recipe data semantics.
- UI guidance must not imply behavior that differs from actual backend outcomes.
- Empty-state and helper messaging must remain behavior-accurate.

---

## 8. Test Strategy Lock

Required validation for Slice D implementation:

- Branch Pricing and Recipe/Ingredients sections have clearer headers and helper
  text, with behavior-accurate wording.
- Empty states are visible and clear when section data is absent.
- Action labels are clearer and remain semantically correct.
- Existing write/read affordance behavior remains unchanged.
- RBAC and feature-gate behavior remains unchanged.
- Tenant and branch isolation behavior remains unchanged.
- Frontend build passes (`npm run build`).

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Governance Lock

Story 31.2 Slice D is planning and scope-lock only.

No Slice D implementation is approved until explicit implementation approval is
received for this slice.
