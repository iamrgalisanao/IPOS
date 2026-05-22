# Story 31.6 Slice B - Import Template and Validation Strategy Closure

**Epic:** 31 - Product Catalog and Inventory Admin UX Completion  
**Story:** 31.6 - Catalog Import/Export and Audit Hardening  
**Slice:** B - Import Template and Validation Strategy  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-22  
**Governance Ref:** G-068

---

## Closure Summary

Story 31.6 Slice B adds catalog import template downloads and validation-only
preview flows for products and product categories.

The implementation is non-mutating. It defines safe import template structure,
validates uploaded CSV files, reports row-level preview failures, and logs
template/preview activity without creating, updating, or deleting catalog records.
Actual import writes, bulk create/update workflows, background jobs, pricing/tax
logic changes, inventory movement/deduction changes, recipe/BOM computation
changes, POS/runtime changes, accounting certification claims, and tenant/branch
isolation changes remain locked.

---

## Completed Scope

- Added product import template CSV download.
- Added product category import template CSV download.
- Added validation-only product import preview.
- Added validation-only product category import preview.
- Added `CatalogImportPreviewService` for template generation and preview
  validation.
- Added required/optional column handling for products and categories.
- Added duplicate detection for uploaded rows and existing tenant catalog values.
- Added SKU, barcode, category, and tax reference validation where applicable.
- Added row-level failure reporting and preview summaries.
- Added audit logging for template downloads and preview attempts.
- Added import-preview controls and preview result displays to existing product
  and category list pages.
- Preserved Slice A export behavior and boundaries.

---

## Validation Evidence

```bash
npm run build
```

- Result: passed

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php
```

- Result: 27 passed / 71 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductCatalogTest.php
```

- Result: 10 passed / 42 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ProductPricingTest.php
```

- Result: 6 passed / 20 assertions

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/CatalogInventoryIsolationTest.php
```

- Result: 9 passed / 43 assertions

---

## Governance Boundary

Story 31.6 Slice B is template and validation-preview work only. It does not:

- create or update products
- create or update product categories
- add actual import write workflows
- add bulk create/update behavior
- add background import jobs
- alter product write-path persistence behavior
- alter pricing calculations
- alter tax behavior
- alter inventory deduction, posting, or movement behavior
- alter recipe/BOM computation
- alter POS checkout or runtime behavior
- alter subscription engine behavior
- make accounting certification claims
- alter RBAC, middleware, subscription gates, tenant isolation, or branch
  isolation

---

## Files Touched

- `app/Http/Controllers/Admin/ProductController.php`
- `app/Http/Controllers/Admin/ProductCategoryController.php`
- `app/Services/Catalog/CatalogImportPreviewService.php`
- `routes/web.php`
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/ProductCategories/Index.jsx`
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `docs/validation/story-31.6-slice-b-import-template-and-validation-strategy-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`
- `.agents/rules/03-current-focus.md`

---

## Next Recommended Slice

Proceed to Story 31.6 Slice C only after explicit approval and a dedicated scope
lock. Actual import writes, bulk creation/update, background processing, and
write-path behavior remain locked.
