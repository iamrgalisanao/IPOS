# Story 31.4 Slice B — Read-Only Inventory Summary Data Closure

**Epic:** 31 — Product Catalog and Inventory Admin UX Completion  
**Story:** 31.4 — Inventory Overview and Stock Visibility Dashboard  
**Slice:** B — Read-Only Inventory Summary Data  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21  
**Governance Ref:** G-068

---

## Closure Summary

Story 31.4 Slice B populates the Inventory Overview dashboard with read-only
summary data using existing inventory models, route permissions, and page surfaces.

The implementation keeps the existing `/inventory/dashboard` route and adds a
dedicated read-only controller action to compute dashboard data. It does not add
inventory mutation controls, write endpoints, posting/deduction behavior changes,
movement semantic changes, WAC/FEFO/costing changes, procurement automation
changes, POS/tax/accounting changes, RBAC changes, subscription changes, or
tenant/branch isolation changes.

---

## Completed Scope

- Added `InventoryDashboardController@index` as a read-only dashboard data source.
- Preserved the existing dashboard route:
  - `GET /inventory/dashboard`
  - route name: `inventory.dashboard.index`
  - permission boundary: `view_branch_inventory|inventory.stocktake.view`
- Added read-only summary widgets:
  - tracked inventory items
  - low-stock count
  - negative-stock count
- Added branch-level stock visibility table.
- Added product-level stock visibility table.
- Added negative-stock spotlight list.
- Added movement summary counts using the existing inventory movement read surface.
- Added functional read-only filters:
  - branch
  - product name/SKU
  - stock status
  - movement date range
- Preserved links to existing inventory surfaces.

---

## Validation Evidence

```bash
npm run build
```

- Result: passed

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php
```

- Result: 25 passed / 63 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductCatalogTest.php
```

- Result: 7 passed / 16 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductPricingTest.php
```

- Result: 6 passed / 20 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/CatalogInventoryIsolationTest.php
```

- Result: 7 passed / 37 assertions

---

## Governance Boundary

Story 31.4 Slice B is read-only dashboard data work. It does not:

- add stock mutation controls
- add inventory write endpoints
- change inventory deduction or posting behavior
- change inventory movement semantics
- change WAC/FEFO/costing behavior
- change procurement automation
- change POS, tax, or accounting behavior
- change backend write contracts
- change RBAC or middleware checks
- change subscription feature gates
- change tenant or branch isolation

---

## Files Touched

- `app/Http/Controllers/Inventory/InventoryDashboardController.php`
- `routes/web.php`
- `resources/js/Pages/Inventory/Dashboard/Index.jsx`
- `docs/validation/story-31.4-slice-b-read-only-inventory-summary-data-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`
- `.agents/rules/03-current-focus.md`

---

## Next Recommended Slice

Proceed to Story 31.4 Slice C — Inventory Dashboard UX Refinement / Closure only
after explicit approval and a dedicated scope lock, or close Story 31.4 if no
additional inventory dashboard slice is needed.
