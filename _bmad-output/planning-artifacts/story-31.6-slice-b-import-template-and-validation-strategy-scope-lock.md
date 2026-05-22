# Story 31.6 Slice B Scope Lock - Import Template and Validation Strategy

Status: Planning / Scope Locked
Date: 2026-05-22
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.6 - Catalog Import/Export and Audit Hardening
Slice: B - Import Template and Validation Strategy
Governance Ref: G-068
Predecessor: Story 31.6 Slice A - Implemented, Locally Validated, and Governance-Recorded

---

## 0. Slice Intent

Story 31.6 Slice B defines approved boundaries for planning safe catalog import
templates and validation-preview behavior before any write-path implementation is
considered.

It authorizes planning only and does not authorize actual import writes, bulk
create/update workflows, background jobs, or any pricing/tax/inventory/recipe
business-rule changes.

---

## 1. Goal

Plan safe catalog import templates and validation-preview behavior for products
and categories while preserving all current product write semantics,
pricing/tax/inventory/recipe computation behavior, POS/runtime behavior,
subscription behavior, accounting-sensitive behavior, backend contracts, RBAC
checks, and tenant/branch isolation.

---

## 2. Current Surface Baseline

Current catalog import/export-adjacent surfaces in the codebase:

Pages:
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`
- `resources/js/Pages/Admin/ProductCategories/Index.jsx`

Existing export slice surfaces already implemented:
- `GET /admin/products/export/csv`
- `GET /admin/product-categories/export/csv`
- `app/Services/Catalog/CatalogCsvExportService.php`

Existing catalog controllers/models relevant for planning reference only:
- `app/Http/Controllers/Admin/ProductController.php`
- `app/Http/Controllers/Admin/ProductCategoryController.php`
- `app/Models/Product.php`
- `app/Models/ProductCategory.php`
- existing tax/category/product references already used in current catalog forms

Guardrail suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Slice B implementation planning may include:

- Product import template definition.
- Category import template definition.
- Required versus optional column mapping rules.
- Validation-only preview strategy before any write path.
- Duplicate handling rules.
- SKU collision handling rules.
- Category reference handling rules.
- Tax reference handling rules.
- Failure report design for import attempts or validation previews.
- Audit expectations for import attempts, preview actions, and failure outcomes.

---

## 4. Out of Scope

Not approved under Slice B:

- Actual import writes.
- Bulk product/category creation implementation.
- Bulk product/category update implementation.
- Background jobs or queue architecture.
- Pricing calculation changes.
- Tax behavior changes.
- Inventory deduction, posting, or movement changes.
- Recipe/BOM computation changes.
- POS checkout/runtime changes.
- Subscription engine changes.
- Accounting certification claims.
- Backend contract changes unless separately approved.
- Tenant or branch isolation model changes.

---

## 5. Acceptance Boundaries

Slice B may modify after explicit implementation approval:

- Planning artifacts for import template columns and preview behavior.
- UI planning for validation-only preview entry points and failure reporting.
- Validation/error messaging strategy for duplicate, SKU, category, and tax
  reference handling.
- Auditability planning for import-attempt and preview visibility.

Slice B must not modify without separate write-path approval:

- Product or category persistence behavior.
- Existing create/edit write contracts.
- Pricing, tax, inventory, recipe, or costing logic.
- POS/runtime, accounting, or subscription behavior.
- Middleware, RBAC, tenant isolation, or branch isolation rules.

---

## 6. RBAC and Feature-Gate Lock

No permission relaxation is approved.

The following remain mandatory and unchanged:
- Existing catalog access controls remain enforced.
- Any future import preview/attempt surface must remain fail-closed.
- `catalog.view` must not implicitly grant write behavior.
- `catalog.edit` must not be treated as blanket approval for bulk writes.
- Existing middleware, RBAC, subscription gates, tenant isolation, and branch
  isolation remain mandatory.

---

## 7. Data Integrity and Audit Expectations

- Import templates must be defined against existing authoritative catalog field
  expectations.
- Validation-preview planning must be explicit about non-mutating behavior.
- Duplicate/SKU/category/tax reference handling rules must be behavior-accurate
  to current domain constraints.
- Failure reporting must avoid operator ambiguity and partial-write assumptions.
- Audit expectations must preserve who initiated an import attempt or preview,
  what scope was targeted, and when it occurred.
- No hidden writes, no background mutation, and no side effects beyond approved
  audit visibility are permitted.

---

## 8. Test Strategy Lock

Required validation baseline for any future Slice B implementation:

- Frontend build passes (`npm run build`).
- Catalog permissions and feature-gate behavior remain intact.
- Product catalog write behavior remains unchanged.
- Pricing and isolation guardrails remain intact.
- Any preview behavior remains read-only and tenant-isolated.

Required suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:

1. Product/category import template field definition.
2. Required/optional column mapping and reference-rule design.
3. Validation-only preview and failure-report design.
4. Audit-log expectation definition for preview/attempt flows.
5. Regression validation with required guardrail suites.

---

## 10. Governance Lock

Story 31.6 Slice B is planning and scope-lock only.

No Slice B implementation is approved until explicit implementation approval is
received for this slice.