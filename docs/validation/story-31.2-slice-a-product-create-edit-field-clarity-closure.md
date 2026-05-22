# Story 31.2 Slice A — Product Create/Edit Field Clarity Closure

**Epic:** 31 — Product Catalog and Inventory Admin UX Completion  
**Story:** 31.2 — Product Create/Edit UX Hardening  
**Slice:** A — Field Grouping and Required-Field Clarity  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21

---

## Closure Summary

Story 31.2 Slice A improves Product Create/Edit form clarity with UI-only changes
to field labels, placeholders, helper text, required-field affordances, and status
wording consistency.

The implementation does not change controller logic, persistence behavior, pricing
logic, tax logic, inventory deduction, recipe semantics, POS runtime behavior, or
subscription entitlement rules.

---

## Completed Scope

### Create Form

- Added autofocus to Product Name.
- Added helper text for Product Name, SKU / Item Code, Barcode, Base Selling
  Price, and Unit of Measure.
- Clarified Barcode placeholder with scanner-code examples.
- Clarified Base Selling Price as VAT-inclusive at checkout.
- Clarified Unit of Measure as the inventory and recipe quantity basis.

### Edit Form

- Added placeholders and helper text for Product Name, SKU, Barcode, Description,
  Unit of Measure, Global Selling Price, and Base Cost.
- Added required category selection guard.
- Aligned status wording from `Archived` to `Inactive`.
- Preserved existing branch pricing and recipe entry-point behavior without
  changing pricing or recipe engines.

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

Story 31.2 Slice A is UI-only. It does not:

- alter ProductController behavior
- alter product persistence rules
- alter pricing calculations
- alter tax calculations
- alter inventory deduction or movement posting
- alter recipe/BOM computation
- alter POS runtime or checkout behavior
- alter subscription feature gates or RBAC rules
- add import/export or bulk creation behavior

---

## Files Touched

- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`
- `docs/validation/story-31.2-slice-a-product-create-edit-field-clarity-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`

---

## Next Recommended Slice

Proceed to Story 31.2 Slice B — Validation/Error Feedback Hardening only after
explicit approval.
