# Story 31.3 Scope Lock - Branch Pricing and Availability Management UI

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.3 - Branch Pricing and Availability Management UI
Governance Ref: G-068
Predecessor: Story 31.2 - Product Create/Edit UX Hardening (Closed)

---

## 0. Story Intent

Story 31.3 defines approved UI planning boundaries for branch-level pricing and
availability management surfaces in Product Admin.

This story authorizes planning only.

---

## 1. Goal

Improve branch pricing and branch availability management usability in admin flows
through frontend UI and interaction hardening, without changing pricing engines,
inventory deduction semantics, recipe/BOM computation, tax/POS behavior,
controller persistence rules, or authorization/isolation behavior.

---

## 2. Current Surface Baseline

Targeted surfaces for Story 31.3 planning:

Pages (frontend):
- `resources/js/Pages/Admin/Products/Edit.jsx`
- `resources/js/Pages/Admin/Products/Index.jsx` (if needed for branch-status visibility)

Routes (existing surfaces only; behavior-preserving):
- POST `/admin/products/{product}/branch-pricing`
- DELETE `/admin/products/{product}/branch-pricing/{branchPrice}`
- Existing product read/edit routes already used by current admin UI

Controller (read-only reference; no changes approved in planning lock):
- `app/Http/Controllers/Admin/ProductController.php`

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Story 31.3 implementation may include UI-only improvements such as:

- Clearer branch pricing section framing and operator guidance.
- Better branch pricing row readability (labels, hierarchy, status chips, helper copy).
- Improved branch availability affordance clarity in existing product admin surfaces.
- Empty states and no-data states for branch pricing/availability contexts.
- Interaction consistency for add/edit/remove branch override entry points.
- Confirmation and feedback copy polish for branch-level override actions.
- Visual grouping that separates global product values from branch-specific overrides.
- Frontend-only safety cues that reduce operator misinterpretation of branch scope.

---

## 4. Out of Scope

Not approved under Story 31.3:

- Branch pricing engine or pricing calculation changes.
- Availability computation model changes outside existing persisted semantics.
- Inventory deduction or movement posting logic changes.
- Tax logic, POS runtime, or checkout behavior changes.
- Recipe/BOM computation or consumption semantics changes.
- Server-side validation rule changes.
- ProductController persistence logic changes.
- New backend APIs, endpoint contracts, or mutation workflows.
- Subscription feature-gate/RBAC/middleware behavior changes.
- Tenant or branch isolation model changes.
- New dedicated standalone branch-pricing module/workspace.

---

## 5. Acceptance Boundaries

31.3 may modify:

- Frontend JSX, component composition, labels, and helper/feedback copy in approved pages.
- Existing modal/list/table presentation for branch pricing and availability controls.
- UI state handling for clearer interaction outcomes using existing backend responses.

31.3 must not modify:

- Pricing/tax/inventory/recipe computational semantics.
- Controller persistence logic, request lifecycle rules, or validation contracts.
- Authorization gates, middleware enforcement, subscription constraints, or isolation guarantees.

---

## 6. RBAC and Feature-Gate Lock

Permission and feature-gate expectations remain mandatory and unchanged:

- `manage_products` required for product write pathways.
- `catalog.edit` required for branch-level pricing/availability write interactions.
- `catalog.view` without edit permissions remains non-mutating.
- Existing tenant and branch isolation remains fail-closed.

No relaxation of RBAC or middleware checks is approved.

---

## 7. Audit and Data Integrity Expectations

Story 31.3 must preserve audit and integrity posture:

- Branch-specific overrides remain explicit and traceable in UI messaging.
- No silent mutation of unrelated global product values.
- User-facing success/error states must reflect actual backend outcomes.
- UI labels must accurately distinguish global defaults versus branch overrides.

---

## 8. Test Strategy Lock

Required validation baseline for Story 31.3 implementation:

- Frontend build passes (`npm run build`).
- Branch pricing/availability UI interactions remain permission-gated.
- No regressions in product catalog access/write behavior and isolation rules.
- Existing branch override actions remain functional with behavior-preserving semantics.

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:
1. Baseline UX audit of existing branch pricing/availability controls.
2. UI copy and hierarchy pass for branch/global distinction.
3. Empty-state and action feedback polish.
4. Regression validation using required baseline.

---

## 10. Governance Lock

Story 31.3 is planning and scope-lock only.

No implementation beyond documentation is approved until explicit Story 31.3
implementation approval is received.
