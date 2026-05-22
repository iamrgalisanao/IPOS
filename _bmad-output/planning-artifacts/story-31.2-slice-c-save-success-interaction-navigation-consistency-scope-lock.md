# Story 31.2 Slice C Scope Lock — Save/Success Interaction and Navigation Consistency

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 — Product Catalog and Inventory Admin UX Completion
Story: 31.2 — Product Create/Edit UX Hardening
Slice: C — Save/Success Interaction and Navigation Consistency
Governance Ref: G-068
Predecessor: Story 31.2 Slice B — Implemented, Locally Validated, and Governance-Recorded

---

## 0. Slice Intent

Story 31.2 Slice C defines the approved boundaries for post-save UX behavior and
navigation consistency in Product Create/Edit admin flows.

It authorizes frontend-only interaction and messaging improvements after save
actions without changing persistence behavior, validation rules, pricing logic,
tax logic, inventory behavior, recipe/BOM behavior, POS runtime behavior,
subscription gates, or RBAC checks.

---

## 1. Goal

Improve post-save user experience and navigation consistency after product
create/update while preserving existing data mutation semantics and backend
controller behavior.

---

## 2. Current Surface Baseline

Surfaces targeted by Slice C (frontend-only):

Pages:
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`
- `resources/js/Pages/Admin/Products/Index.jsx` (navigation destination parity only)

Routes (behavior-preserving):
- GET `/admin/products/create`
- POST `/admin/products`
- GET `/admin/products/{product}/edit`
- PUT `/admin/products/{product}`
- GET `/admin/products`

Controller (read-only reference; no changes approved):
- `app/Http/Controllers/Admin/ProductController.php`

Test guardrails:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Slice C implementation may include:

- **Clearer success acknowledgment** after create/update save outcomes using
  existing Inertia response signals and existing redirect/result behavior.
- **Consistent product navigation affordances** across Create/Edit surfaces,
  including harmonized labels such as "Back to Products" / "View Product List"
  when functionally equivalent.
- **Save button state clarity** (idle, processing, and post-save affordance)
  as long as it does not alter mutation timing or request semantics.
- **Post-save interaction consistency** between Create and Edit UX flows while
  preserving current redirect behavior unless it is already inconsistent.
- **Frontend-only microcopy and interaction polish** related to save/success
  communication and route-to-list guidance.

---

## 4. Out of Scope

Not approved under Slice C:

- Changing `ProductController` store/update persistence logic.
- Changing server-side validation rules or model-level validation behavior.
- Changing product mutation semantics or transactional boundaries.
- Changing pricing calculations, tax calculations, inventory deduction/movement,
  or recipe/BOM behavior.
- Changing POS runtime or checkout behavior.
- Changing subscription feature-gate rules or RBAC/middleware authorization.
- Introducing new backend APIs, new write endpoints, or import/export flows.
- Reworking form structure outside save/success interaction and navigation
  consistency concerns.

---

## 5. Acceptance Boundaries

Slice C may modify:

- Frontend JSX in Create/Edit/Index pages for success messaging and navigation
  consistency.
- Existing Inertia submit UI state handling for improved user guidance.
- Non-breaking copy, button labels, and post-save affordance presentation.

Slice C must not modify:

- Backend controller persistence logic.
- Validation rule definitions.
- Pricing, tax, inventory, recipe/BOM, POS, subscription, or RBAC logic.
- Tenant/branch isolation semantics.

---

## 6. RBAC and Feature-Gate Lock

No relaxation of middleware, permissions, feature gates, tenant isolation, or
branch isolation is approved.

The following remain mandatory and unchanged:
- `manage_products` required for write pathways.
- `catalog.edit` required for create/edit access and writes.
- `catalog.view` without `catalog.edit` remains view-only.
- Existing tenant and branch isolation remain fail-closed.

---

## 7. Data Integrity and Behavior Guarantees

- No mutation logic changes are introduced.
- Save success/failure UX must reflect actual persistence outcomes.
- Redirect behavior remains unchanged unless inconsistency correction is required
  for parity between Create and Edit flows.
- No client-side behavior may mask backend validation or persistence outcomes.

---

## 8. Test Strategy Lock

Required validation for Slice C implementation:

- Save success acknowledgment is visible and context-appropriate on create/update
  outcomes.
- Navigation actions from Create/Edit to product list are consistent and clear.
- Save button processing and post-save affordance states are consistent and do
  not permit duplicate-submission regressions.
- RBAC and feature-gate behavior remains unchanged.
- Tenant/branch isolation remains unchanged.
- Frontend build passes (`npm run build`).

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Governance Lock

Story 31.2 Slice C is planning and scope-lock only.

No Slice C implementation is approved until explicit implementation approval is
received for this slice.

Slice D (Branch Pricing and Recipe Entry-Point UX Polish) remains locked pending
Slice C closure.
