# Slice A Planning Lock: Inventory Hub

Status: Closed - Implemented & Locally Validated
Date: 2026-05-25
Parent Plan: `docs/roadmap/market-readiness-inventory-operations-priority-plan.md`

## 1. Purpose

Create a planning-only scope lock for a unified Inventory Hub that makes existing
inventory, stocktake, reporting, recipe/composition, movement, and procurement
entry points easier to find and explain for pilot users.

This document does not approve implementation. It defines the route/page
inventory, user workflow map, information architecture, acceptance criteria, and
non-goals required before implementation can begin.

## 2. Execution Boundary

This task is planning-only.

Do not implement:

1. Routes.
2. Pages.
3. Controllers.
4. Services.
5. Migrations.
6. UI cards.
7. Print views.
8. Dashboard widgets.
9. Report exports.
10. Stock mutation workflows.

## 3. Current Route Inventory

### Existing Inventory Routes

| Area | Route | Method | Route Name | Permission Boundary | Current Role in Hub |
| --- | --- | --- | --- | --- | --- |
| Inventory dashboard | `/inventory/dashboard` | GET | `inventory.dashboard.index` | `view_branch_inventory` or `inventory.stocktake.view` | Primary existing inventory overview |
| Inventory movements | `/inventory/movements` | GET | `inventory.movements.index` | `view_branch_inventory` | Existing movement visibility entry |
| Stocktake list | `/inventory/stocktakes` | GET | `inventory.stocktakes.index` | `inventory.stocktake.view` | Count cycle list |
| New stocktake form | `/inventory/stocktakes/create` | GET | `inventory.stocktakes.create` | `inventory.stocktake.create` | Count cycle setup |
| Create stocktake | `/inventory/stocktakes` | POST | `inventory.stocktakes.store` | `inventory.stocktake.create` | Existing mutation, link only if permitted |
| Stocktake catalog search | `/inventory/stocktakes/catalog/search` | GET | `inventory.stocktakes.catalog.search` | `inventory.stocktake.count` | Existing support route, not a hub card |
| Stocktake detail | `/inventory/stocktakes/{stocktakeSession}` | GET | `inventory.stocktakes.show` | `inventory.stocktake.view` | Drill-down from stocktake list |
| Start counting | `/inventory/stocktakes/{stocktakeSession}/start-counting` | POST | `inventory.stocktakes.start-counting` | `inventory.stocktake.count` | Existing mutation, not hub-level action |
| Cancel stocktake | `/inventory/stocktakes/{stocktakeSession}/cancel` | POST | `inventory.stocktakes.cancel` | `inventory.stocktake.cancel` | Existing mutation, not hub-level action |
| Update counts | `/inventory/stocktakes/{stocktakeSession}/lines` | PUT | `inventory.stocktakes.lines.update` | `inventory.stocktake.count` | Existing mutation, not hub-level action |
| Submit stocktake | `/inventory/stocktakes/{stocktakeSession}/submit` | POST | `inventory.stocktakes.submit` | `inventory.stocktake.count` | Existing mutation, not hub-level action |
| Update variance reasons | `/inventory/stocktakes/{stocktakeSession}/reasons` | PUT | `inventory.stocktakes.variance-reasons.update` | `inventory.stocktake.review` | Existing mutation, not hub-level action |
| Reject stocktake | `/inventory/stocktakes/{stocktakeSession}/reject` | POST | `inventory.stocktakes.reject` | `inventory.stocktake.review` | Existing mutation, not hub-level action |
| Post stocktake | `/inventory/stocktakes/{stocktakeSession}/post` | POST | `inventory.stocktakes.post` | `inventory.stocktake.post` | Existing mutation, not hub-level action |
| Stocktake summary | `/inventory/stocktakes/{stocktakeSession}/summary` | GET | `inventory.stocktakes.summary` | `inventory.stocktake.view` | Report/detail target |
| Stocktake variance export | `/inventory/stocktakes/{stocktakeSession}/export/variance-csv` | GET | `inventory.stocktakes.export.variance-csv` | `inventory.stocktake.view` | Existing export target |
| Add stocktake line | `/inventory/stocktakes/{stocktakeSession}/add-line` | POST | `inventory.stocktakes.add-line` | `inventory.stocktake.count` | Existing mutation, not hub-level action |
| Unit conversions | `/inventory/unit-conversions` | GET | `inventory.unit-conversions.index` | Existing route group context; verify final permission in implementation readiness | Setup/maintenance target |
| Create unit conversion | `/inventory/unit-conversions` | POST | `inventory.unit-conversions.store` | Existing route group context; verify final permission in implementation readiness | Existing mutation, not hub-level action |
| Update unit conversion | `/inventory/unit-conversions/{unitConversion}` | PUT | `inventory.unit-conversions.update` | Existing route group context; verify final permission in implementation readiness | Existing mutation, not hub-level action |
| Delete unit conversion | `/inventory/unit-conversions/{unitConversion}` | DELETE | `inventory.unit-conversions.destroy` | Existing route group context; verify final permission in implementation readiness | Existing mutation, not hub-level action |
| Variance logs | `/inventory/reports/variance-logs` | GET | `inventory.reports.variance-logs.index` | `view_inventory_reports` or `audit_inventory` | Report target |
| Variance logs export | `/inventory/reports/variance-logs/export` | GET | `inventory.reports.variance-logs.export` | `view_inventory_reports` or `audit_inventory` | Existing export target |
| Product composition report | `/inventory/reports/product-composition` | GET | `inventory.reports.product-composition.index` | `view_inventory_reports` or `audit_inventory` | Report target |
| Product composition export | `/inventory/reports/product-composition/export` | GET | `inventory.reports.product-composition.export` | `view_inventory_reports` or `audit_inventory` | Existing export target |

### Related Catalog and Recipe Routes

| Area | Route | Method | Route Name | Permission / Feature Boundary | Current Role in Hub |
| --- | --- | --- | --- | --- | --- |
| Product list | `/admin/products` | GET | `admin.products.index` | `manage_products` + `catalog.view` | Catalog setup target |
| Product create form | `/admin/products/create` | GET | `admin.products.create` | `manage_products` + `catalog.edit` | Setup workflow target |
| Product edit form | `/admin/products/{product}/edit` | GET | `admin.products.edit` | `manage_products` + `catalog.edit` | Recipe/ingredient setup target |
| Product recipe update | `/admin/products/{product}/recipe` | POST | `admin.products.recipe.update` | `manage_products` + `catalog.edit` | Existing mutation, not hub-level action |
| Product categories | `/admin/product-categories` | GET | `admin.product-categories.index` | `manage_products` + `catalog.view` | Setup target |
| Branch inventory policy | `/admin/branches/{branch}/inventory-policy` | PUT | `admin.branches.inventory-policy.update` | Existing admin route group context; verify final permission in implementation readiness | Policy setup target, not a hub mutation |

### Related Procurement Routes

Procurement routes are useful context for inventory operations, but the first
hub implementation should link to them only as existing workflows. It must not
create purchase orders, receiving records, returns, supplier invoices, or
replenishment drafts.

| Area | Route Family | Feature Boundary | Permission Boundary | Current Role in Hub |
| --- | --- | --- | --- | --- |
| Suppliers | `/procurement/suppliers*` | `procurement.basic` | `procurement.suppliers.view/manage` | Procurement setup target |
| Purchase orders | `/procurement/purchase-orders*` | `procurement.basic` | `procurement.purchase-orders.*` | Inbound planning target |
| Receivings | `/procurement/receivings*` | `procurement.basic` | `procurement.receiving.*` | Inventory ingestion target |
| Supplier returns | `/procurement/returns*` | `procurement.advanced` | `procurement.returns.*` | Advanced reverse-logistics target |

## 4. Current Page Inventory

### Existing Inventory Pages

| Page | File | Notes |
| --- | --- | --- |
| Inventory dashboard | `resources/js/Pages/Inventory/Dashboard/Index.jsx` | Existing overview surface |
| Inventory movements | Controller route exists; page component should be confirmed before implementation | Used as movement visibility entry |
| Stocktake list | `resources/js/Pages/Inventory/Stocktake/Index.jsx` | Session register |
| Stocktake create | `resources/js/Pages/Inventory/Stocktake/Create.jsx` | Session setup |
| Stocktake show/counting | `resources/js/Pages/Inventory/Stocktake/Show.jsx` | Count entry/detail |
| Stocktake review | `resources/js/Pages/Inventory/Stocktake/Review.jsx` | Variance reason review |
| Stocktake summary | `resources/js/Pages/Inventory/Stocktake/Summary.jsx` | Final review/export surface |
| Variance logs | `resources/js/Pages/Inventory/VarianceLogs/Index.jsx` | Report surface |
| Product composition | `resources/js/Pages/Inventory/ProductComposition/Index.jsx` | Report surface |
| Unit conversions | `resources/js/Pages/Inventory/UnitConversions/Index.jsx` | Setup/maintenance surface |

### Related Pages

| Page | File | Hub Relationship |
| --- | --- | --- |
| Product list | `resources/js/Pages/Admin/Products/Index.jsx` | Catalog setup |
| Product create | `resources/js/Pages/Admin/Products/Create.jsx` | Product setup |
| Product edit | `resources/js/Pages/Admin/Products/Edit.jsx` | Recipe/ingredient setup |
| Product categories | `resources/js/Pages/Admin/ProductCategories/Index.jsx` | Category setup |
| Supplier list/create/edit/show | `resources/js/Pages/Procurement/Suppliers/*.jsx` | Procurement setup |
| Purchase order list/create/edit/show | `resources/js/Pages/Procurement/PurchaseOrders/*.jsx` | Inbound planning |
| Receiving list/create/edit/show | `resources/js/Pages/Procurement/Receivings/*.jsx` | Stock ingestion |

## 5. User Role Workflow Map

### Branch Manager

Primary jobs:

1. Check stock status.
2. Start or monitor stocktake.
3. Review stock movements.
4. Export stocktake and variance reports.
5. Identify low-stock items.

Relevant permissions:

1. `view_branch_inventory`
2. `inventory.stocktake.view`
3. `inventory.stocktake.create`
4. `inventory.stocktake.review`
5. `inventory.stocktake.post` where authorized
6. `view_inventory_reports`

Hub needs:

1. Fast links to stock dashboard, stocktake, movement summary, and inventory reports.
2. Clear permission-limited states when post/review actions are unavailable.

### Counter

Primary jobs:

1. Count physical inventory.
2. Enter quantities.
3. Submit count for review.

Relevant permissions:

1. `inventory.stocktake.count`
2. `inventory.stocktake.view`

Hub needs:

1. Direct path to active stocktake sessions.
2. No exposure to cost/audit-only report details unless separately permitted.

### Reviewer

Primary jobs:

1. Review variance lines.
2. Add reason codes.
3. Reject low-quality sessions.

Relevant permissions:

1. `inventory.stocktake.review`
2. `inventory.stocktake.view`

Hub needs:

1. Direct path to review-state stocktakes.
2. Clear status and outstanding review tasks.

### Auditor / Owner

Primary jobs:

1. Review reports and exports.
2. Inspect variance logs and composition/cost context.
3. Preserve audit evidence.

Relevant permissions:

1. `view_inventory_reports`
2. `audit_inventory`
3. `view_branch_inventory`

Hub needs:

1. Report group for variance logs, product composition, stocktake summary, and movement evidence.
2. Cost visibility only when `audit_inventory` is present.

### Catalog / Inventory Admin

Primary jobs:

1. Maintain product catalog.
2. Maintain recipes and ingredients.
3. Maintain unit conversions.
4. Manage branch inventory policies.

Relevant permissions and features:

1. `manage_products`
2. `catalog.view`
3. `catalog.edit`
4. Inventory setup permissions as currently enforced by route groups.

Hub needs:

1. Setup group linking to products, categories, recipe workspace, and unit conversions.
2. No new editing workflow in the hub itself.

### Procurement User

Primary jobs:

1. Manage suppliers.
2. Create or review purchase orders.
3. Receive stock.
4. Review returns where enabled.

Relevant permissions and features:

1. `procurement.basic`
2. `procurement.advanced`
3. `procurement.suppliers.*`
4. `procurement.purchase-orders.*`
5. `procurement.receiving.*`
6. `procurement.returns.*`

Hub needs:

1. Contextual links to inbound inventory workflows.
2. No procurement automation or new purchasing mutation.

## 6. Hub Information Architecture

The first implementation should be a read-mostly navigation and status hub with
role-aware sections. It should not become a dense analytics dashboard yet.

### Proposed Sections

1. **Inventory Overview**
   - Existing inventory dashboard.
   - Inventory movements.
   - Low-stock/reorder dashboard placeholder or future-link only until Slice D.

2. **Stocktake Operations**
   - Stocktake list.
   - Create stocktake when permitted.
   - Review/summary guidance links.
   - Export guidance using existing stocktake summary/export routes.

3. **Reports and Audit**
   - Variance logs.
   - Product ingredient composition.
   - Stocktake summaries.
   - Cost/audit messaging conditioned by `audit_inventory`.

4. **Catalog and Recipe Setup**
   - Product catalog.
   - Product categories.
   - Recipe/ingredient setup through existing product edit workflow.
   - Unit conversions.

5. **Inbound and Procurement**
   - Suppliers.
   - Purchase orders.
   - Receivings.
   - Supplier returns where `procurement.advanced` is enabled.

6. **Training and SOP**
   - Link or reference the stocktake user enablement guide.
   - Future screenshot pack target.

### Information Architecture Rules

1. Prefer existing routes over new behavior.
2. Show only links the actor can reasonably access.
3. Use clear permission-limited empty states.
4. Keep mutations inside existing workflow pages.
5. Do not surface cost-sensitive details without `audit_inventory`.
6. Do not imply BIR certification or official compliance format readiness.

## 7. Acceptance Criteria

Implementation may proceed only if the eventual Slice B scope satisfies these
criteria:

1. Hub is read-mostly and primarily links to existing workflow/report surfaces.
2. Existing inventory-related routes are represented or intentionally excluded
   with rationale.
3. Existing inventory-related pages/reports are mapped.
4. Hub respects existing permissions and feature entitlements.
5. Hub does not introduce new stock mutation routes.
6. Hub does not introduce procurement automation.
7. Hub does not create or modify purchase orders, receivings, returns, products,
   recipes, stocktakes, stocktake lines, inventory movements, or variance logs.
8. Hub does not add migrations.
9. Hub does not claim BIR certification, BIR accreditation, or official
   compliance format readiness.
10. Hub includes permission-limited and no-data states.
11. Hub remains branch/tenant safe.
12. Hub has focused feature tests for access and payload/page rendering.
13. Frontend build must pass after implementation.

## 8. Non-Goal Confirmation

This Slice A plan explicitly excludes:

1. Recursive POS recipe deduction.
2. Recipe editor rebuild.
3. Catalog import write path.
4. Auto-reorder purchasing mutation.
5. New procurement scheduler behavior.
6. New purchase order generation.
7. New stock adjustment workflow.
8. New stocktake posting behavior.
9. Accounting sync changes.
10. Tax, Z-read, GCT, receipt, or e-journal changes.
11. Offline-sales rollout.
12. BIR certification or accreditation claims.
13. New database tables or migrations.
14. New export formats.
15. Print views; those belong to a later slice.

## 9. Implementation Readiness Checklist

Before Slice B implementation starts, confirm:

1. Existing inventory-related routes are listed.
2. Existing pages/reports are mapped.
3. User roles and permission boundaries are identified.
4. Route names and page component names are verified against current code.
5. The hub remains read-mostly.
6. No stock mutation workflow is introduced.
7. No procurement automation is introduced.
8. No BIR certification or compliance-format claim is added.
9. Cost-sensitive cards are hidden or masked unless `audit_inventory` is present.
10. Any missing component, such as the inventory movement page, is either verified
    or treated as a link-only/route-only target until implementation research.
11. Test targets are identified before coding.
12. `npm run build` is planned as required validation.

## 10. Proposed Slice B Implementation Shape

This is advisory only and does not approve implementation.

Potential files:

1. `app/Http/Controllers/Inventory/InventoryHubController.php`
2. `resources/js/Pages/Inventory/Hub/Index.jsx`
3. `tests/Feature/Inventory/InventoryHubTest.php`
4. `routes/web.php`
5. Existing navigation host in `resources/js/Layouts/AuthenticatedLayout.jsx` or
   current Inventory Dashboard navigation host.

Potential route:

1. `GET /inventory/hub` -> `inventory.hub.index`

Potential minimum permission:

1. `view_branch_inventory|inventory.stocktake.view|view_inventory_reports|audit_inventory|manage_products|procurement.suppliers.view|procurement.purchase-orders.view|procurement.receiving.view`

Final middleware should be reviewed before implementation to avoid accidentally
blocking support/setup users or exposing inventory surfaces too broadly.

## 11. Decision

Slice A is accepted and implementation is complete within read-only guardrails.

Closure evidence:

1. `docs/validation/inventory-hub-implementation-closure.md`
2. `app/Http/Controllers/Inventory/InventoryHubController.php`
3. `resources/js/Pages/Inventory/Hub/Index.jsx`
4. `tests/Feature/Inventory/InventoryHubTest.php`

Validation summary:

1. `php artisan test tests/Feature/Inventory/InventoryHubTest.php` passed (4 tests, 44 assertions).
2. `npm run build` passed.

Boundary confirmation:

1. Hub remains read-mostly and route-link oriented.
2. No new mutation workflows were introduced for inventory, stocktake, procurement, or reporting.
3. No BIR certification or accreditation claim was added.
