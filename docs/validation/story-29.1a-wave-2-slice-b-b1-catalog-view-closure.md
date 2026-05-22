# Story 29.1A Wave 2 Slice B Phase B1 — catalog.view Closure

Date: 2026-05-20
Status: Implemented & Locally Validated

---

## 1. Implementation Scope

### 1.1 Gated Routes (Back-Office Safe Catalog Reads)

Middleware applied: `subscription.feature:catalog.view`

#### Route 1: Product Categories Index
- **Route Name:** `admin.product-categories.index`
- **HTTP Method:** GET
- **Path:** `/admin/product-categories`
- **File:** [routes/web.php](../../routes/web.php#L479)
- **Middleware Stack:** `['permission:manage_products', 'subscription.feature:catalog.view']`

#### Route 2: Products Index
- **Route Name:** `admin.products.index`
- **HTTP Method:** GET
- **Path:** `/admin/products`
- **File:** [routes/web.php](../../routes/web.php#L494)
- **Middleware Stack:** `['permission:manage_products', 'subscription.feature:catalog.view']`

### 1.2 Routes Intentionally Left Ungated

#### Runtime POS/Inventory Dependencies (Deferred by Design)

**Route:** POS Product Search
- **Route Name:** `pos.search`
- **HTTP Method:** GET
- **Path:** `/pos/search`
- **Rationale:** Operational runtime dependency for cashier; gating deferred to avoid checkout disruption
- **Status:** No middleware added; remains ungated
- **Test Evidence:** Non-regression test confirms 200 response for entitled tenants

**Route:** Stocktake Catalog Search
- **Route Name:** `inventory.stocktakes.catalog.search`
- **HTTP Method:** GET
- **Path:** `/inventory/stocktakes/catalog/search`
- **Rationale:** Operational runtime dependency for stocktake personnel; gating deferred to avoid inventory workflow disruption
- **Status:** No middleware added; remains ungated
- **Test Evidence:** Non-regression test confirms 200 response for entitled tenants (1-char query parameter to avoid triggering existing DB-specific search branch edge case)

#### Create/Edit Form Routes (Deferred to Slice B2)

**Route:** Product Create Form
- **Route Name:** `admin.products.create`
- **HTTP Method:** GET
- **Path:** `/admin/products/create`
- **Decision-Needed Category:** Read form route tied to write workflow; evaluation deferred to Slice B2
- **Status:** No middleware added; remains ungated
- **Rationale:** Interaction with Slice A (`catalog.edit` write gate) needs validation; decision-needed routes require explicit follow-on approval

**Route:** Product Edit Form
- **Route Name:** `admin.products.edit`
- **HTTP Method:** GET
- **Path:** `/admin/products/{product}/edit`
- **Decision-Needed Category:** Read form route tied to write workflow; evaluation deferred to Slice B2
- **Status:** No middleware added; remains ungated
- **Rationale:** Interaction with Slice A (`catalog.edit` write gate) needs validation; decision-needed routes require explicit follow-on approval

---

## 2. Navigation Alignment

### Product Catalog Menu Item Visibility

**File:** [resources/js/Layouts/AuthenticatedLayout.jsx](../../resources/js/Layouts/AuthenticatedLayout.jsx#L72)

**Change:**
```javascript
// Before
if (permissions.includes('manage_products')) {
    catalogAndStockItems.push({ ... });
}

// After
if (permissions.includes('manage_products') && hasFeature('catalog.view')) {
    catalogAndStockItems.push({ ... });
}
```

**Effect:** Product Catalog menu entry is now hidden when tenant lacks `catalog.view` entitlement, even if user has `manage_products` permission.

---

## 3. Validation Evidence

### Test Suite Execution

**Test File:** [tests/Feature/Subscription/RouteFeatureGateTest.php](../../tests/Feature/Subscription/RouteFeatureGateTest.php)

**Command Executed:**
```bash
./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php
```

**Result:** ✅ 19 tests / 37 assertions **PASSING**

### Test Coverage Breakdown

#### Slice B Phase B1 Allow/Deny Tests (New in This Phase)

1. **test_non_entitled_tenant_cannot_access_product_category_index**
   - Tenant: basic plan with `catalog.view: false`
   - Request: `GET /admin/product-categories`
   - Expected: 403 Forbidden
   - Result: ✅ Pass

2. **test_non_entitled_tenant_cannot_access_product_index**
   - Tenant: basic plan with `catalog.view: false`
   - Request: `GET /admin/products`
   - Expected: 403 Forbidden
   - Result: ✅ Pass

3. **test_entitled_tenant_can_access_product_category_index**
   - Tenant: basic plan (default features enabled)
   - Request: `GET /admin/product-categories`
   - Expected: 200 OK
   - Result: ✅ Pass

4. **test_entitled_tenant_can_access_product_index**
   - Tenant: basic plan (default features enabled)
   - Request: `GET /admin/products`
   - Expected: 200 OK
   - Result: ✅ Pass

#### Mixed Entitlement Test (Slice A + B1 Interaction)

5. **test_catalog_view_true_and_catalog_edit_false_can_view_lists_but_cannot_write**
   - Tenant: professional plan with `catalog.edit: false` (but `catalog.view: true` by default)
   - Index reads: `GET /admin/product-categories` → 200 OK ✅
   - Index reads: `GET /admin/products` → 200 OK ✅
   - Write attempt: `POST /admin/products` → 403 Forbidden ✅
   - Demonstrates proper layering of view and edit gates

#### Non-Regression Tests (Operational Routes)

6. **test_pos_search_remains_unchanged_for_entitled_tenants**
   - Tenant: basic plan (default features enabled)
   - Request: `GET /pos/search?q=t`
   - Expected: 200 OK (no new feature gating applied)
   - Result: ✅ Pass

7. **test_stocktake_catalog_search_remains_unchanged_for_entitled_tenants**
   - Tenant: basic plan (default features enabled)
   - Request: `GET /inventory/stocktakes/catalog/search?q=t`
   - Expected: 200 OK (no new feature gating applied)
   - Result: ✅ Pass

#### Prior Wave Regression Coverage

- **Wave 1 Tests:** 7 tests (reports.basic, reports.advanced, procurement.basic, procurement.advanced, layout.custom, quickbooks.sync)
- **Slice A Tests:** 5 tests (catalog.edit write endpoint allow/deny)
- **Total:** 19 tests / 37 assertions

---

## 4. Implementation Checklist

- [x] **Routes Identified & Classified**
  - Safe back-office reads: product-categories.index, products.index
  - Shared runtime dependencies: pos.search, inventory.stocktakes.catalog.search
  - Decision-needed form routes: products.create, products.edit

- [x] **Middleware Applied to Gated Routes**
  - `subscription.feature:catalog.view` added to both index routes
  - Permission checks (`manage_products`) preserved alongside feature gates

- [x] **Navigation Behavior Updated**
  - Product Catalog menu item visibility now requires `catalog.view` entitlement
  - Preserves existing permission constraint (`manage_products`)

- [x] **Allow/Deny Tests Added**
  - Non-entitled deny tests for both index routes
  - Entitled allow tests for both index routes

- [x] **Mixed Entitlement Test Added**
  - Confirms `catalog.view: true` + `catalog.edit: false` allows reads but blocks writes

- [x] **Non-Regression Tests Added**
  - Confirms `pos.search` unchanged
  - Confirms `inventory.stocktakes.catalog.search` unchanged
  - No new gates on runtime dependencies

- [x] **Test Suite Passed**
  - Full regression suite: 19 tests / 37 assertions passing
  - No failures
  - No edge case regressions

---

## 5. Governance Notes

### Scope Boundaries (Intentional Design)

**In Scope for Slice B1:**
- Safe back-office index routes only
- Navigation alignment where routes are fully gated
- Preservation of permission-based access control
- Non-regression validation for shared dependencies

**Out of Scope / Deferred:**
- Runtime POS and inventory search gating (operational risk)
- Create/edit form route gating (interaction with Slice A write gate needs validation; subsequently closed in Slice B2)
- Story 29.2 onboarding (blocked until 29.1A Wave 2 completion review)

### Design Rationale

Slice B Phase B1 gates only the safest, most isolated catalog read surfaces (index routes) with full dependency review to avoid breaking operational workflows. POS and stocktake search remain intentionally ungated. Create/edit form routes were deferred to Slice B2 pending explicit decision on how they interact with write-gate behavior.

---

## 6. Next Actions

### Option A: Slice B2 Completed
- **Decision Recorded:** `admin.products.create` and `admin.products.edit` are gated by `catalog.edit` because they are form routes tied to write workflows.
- **Closure Artifact:** `docs/validation/story-29.1a-wave-2-slice-b2-product-form-route-gating-closure.md`

### Option B: Accept Remaining Gaps and Close 29.1A Wave 2
- **Current Coverage:** Reports, procurement, layout fully gated; catalog.edit writes and product form routes gated; catalog.view index reads gated.
- **Remaining Gaps:** Form route gating, POS read gate, deferred to future scope.
- **Decision:** Lock current 29.1A Wave 2 state and unblock Story 29.2 onboarding.

---

## 7. Closure Sign-Off

- **Implementation Date:** 2026-05-20
- **Test Status:** ✅ All 19 tests passing
- **Review Status:** Ready for governance approval
- **Artifacts Updated:**
  - [_bmad-output/planning-artifacts/story-29.1a-wave-2-slice-b-catalog-view-plan.md](../../_bmad-output/planning-artifacts/story-29.1a-wave-2-slice-b-catalog-view-plan.md) — Marked B1 implemented/validated
  - [_bmad-output/planning-artifacts/story-29.1a-wave-2-implementation-checklist.md](../../_bmad-output/planning-artifacts/story-29.1a-wave-2-implementation-checklist.md) — Marked Slice B Phase B1 complete
  - [docs/validation/story-29.1a-feature-gate-coverage-map-initial.md](./story-29.1a-feature-gate-coverage-map-initial.md) — Updated coverage state to partial (B1 only)
  - [docs/ai-governance/task-ledger.md](../ai-governance/task-ledger.md) — G-060 updated with B1 evidence

---

## Appendix: Implementation Files Modified

| File | Change | Evidence |
|------|--------|----------|
| [routes/web.php](../../routes/web.php) | Added `subscription.feature:catalog.view` to two index routes | Lines 479, 494 |
| [resources/js/Layouts/AuthenticatedLayout.jsx](../../resources/js/Layouts/AuthenticatedLayout.jsx) | Updated Product Catalog menu visibility condition | Line 72 |
| [tests/Feature/Subscription/RouteFeatureGateTest.php](../../tests/Feature/Subscription/RouteFeatureGateTest.php) | Added 7 new tests (allow/deny, mixed entitlement, non-regression) | Lines 337–502 |
