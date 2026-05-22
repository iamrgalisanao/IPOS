# Story 29.1A Wave 2 Slice B - catalog.view Read Routes with Dependency Review Plan

Date: 2026-05-20
Status: Phase B1 Implemented and Locally Validated / Phase B2 Deferred
Slice: B

---

## 1. Scope Intent
Target feature key: `catalog.view`

Objective: classify catalog-related read surfaces and apply approved low-risk gating without breaking POS and inventory runtime dependencies.

Constraint: only Phase B1 is implemented in this slice. Runtime shared dependencies and B2 routes remain deferred.

---

## 1.1 Implementation Result (Phase B1)
Implemented middleware:
- `GET /admin/product-categories` (`admin.product-categories.index`) now includes `subscription.feature:catalog.view`
- `GET /admin/products` (`admin.products.index`) now includes `subscription.feature:catalog.view`

Navigation alignment:
- Product Catalog menu visibility now requires both `manage_products` permission and `catalog.view` entitlement.

Validation evidence:
- `./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php`
- Result: 19 passed (37 assertions)

---

## 2. Route Classification

### 2.1 Pure Back-Office Read Routes (Safer Candidates)
These are admin/back-office catalog reads and can be considered first for `subscription.feature:catalog.view` in implementation:
- `GET /admin/product-categories` (`admin.product-categories.index`)
- `GET /admin/products` (`admin.products.index`)

### 2.2 POS/Inventory Shared Read Dependencies
These routes are used by runtime operational workflows and require higher caution:
- `GET /pos/search` (`pos.search`) - POS product lookup via catalog service
- `GET /inventory/stocktakes/catalog/search` (`inventory.stocktakes.catalog.search`) - stocktake dynamic catalog search

### 2.3 Read/Form Routes Deferred for Decision in Slice B
These are read endpoints but tied to write workflows and should be reviewed with UX and permission behavior:
- `GET /admin/products/create` (`admin.products.create`)
- `GET /admin/products/{product}/edit` (`admin.products.edit`)

Current recommendation: treat these as catalog-view-dependent UI reads, but gate only after validating interaction with Slice A (`catalog.edit` write gates).

### 2.4 Routes That Should Remain Ungated in Slice B
To avoid runtime disruption in this slice, keep these ungated until explicit follow-on approval:
- `GET /pos/search` (`pos.search`)
- `GET /inventory/stocktakes/catalog/search` (`inventory.stocktakes.catalog.search`)

Rationale: both support operational search workflows; gating them prematurely can break cashier and stocktake execution.

---

## 3. Middleware Placement Proposal (Planning-Only)

Phase B1 (low risk):
- add `subscription.feature:catalog.view` to
  - `admin.product-categories.index`
  - `admin.products.index`

Status: implemented.

Phase B2 (reviewed risk):
- evaluate adding `subscription.feature:catalog.view` to
  - `admin.products.create`
  - `admin.products.edit`

Status: deferred pending explicit approval.

Do not include runtime POS/inventory search endpoints in Slice B implementation unless separately approved.

---

## 4. Navigation Behavior: Hide vs Disable

Recommendation:
- Hide Product Catalog menu entry when tenant lacks `catalog.view`.
- Hide category/product list access points in back-office navigation when not entitled.
- For create/edit actions:
  - if `catalog.view` denied: hide action links/buttons entirely.
  - if `catalog.view` allowed but `catalog.edit` denied: keep read views available and disable/hide write controls per Slice A behavior.

Avoid disabled nav for runtime paths in this slice; runtime routes are deferred and unchanged.

---

## 5. Regression Tests Required Before Implementation

### Allow/Deny Route Tests
- deny: non-entitled tenant cannot access `admin.product-categories.index`
- deny: non-entitled tenant cannot access `admin.products.index`
- allow: entitled tenant can access both routes

### Mixed Entitlement Tests
- `catalog.view` true + `catalog.edit` false:
  - list/index views accessible
  - write endpoints remain blocked (already covered by Slice A tests)

### Non-Regression Tests (Operational)
- `pos.search` behavior unchanged for entitled tenants
- `inventory.stocktakes.catalog.search` behavior unchanged for entitled tenants
- no unexpected 403 regressions on runtime lookup flows

### Context-Sensitive Checks
- validate branch/tenant context behavior stays unchanged for deferred runtime routes
- ensure no new dependency on branch context is introduced for admin read routes

---

## 6. Rollback Notes (If Slice B Later Implemented)
- Remove `subscription.feature:catalog.view` from any newly gated read routes.
- Restore prior navigation visibility conditions in catalog menu/actions.
- Re-run route-gate suite plus POS/inventory lookup smoke tests.
- Record residual risk note in coverage map and task ledger.

---

## 7. Approval Gate
Slice B implementation may proceed only when all are true:
- [x] Governance approved Slice B Phase B1 rollout.
- [x] Route classification accepted (safe vs shared dependency).
- [x] Middleware placement list accepted (B1/B2 split).
- [x] Hide/disable behavior accepted.
- [x] Regression test list accepted.
- [x] Story 29.2 onboarding remains blocked pending Wave 2 review completion.
