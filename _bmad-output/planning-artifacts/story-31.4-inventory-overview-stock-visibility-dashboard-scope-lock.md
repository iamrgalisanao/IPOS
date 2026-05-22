# Story 31.4 Scope Lock - Inventory Overview and Stock Visibility Dashboard

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.4 - Inventory Overview and Stock Visibility Dashboard
Governance Ref: G-068
Predecessor: Story 31.3 - Branch Pricing and Availability Management UI (Closed)

---

## 0. Story Intent

Story 31.4 defines approved planning boundaries for a Back Office inventory
visibility dashboard using existing inventory, product, branch, and movement
data surfaces.

This story authorizes planning only.

---

## 1. Goal

Create a read-first inventory visibility dashboard that improves branch-level
stock awareness, low/negative stock detection, and movement visibility, without
changing inventory posting semantics, deduction logic, pricing/tax behavior,
checkout behavior, or accounting-sensitive computational rules.

---

## 2. Current Surface Baseline

Known existing inventory-adjacent surfaces in the current codebase:

Pages (frontend):
- `resources/js/Pages/Inventory/Stocktake/Index.jsx`
- `resources/js/Pages/Inventory/Stocktake/Create.jsx`
- `resources/js/Pages/Inventory/Stocktake/Show.jsx`
- `resources/js/Pages/Inventory/Stocktake/Review.jsx`
- `resources/js/Pages/Inventory/Stocktake/Summary.jsx`
- `resources/js/Pages/Inventory/VarianceLogs/Index.jsx`
- `resources/js/Pages/Inventory/UnitConversions/Index.jsx`

Routes (existing):
- GET `/inventory/movements` (JSON from existing controller)
- Existing stocktake read/create/review/post routes under `/inventory/stocktakes`

Controllers (read-only reference for planning):
- `app/Http/Controllers/Inventory/InventoryMovementController.php`
- `app/Http/Controllers/Inventory/StocktakeController.php`
- `app/Http/Controllers/Inventory/StocktakeReportController.php`

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Story 31.4 implementation may include planning and UI-only work for:

- Inventory overview dashboard information architecture and layout planning.
- Product stock visibility by branch (read-only display first).
- Low-stock and negative-stock visibility indicators.
- Inventory movement summary links and read-path navigation cues.
- Stock visibility filters (branch, product, status, and date-range where
  available from existing read surfaces).
- Read-only first slice with no mutation workflows introduced.
- Copy, labels, legends, and empty states that improve operator comprehension.

---

## 4. Out of Scope

Not approved under Story 31.4:

- Inventory deduction logic changes.
- Inventory posting pipeline changes.
- WAC, FEFO, costing, or valuation model changes.
- Procurement automation changes.
- POS checkout or runtime behavior changes.
- Tax or accounting behavior changes.
- Stock mutation workflows beyond existing approved pathways.
- Server-side validation contract changes for inventory posting workflows.
- New backend APIs or endpoint contract changes unless separately approved.

---

## 5. Acceptance Boundaries

31.4 may modify:

- Frontend dashboard/page composition for inventory visibility and filtering.
- Read-only query/view interactions against existing data surfaces.
- Labels, helper copy, legends, and empty-state messaging.

31.4 must not modify:

- Inventory movement semantics or write-path business rules.
- Inventory deduction/posting logic in services/controllers.
- Costing/computation engines (including WAC/FEFO behavior).
- POS, tax, accounting, and checkout behavior.

---

## 6. RBAC and Feature-Gate Lock

Permission and feature-gate behavior remain mandatory and unchanged:

- Existing inventory view permissions remain enforced.
- No relaxation of middleware, subscription gates, RBAC, tenant isolation, or
  branch isolation is approved.
- Read-only dashboard visibility must remain permission-gated.

---

## 7. Data Integrity and Audit Expectations

Story 31.4 must preserve integrity and audit posture:

- Dashboard data must reflect existing authoritative read models only.
- No inferred values may be presented as posted stock facts without source
  clarity.
- Low/negative stock indicators must be behavior-accurate to current data.
- No hidden write side effects from dashboard interactions.

---

## 8. Test Strategy Lock

Required validation baseline for Story 31.4 implementation:

- Frontend build passes (`npm run build`).
- Read-only stock visibility surfaces render without breaking existing admin
  workflows.
- Permission-gated access remains intact.
- No regressions in product catalog, pricing guardrails, and isolation behavior.

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:
1. Dashboard IA and widget hierarchy for read-only stock visibility.
2. Branch/product filter model and empty-state handling.
3. Low/negative stock highlighting and movement-summary linking.
4. Regression validation with required baseline.

---

## 10. Governance Lock

Story 31.4 is planning and scope-lock only.

No implementation beyond documentation is approved until explicit Story 31.4
implementation approval is received.
