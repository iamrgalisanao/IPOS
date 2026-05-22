# Story 31.4 — Inventory Overview and Stock Visibility Dashboard Closure

**Epic:** 31 — Product Catalog and Inventory Admin UX Completion  
**Story:** 31.4 — Inventory Overview and Stock Visibility Dashboard  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21  
**Governance Ref:** G-068

---

## Closure Summary

Story 31.4 is implemented and locally validated across Slices A-B.

The story introduces a read-only inventory overview dashboard using existing
inventory/product/branch/movement surfaces. Slice A established the dashboard
shell and navigation entry. Slice B populated read-only summary data, branch and
product visibility tables, negative-stock spotlight visibility, movement summary
counts, and read-only filters.

The implementation preserves all approved boundaries: no stock mutation controls,
no write endpoint additions, no inventory posting/deduction behavior changes, no
movement semantic changes, no WAC/FEFO/costing changes, no procurement automation
changes, no POS/tax/accounting behavior changes, no backend write-contract
changes, and no RBAC/subscription/tenant/branch isolation changes.

---

## Completed Slices

### Slice A — Read-Only Dashboard Shell

- Added `GET /inventory/dashboard` (`inventory.dashboard.index`) with existing
  inventory permission boundary.
- Added Inventory Overview sidebar navigation entry.
- Added dashboard shell, filter placeholders, stock visibility card placeholders,
  explanatory legends, and links to existing inventory surfaces.

Closure:
- `docs/validation/story-31.4-slice-a-inventory-dashboard-shell-closure.md`

### Slice B — Read-Only Inventory Summary Data

- Added read-only dashboard data controller action:
  `InventoryDashboardController@index`.
- Populated read-only summary widgets:
  tracked inventory items, low-stock count, negative-stock count.
- Added read-only branch-level stock visibility and product-level stock
  visibility tables.
- Added negative-stock spotlight and read-only movement summary counts.
- Added functional read-only filters:
  branch, product name/SKU, stock status, and movement date range.

Closure:
- `docs/validation/story-31.4-slice-b-read-only-inventory-summary-data-closure.md`

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

Story 31.4 is read-only inventory dashboard work. It does not:

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
- `resources/js/Layouts/AuthenticatedLayout.jsx`
- `resources/js/Pages/Inventory/Dashboard/Index.jsx`
- `docs/validation/story-31.4-slice-a-inventory-dashboard-shell-closure.md`
- `docs/validation/story-31.4-slice-b-read-only-inventory-summary-data-closure.md`
- `docs/validation/story-31.4-inventory-overview-stock-visibility-dashboard-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`
- `.agents/rules/03-current-focus.md`

---

## Final Governance Decision

Story 31.4 — Inventory Overview and Stock Visibility Dashboard is accepted for
closure.

Recommended next step: proceed to Story 31.5 — Recipe / Ingredient Admin
Management UI as a planning lock first.
