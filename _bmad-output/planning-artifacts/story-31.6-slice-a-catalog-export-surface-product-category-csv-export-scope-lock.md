# Story 31.6 Slice A Scope Lock - Catalog Export Surface / Product & Category CSV Export

Status: Planning / Scope Locked
Date: 2026-05-22
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.6 - Catalog Import/Export and Audit Hardening
Slice: A - Catalog Export Surface / Product & Category CSV Export
Governance Ref: G-068
Predecessor: Story 31.6 - Catalog Import/Export and Audit Hardening (Scope Locked)

---

## 0. Slice Intent

Story 31.6 Slice A defines approved boundaries for safe read-only catalog export
of products and categories using CSV, with permission gating, tenant isolation,
CSV safety, safe headers/filenames, and audit visibility.

It authorizes export-first planning only and does not authorize import upload,
bulk create/update workflows, or any product/business-rule computation changes.

---

## 1. Goal

Implement safe read-only catalog export for products and categories while
preserving pricing/tax/inventory/recipe computation behavior, POS/runtime
behavior, subscription behavior, accounting-sensitive behavior, backend
contracts, RBAC checks, and tenant/branch isolation.

---

## 2. Current Surface Baseline

Primary catalog surfaces currently in use:

Pages:
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`

Existing catalog controller/reference surface:
- `app/Http/Controllers/Admin/ProductController.php`

Existing catalog models and related references:
- `app/Models/Product.php`
- `app/Models/ProductCategory.php`
- Existing tax/category/product fields already used in current admin forms

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Slice A implementation may include:

- Product CSV export.
- Category CSV export.
- Permission-gated export route planning/implementation.
- `catalog.view` / `catalog.edit` expectation review for export access.
- Tenant isolation preservation.
- CSV formula-injection protection.
- Audit logging for export actions.
- Safe export filename and response content-header behavior.
- Read-only export entry points in existing catalog admin surfaces.

---

## 4. Out of Scope

Not approved under Slice A:

- Import upload workflows.
- Bulk product creation or update.
- Pricing calculation changes.
- Tax behavior changes.
- Inventory deduction, posting, or movement changes.
- Recipe/BOM computation changes.
- POS checkout/runtime changes.
- Subscription engine changes.
- Accounting certification claims.
- Backend contract changes unless separately approved.
- Background processing architecture changes.
- Tenant or branch isolation model changes.

---

## 5. Acceptance Boundaries

Slice A may modify:

- Read-only export route/controller wiring for approved product/category CSV
  downloads.
- Existing catalog admin UI to expose export actions.
- CSV serialization/output hardening limited to approved read-only exports.
- Audit logging tied to export initiation.

Slice A must not modify:

- Product write-path persistence behavior.
- Pricing, tax, inventory, recipe, or costing logic.
- POS/runtime, accounting, or subscription behavior.
- Existing create/edit product contracts beyond adding approved read-only export
  pathways.
- Import/write workflows of any kind.

---

## 6. RBAC and Feature-Gate Lock

No permission relaxation is approved.

The following remain mandatory and unchanged:
- Export access must remain permission-gated.
- `catalog.view` and `catalog.edit` expectations must be reviewed explicitly and
  implemented fail-closed.
- Existing middleware, RBAC, subscription gates, tenant isolation, and branch
  isolation remain mandatory.
- Export access must not create an implicit write pathway.

---

## 7. Data Integrity and Audit Expectations

- Exported CSV data must reflect existing authoritative product/category data.
- CSV output must be hardened against spreadsheet formula-injection.
- Filenames and response headers must be safe, deterministic, and user-accurate.
- Export actions must be auditable by actor, scope, and timestamp.
- No hidden writes, no background mutation, and no side effects beyond audit
  logging are permitted.

---

## 8. Test Strategy Lock

Required validation for Slice A implementation:

- Frontend build passes (`npm run build`).
- Export routes remain permission-gated.
- Product catalog behavior remains intact.
- Pricing and isolation guardrails remain intact.
- Export responses are read-only and tenant-isolated.

Required suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:

1. Product/category export route and permission decision.
2. CSV payload field selection and header safety hardening.
3. Export entry-point UI wiring in existing catalog surfaces.
4. Audit-log coverage for export initiation.
5. Regression validation with required guardrail suites.

---

## 10. Governance Lock

Story 31.6 Slice A is planning and scope-lock only.

No Slice A implementation is approved until explicit implementation approval is
received for this slice.