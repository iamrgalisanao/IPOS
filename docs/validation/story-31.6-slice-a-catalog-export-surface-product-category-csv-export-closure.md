# Story 31.6 Slice A - Catalog Export Surface / Product & Category CSV Export Closure

**Epic:** 31 - Product Catalog and Inventory Admin UX Completion  
**Story:** 31.6 - Catalog Import/Export and Audit Hardening  
**Slice:** A - Catalog Export Surface / Product & Category CSV Export  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-22  
**Governance Ref:** G-068

---

## Closure Summary

Story 31.6 Slice A adds safe, read-only CSV export for products and product
categories through existing catalog admin list surfaces.

The implementation uses the existing `catalog.view` list boundary, preserves
tenant isolation, hardens CSV values against spreadsheet formula injection, adds
safe response headers and filenames, and records export audit events. It does
not add import upload workflows, bulk create/update behavior, pricing/tax
changes, inventory movement/deduction changes, recipe/BOM computation changes,
POS/runtime changes, subscription engine changes, accounting certification
claims, background processing, or tenant/branch isolation model changes.

---

## Completed Scope

- Added read-only product CSV export.
- Added read-only product category CSV export.
- Added export routes under existing catalog list permission boundaries:
  - `GET /admin/products/export/csv`
  - `GET /admin/product-categories/export/csv`
- Reused `manage_products` and `catalog.view` access expectations for export
  access.
- Added `CatalogCsvExportService` for product/category CSV serialization.
- Hardened formula-like CSV cell values that begin with `=`, `+`, `-`, or `@`.
- Added safe CSV response headers:
  - `Content-Type: text/csv; charset=UTF-8`
  - `Content-Disposition` attachment filenames
  - `X-Content-Type-Options: nosniff`
  - no-store cache headers
- Added deterministic export filenames with timestamps.
- Added export audit events for product and category exports.
- Added product and category list UI export actions.
- Extended route, catalog, and isolation guardrail tests.

---

## Validation Evidence

```bash
npm run build
```

- Result: passed

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php
```

- Result: 26 passed / 67 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductCatalogTest.php
```

- Result: 8 passed / 23 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductPricingTest.php
```

- Result: 6 passed / 20 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/CatalogInventoryIsolationTest.php
```

- Result: 8 passed / 41 assertions

---

## Governance Boundary

Story 31.6 Slice A is read-only catalog export work. It does not:

- add import upload workflows
- add bulk product/category creation or update
- alter product write-path persistence behavior
- alter pricing calculations
- alter tax behavior
- alter inventory deduction, posting, or movement behavior
- alter recipe/BOM computation
- alter POS checkout or runtime behavior
- alter subscription engine behavior
- make accounting certification claims
- add background processing architecture
- alter RBAC, middleware, subscription gates, tenant isolation, or branch
  isolation

---

## Files Touched

- `app/Http/Controllers/Admin/ProductController.php`
- `app/Http/Controllers/Admin/ProductCategoryController.php`
- `app/Services/Catalog/CatalogCsvExportService.php`
- `routes/web.php`
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/ProductCategories/Index.jsx`
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `docs/validation/story-31.6-slice-a-catalog-export-surface-product-category-csv-export-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`
- `.agents/rules/03-current-focus.md`

---

## Next Recommended Slice

Proceed to Story 31.6 Slice B only after explicit approval and a dedicated scope
lock. Import upload, bulk creation/update, and write-path behavior remain locked.
