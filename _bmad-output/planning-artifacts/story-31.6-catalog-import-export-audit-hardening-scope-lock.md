# Story 31.6 Scope Lock - Catalog Import/Export and Audit Hardening

Status: Planning / Scope Locked
Date: 2026-05-22
Epic: Epic 31 - Product Catalog and Inventory Admin UX Completion
Story: 31.6 - Catalog Import/Export and Audit Hardening
Governance Ref: G-068
Predecessor: Story 31.5 - Recipe / Ingredient Admin Management UI (Closed)

---

## 0. Story Intent

Story 31.6 defines approved planning boundaries for safe catalog import/export
workflows with auditability, validation, tenant isolation, CSV safety, and
rollback-friendly behavior.

This story authorizes planning only.

---

## 1. Goal

Plan safe catalog import/export workflows for Back Office operators without
changing pricing/tax/inventory/recipe computation behavior, POS runtime
behavior, subscription engine behavior, accounting-sensitive behavior, backend
contracts, RBAC checks, or tenant/branch isolation.

---

## 2. Current Surface Baseline

Known existing catalog-adjacent surfaces in the current codebase:

Pages:
- `resources/js/Pages/Admin/Products/Index.jsx`
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`

Routes:
- Existing product management routes under `/admin/products`
- Existing category-management/admin catalog pathways already used by current
  Product Catalog flows

Controllers:
- `app/Http/Controllers/Admin/ProductController.php`

Models:
- `app/Models/Product.php`
- `app/Models/ProductCategory.php`
- Related tax/category references already used by current product forms

Relevant guardrail and isolation suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

Relevant audit/inventory-adjacent suites to preserve unaffected behavior where
import/export touches adjacent catalog surfaces:
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 3. In Scope

Story 31.6 implementation planning may include:

- Catalog export planning.
- Product CSV export planning.
- Product category CSV export planning.
- Import validation strategy.
- CSV/formula-injection protection strategy.
- Failure reporting and operator-facing import result planning.
- Audit logging requirements for catalog import/export actions.
- Tenant isolation, branch isolation, RBAC, and feature-gate preservation.
- Rollback-friendly behavior planning for import failures.
- Template/layout planning for safe bulk catalog data interchange.

---

## 4. Out of Scope

Not approved under Story 31.6:

- Bulk import implementation before explicit approval.
- Pricing calculation changes.
- Tax behavior changes.
- Inventory deduction, posting, or movement changes.
- Recipe/BOM computation changes.
- POS runtime or checkout behavior changes.
- Subscription engine changes.
- Accounting behavior changes.
- Compliance/export certification claims.
- Backend endpoint contract changes unless separately approved.
- New background processing architecture unless separately approved.
- Changes to tenant/branch isolation semantics.

---

## 5. Acceptance Boundaries

31.6 may modify after explicit implementation approval:

- Planning artifacts for export/import information architecture.
- UI planning for export actions, import upload entry points, and failure
  reporting surfaces.
- Validation/error messaging strategy for bulk catalog data review.
- Auditability planning for who initiated import/export actions and what scope
  they targeted.

31.6 must not modify without a separate implementation approval:

- Product pricing logic or branch-pricing behavior.
- Tax-category semantics or tax calculation behavior.
- Inventory, recipe, or costing engines.
- POS, checkout, accounting, or subscription behavior.
- Middleware, RBAC, tenant isolation, or branch isolation rules.

---

## 6. RBAC and Feature-Gate Lock

Permission and feature-gate expectations remain mandatory and unchanged:

- Existing catalog view/edit permissions remain enforced.
- `catalog.view` access must not implicitly grant import/export mutation rights.
- `catalog.edit` and any existing admin write protections remain fail-closed.
- No relaxation of middleware, RBAC, subscription gates, tenant isolation, or
  branch isolation is approved.

---

## 7. Data Integrity and Audit Expectations

Story 31.6 planning must preserve integrity and audit posture:

- Exported catalog data must reflect existing authoritative catalog records.
- Import planning must include validation-first behavior before any write path.
- CSV safety must explicitly address spreadsheet formula-injection risk.
- Failure reporting must avoid silent partial writes without operator clarity.
- Audit logging requirements must preserve who, what scope, and when for import
  and export actions.
- Rollback-friendly behavior must be explicit for failure handling and recovery.

---

## 8. Test Strategy Lock

Required validation baseline for any future Story 31.6 implementation:

- Frontend build passes (`npm run build`).
- Catalog permissions and feature-gate behavior remain intact.
- Product catalog write behavior and isolation remain intact.
- No regressions are introduced in adjacent inventory audit guardrails.

Recommended suites:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`
- `tests/Feature/Inventory/UnitConversionManagementTest.php`
- `tests/Feature/Inventory/VarianceLogAuditingTest.php`

---

## 9. Delivery Guidance (Post-Approval)

Suggested sequence after explicit implementation approval:

1. Export-surface planning for products and categories.
2. Import template and validation strategy definition.
3. Failure-reporting and operator feedback design.
4. Audit-log coverage and rollback/failure handling design.
5. Regression validation with required guardrail suites.

---

## 10. Governance Lock

Story 31.6 is planning and scope-lock only.

No implementation beyond documentation is approved until explicit Story 31.6
implementation approval is received.