# Story 31.4 Slice B Scope Lock - Read-Only Inventory Summary Data

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.4 - Inventory Overview and Stock Visibility Dashboard
Slice: B - Read-Only Inventory Summary Data
Governance Ref: G-068
Predecessor: Story 31.4 Slice A - Implemented, Locally Validated, and Governance-Recorded

---

## 0. Slice Intent

Story 31.4 Slice B defines approved boundaries to populate the inventory
dashboard with read-only summary data using existing inventory/product/branch
surfaces and existing route/controller patterns.

It authorizes read-only data visibility work only and does not authorize stock
mutation workflows, posting logic changes, deduction rule changes, costing model
changes, or any backend contract changes beyond existing approved read behavior.

---

## 1. Goal

Populate the inventory dashboard with read-only summary data to improve operator
visibility of branch and product stock states while preserving all existing
inventory mutation semantics and accounting-sensitive behavior.

---

## 2. Current Surface Baseline

Primary dashboard surface introduced in Slice A:
- `resources/js/Pages/Inventory/Dashboard/Index.jsx`
- route: `inventory.dashboard.index` (`GET /inventory/dashboard`)

Existing inventory read surfaces:
- `GET /inventory/movements` (existing read endpoint)
- Stocktake, variance logs, and unit conversion read screens/routes already in use

Existing controller/services (read-only reference):
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

Slice B implementation may include:

- Stock summary counts (read-only).
- Low-stock count visibility.
- Negative-stock count visibility.
- Branch-level stock visibility (read-only drilldown/listing).
- Product-level stock visibility (read-only drilldown/listing).
- Movement summary links and count summaries where already available from
  existing read surfaces.
- Filter behavior using existing read surfaces and existing permission boundaries.
- Dashboard widget population and read-only state/empty-state messaging.

---

## 4. Out of Scope

Not approved under Slice B:

- Stock mutation workflows (manual or automated).
- Inventory posting pipeline changes.
- Inventory deduction logic changes.
- Inventory movement semantics changes.
- WAC/FEFO/costing/valuation behavior changes.
- Procurement automation changes.
- POS checkout/runtime behavior changes.
- Tax/accounting behavior changes.
- New write endpoints.
- Backend API contract changes unless separately approved.
- RBAC/subscription gate changes.
- Tenant or branch isolation model changes.

---

## 5. Acceptance Boundaries

Slice B may modify:

- Dashboard read-only data rendering, widget composition, filter presentation, and
  read-path navigation links.
- Existing read queries/wiring limited to approved existing data surfaces.
- Copy, labels, legends, and explanatory guidance for read-only metrics.

Slice B must not modify:

- Inventory write-path business rules or posting semantics.
- Deduction behavior, movement semantics, or valuation model behavior.
- Controller persistence logic for write operations.
- POS/tax/accounting side effects.

---

## 6. RBAC and Feature-Gate Lock

No permission relaxation is approved.

The following remain mandatory and unchanged:
- Existing inventory view permissions remain enforced.
- Read-only dashboard data remains permission-gated.
- Subscription gates, RBAC checks, tenant isolation, and branch isolation remain
  fail-closed.

---

## 7. Data Integrity and Audit Expectations

- Read-only summaries must be traceable to existing authoritative data surfaces.
- No inferred or synthetic values may be presented as posted stock facts without
  clear labeling.
- Low/negative stock indicators must match existing source behavior.
- No hidden writes, side effects, or background mutation actions are permitted.

---

## 8. Test Strategy Lock

Required validation for Slice B implementation:

- Frontend build passes (`npm run build`).
- Dashboard read-only summary widgets render and filter without write side
  effects.
- Permission-gated behavior remains intact.
- No regressions in guardrail suites for product/pricing/isolation boundaries.

Required suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Governance Lock

Story 31.4 Slice B is planning and scope-lock only.

No Slice B implementation is approved until explicit implementation approval is
received for this slice.
