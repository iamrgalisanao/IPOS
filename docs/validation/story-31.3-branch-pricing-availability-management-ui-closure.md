# Story 31.3 — Branch Pricing and Availability Management UI Closure

**Epic:** 31 — Product Catalog and Inventory Admin UX Completion  
**Story:** 31.3 — Branch Pricing and Availability Management UI  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21  
**Governance Ref:** G-068

---

## Closure Summary

Story 31.3 improves branch-level pricing and availability management clarity in
Product Admin through frontend-only UI and interaction hardening.

The implementation focuses on the existing Product Edit branch pricing surface. It
adds clearer global-vs-branch pricing hierarchy, branch override feedback copy,
field-level modal feedback, empty-state guidance, and action label clarity while
preserving the existing backend endpoints, pricing semantics, validation rules,
authorization, subscription gates, and tenant/branch isolation.

---

## Completed Scope

- Added a Global Default Price panel inside the Branch Pricing section.
- Clarified that branch override rows affect selected branches only and do not
  modify global pricing rules.
- Added per-row delta copy showing how each override compares to the global price.
- Improved Branch Pricing section helper text and operator guidance.
- Improved branch override modal helper copy.
- Added branch override success/error feedback banners based on existing backend
  outcomes.
- Added field-level feedback display for branch selection and override price errors.
- Improved branch override action labels and processing copy.
- Added explicit remove affordance title text.
- Refined no-override empty-state copy.

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

Story 31.3 is UI-only branch pricing and availability management hardening. It
does not:

- alter branch pricing engine behavior
- alter pricing calculations
- alter availability computation semantics
- alter inventory deduction or movement posting
- alter tax, POS runtime, or checkout behavior
- alter recipe/BOM computation or consumption behavior
- alter server-side or model-level validation rules
- alter `ProductController` persistence logic
- introduce new backend APIs, endpoint contracts, or mutation workflows
- alter subscription feature gates
- alter RBAC or middleware checks
- alter tenant or branch isolation
- introduce a dedicated standalone branch-pricing workspace/module

---

## Files Touched

- `resources/js/Pages/Admin/Products/Edit.jsx`
- `docs/validation/story-31.3-branch-pricing-availability-management-ui-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`
- `.agents/rules/03-current-focus.md`

---

## Final Governance Decision

Story 31.3 — Branch Pricing and Availability Management UI is accepted for
closure.

Recommended next step: proceed to Story 31.4 — Inventory Overview and Stock
Visibility Dashboard as a planning lock first.
