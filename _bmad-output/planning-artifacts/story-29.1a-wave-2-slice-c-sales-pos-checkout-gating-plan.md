# Story 29.1A Wave 2 Slice C — sales.pos Checkout Gating Plan

Date: 2026-05-21  
Status: Implemented & Locally Validated  
Slice: C

---

## 1. Goal

Plan checkout-only feature gating for `sales.pos` without gating the full POS shell or changing checkout, payment, receipt, offline sync, or posting behavior.

This slice is intentionally narrower than full POS access control. It should prevent non-entitled tenants from completing checkout-sensitive operations while keeping low-risk POS screen loading and lookup routes unchanged until a later explicit decision.

---

## 2. In Scope

Target feature key: `sales.pos`

Proposed checkout-sensitive web routes from `routes/web.php`:

- `POST /pos/checkout/validate` (`pos.checkout.validate`)
- `POST /pos/checkout/create-sale` (`pos.checkout.create-sale`)
- `POST /pos/checkout/status` (`pos.checkout.status`)
- `GET /pos/sales/{sale_id}/receipt` (`pos.sales.receipt`)
- `POST /pos/sales/{sale_id}/payments` (`pos.sales.payments`)
- `POST /pos/sales/{sale_id}/payments/split` (`pos.sales.payments.split`)

Current existing guards to preserve:

- `branch`
- `permission:create_sale`

Proposed added guard:

- `subscription.feature:sales.pos`

---

## 3. Out of Scope

- Full POS shell gating.
- Product search gating.
- POS active-shift status gating.
- POS unlock gating.
- POS layout gating changes.
- POS bootstrap cache gating.
- Offline sync/posting changes.
- Receipt engine changes.
- Payment logic changes.
- Sale creation, tax, inventory, GCT, Z-read, e-journal, or immutability logic changes.
- Subscription engine or billing behavior changes.

Explicitly unchanged routes in this slice:

- `GET /pos` (`pos.index`)
- `GET /pos/search` (`pos.search`)
- `GET /pos/active-shift` (`pos.active-shift`)
- `GET /pos/layout` (`pos.layout`, already gated by `layout.custom`)
- `POST /pos/unlock` (`pos.unlock`)
- `GET /api/pos/bootstrap-cache` (`pos.bootstrap-cache`)
- `POST /pos/offline-sync` (`pos.offline-sync`, API route)

---

## 4. Middleware Placement Proposal

Preferred implementation:

```php
Route::middleware(['branch', 'permission:create_sale', 'subscription.feature:sales.pos'])->group(function () {
    // checkout-sensitive routes only
});
```

Rationale:

- Keeps the existing branch and cashier permission checks intact.
- Applies entitlement at the smallest checkout-sensitive route group.
- Leaves POS shell and shared runtime dependencies available for future UX decisions.
- Makes rollback simple by removing one middleware entry from the checkout route group.

---

## 5. UX / Response Behavior

Expected denial behavior:

- Web/Inertia or JSON requests should receive the existing standardized subscription 403 response from `EnforceSubscriptionGate`.
- No new UI copy is required in this slice.
- Cashier-facing behavior may be handled by existing frontend error handling.

Recommended follow-up after implementation:

- Verify checkout UI displays a clear blocked/upgrade message when the API returns 403.
- Do not add new modal or shell-level gating until full POS shell gating is separately approved.

---

## 6. Test Plan

Add focused route gate tests to `tests/Feature/Subscription/RouteFeatureGateTest.php`.

### Deny Tests

- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot access checkout validation.
- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot create sale.
- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot check checkout status.
- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot retrieve receipt.
- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot record payment.
- Tenant with `sales.pos` disabled and cashier with `create_sale` cannot record split payment.

### Allow / Non-Regression Tests

- Tenant with `sales.pos` enabled and cashier with `create_sale` is not blocked by subscription gate on checkout-sensitive routes.
- Missing `create_sale` permission remains denied even when `sales.pos` is enabled.
- Branch context remains required.
- `GET /pos`, `GET /pos/search`, `GET /pos/active-shift`, and bootstrap cache remain unchanged.
- `POST /pos/offline-sync` remains unchanged in this slice.

### Fixture Notes

- Use a basic tenant with custom override `features.sales.pos = false` to test denial without changing configured tier defaults.
- Use an entitled basic tenant for allow-path checks because `sales.pos` is included in the basic tier.
- Use existing cashier/user/branch fixtures where possible.

---

## 7. Rollback Plan

If checkout gating causes regression:

1. Remove `subscription.feature:sales.pos` from the checkout-sensitive route group.
2. Re-run:
   - `php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php`
   - checkout/payment focused tests.
3. Record the regression in the coverage map and task ledger.
4. Reclassify the route or UX failure before attempting another rollout.

Rollback success criteria:

- Checkout routes return to pre-slice behavior.
- POS shell/search/bootstrap/offline sync remain unaffected.

---

## 8. Approval Gate

Implementation may proceed only after approval of:

- [x] Checkout-sensitive route inventory.
- [x] Middleware placement proposal.
- [x] Explicit out-of-scope boundaries.
- [x] Cashier denial response behavior.
- [x] Allow/deny test matrix.
- [x] Rollback plan.

Implementation result:

- Route-level checkout gate applied.
- Focused subscription and POS checkout/payment validation passed.
- Closure evidence recorded in `docs/validation/story-29.1a-wave-2-slice-c-sales-pos-checkout-gating-closure.md`.

---

## 9. Governance Boundary

Slice C is a route-level subscription gate only. It must not change POS sale creation internals, payment recording behavior, receipt rendering, offline sync/posting, tax calculations, inventory deduction, GCT, Z-read, e-journal, or subscription billing logic.
