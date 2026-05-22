# Story 29.1A Wave 2 Slice A - catalog.edit Admin Write Routes Plan

Date: 2026-05-20
Status: Implemented & Locally Validated
Slice: A (Recommended First)

---

## 1. Exact catalog.edit Write Routes
Target feature key: `catalog.edit`

Proposed write-route inventory (from `routes/web.php`):

### Product Categories
- `POST /admin/product-categories` (`admin.product-categories.store`)
- `PUT /admin/product-categories/{productCategory}` (`admin.product-categories.update`)
- `DELETE /admin/product-categories/{productCategory}` (`admin.product-categories.destroy`)

### Products
- `POST /admin/products` (`admin.products.store`)
- `PUT /admin/products/{product}` (`admin.products.update`)
- `DELETE /admin/products/{product}` (`admin.products.destroy`)

### Product-Adjacent Write Actions
- `POST /admin/products/{product}/branch-pricing` (`admin.products.branch-pricing.update`)
- `DELETE /admin/products/{product}/branch-pricing/{branchPricing}` (`admin.products.branch-pricing.destroy`)
- `POST /admin/products/{product}/recipe` (`admin.products.recipe.update`)

Read/form routes in same modules (`index/create/edit`) are deferred to Slice B (`catalog.view`) unless explicitly approved as part of this slice.

---

## 2. Middleware Placement Proposal
Planning-only proposal:

- Wrap admin catalog write routes with `subscription.feature:catalog.edit` while retaining existing permission gates.
- Preferred structure:
  - keep `auth` + `tenant` outer context unchanged
  - add `subscription.feature:catalog.edit` at catalog admin route-group level, or dedicated write-only subgroup if read routes are split later
  - preserve current `permission:manage_products` on each write endpoint

Proposed sequencing:
1. Gate write endpoints listed above.
2. Validate no unexpected impact to read/list/edit form rendering endpoints.
3. Keep route-group rollback simple (single middleware removal point if grouped cleanly).

---

## 3. Permission Interaction Notes
Current permission model uses `permission:manage_products` for both read and write catalog admin surfaces.

Interaction expectations:
- Effective access should require BOTH:
  - role permission (`manage_products`)
  - feature entitlement (`catalog.edit`)
- This slice does not change permission definitions or role seeding.
- Denial precedence should remain fail-closed:
  - missing tenant context -> existing tenant middleware behavior
  - missing feature entitlement -> 403 feature gate denial
  - missing permission -> existing permission denial behavior

Potential caveat:
- If some operational roles have `manage_products` but should remain read-only by plan, feature gating will now enforce plan boundary without RBAC redesign.

---

## 4. Navigation Hide/Disable Behavior
Slice A recommendation (admin write surfaces):
- Hide write actions when `catalog.edit` is not entitled:
  - "Create Product"
  - "Edit Product"
  - "Delete Product"
  - category create/update/delete controls
  - branch pricing mutation controls
  - recipe update controls
- Keep any approved read-only catalog entry points visible (handled in Slice B).
- Avoid disabled controls for write actions unless product explicitly wants discoverability messaging.

---

## 5. Allow/Deny Test List
Planning test matrix for Slice A:

### Route Access (Feature x Permission)
- Deny: tenant without `catalog.edit` + has `manage_products` -> write endpoint returns 403.
- Allow: tenant with `catalog.edit` + has `manage_products` -> write endpoint allowed (status per endpoint behavior).
- Deny: tenant with `catalog.edit` + lacks `manage_products` -> permission denial retained.

### Endpoint Set
- product category store/update/destroy
- product store/update/destroy
- branch-pricing update/destroy
- product recipe update

### Safety/Regression
- Confirm read/list/edit form routes are not unintentionally blocked in Slice A.
- Confirm branch context is not required for these admin write endpoints unless explicitly introduced.

---

## 6. Rollback Notes
If Slice A implementation causes regression:
- Remove `subscription.feature:catalog.edit` from catalog write route grouping.
- Revert UI write-action visibility checks tied to `catalog.edit`.
- Re-run focused route gate suite and catalog admin feature tests.
- Record incident and residual risk note in Story 29.1A coverage map.

Rollback success criteria:
- Admin write flows return to pre-slice behavior.
- No impact on existing wave-1 hardened features.

---

## 7. Approval Gate
Slice A implementation may proceed only when all are true:
- [ ] Governance confirms Wave 2 code changes are now approved.
- [ ] Route inventory and middleware placement plan accepted.
- [ ] Permission interaction notes accepted (no RBAC redesign requested).
- [ ] Allow/deny test list accepted.
- [ ] Rollback plan accepted.
- [ ] Story 29.2 onboarding remains blocked pending Wave 2 review completion.

---

## 8. Decision Record
- Current decision: Slice A implementation approved and completed.
- Implementation state: Applied and locally validated.
- Boundary reaffirmed:
  - No onboarding progression yet.
  - No entitlement engine changes.

Validation evidence:
- `./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php`
- Result: 12 tests passing
