# Story 29.1A — Wave 2 Slice C sales.pos Checkout Gating Closure

**Date:** 2026-05-21  
**Status:** Implemented & Locally Validated

---

## Scope

Slice C implements checkout-only `sales.pos` subscription gating. The implementation applies route-level entitlement enforcement only to checkout-sensitive POS routes and preserves the full POS shell and shared runtime routes.

## Implemented

Added `subscription.feature:sales.pos` to the existing checkout-sensitive web route group while preserving:

- `branch`
- `permission:create_sale`

Gated routes:

- `POST /pos/checkout/validate`
- `POST /pos/checkout/create-sale`
- `POST /pos/checkout/status`
- `GET /pos/sales/{sale_id}/receipt`
- `POST /pos/sales/{sale_id}/payments`
- `POST /pos/sales/{sale_id}/payments/split`

Explicitly unchanged:

- `GET /pos`
- `GET /pos/search`
- `GET /pos/active-shift`
- `GET /pos/layout`
- `POST /pos/unlock`
- `GET /api/pos/bootstrap-cache`
- `POST /pos/offline-sync`

## Validation

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php
```

Result:

```md
25 tests / 63 assertions passing
```

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/POS/CheckoutValidationTest.php tests/Feature/POS/CheckoutFlowTest.php tests/Feature/POS/CheckoutStatusRecoveryTest.php tests/Feature/POS/PaymentRecordingTest.php tests/Feature/POS/SplitPaymentRecordingTest.php tests/Feature/POS/PaymentFailureTest.php
```

Result:

```md
72 tests / 231 assertions passing
```

## Governance Note

Slice C is a route-level subscription gate only. It does not change POS shell access, product search, active shift lookup, offline sync/posting, payment logic, receipt rendering, sale creation internals, tax calculations, inventory deduction, GCT, Z-read, e-journal, or subscription billing behavior.

## Remaining Deferred Feature-Gate Work

- Optional full POS shell gating, pending explicit future approval.
