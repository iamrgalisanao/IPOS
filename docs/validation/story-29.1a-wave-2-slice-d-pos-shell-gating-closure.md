# Story 29.1A Wave 2 Slice D Closure - POS Shell Feature Gating

Date: 2026-05-25
Status: Implemented and Target-Locally Validated

## Scope

Close the previously deferred optional full POS shell gating follow-up by applying
`subscription.feature:sales.pos` to POS shell and supporting POS runtime routes.

## Completed

1. Added `subscription.feature:sales.pos` to the POS shell route group.
2. Added `subscription.feature:sales.pos` to the offline sync API route.
3. Updated authenticated navigation so POS Terminal is hidden when the tenant
   lacks the `sales.pos` entitlement.
4. Updated System Admin tenant provisioning feature coverage to compute live
   route-gate coverage from route middleware instead of static feature notes.
5. Added coverage test asserting `sales.pos` has non-zero route-gated coverage.

## Routes Now Covered

1. `GET /pos`
2. `GET /pos/search`
3. `GET /pos/active-shift`
4. `GET /pos/bootstrap-cache`
5. `POST /api/pos/offline-sync`

Checkout and payment routes remain covered by the prior Slice C closure.

## Validation

Commands run:

```bash
php artisan test tests/Feature/Subscription/RouteFeatureGateTest.php tests/Feature/SystemAdmin/TenantProvisioningTest.php
npm run build
```

Result:

1. Route and provisioning feature tests: 36 passed / 160 assertions.
2. Frontend production build: passed.

## Boundaries Preserved

1. No subscription engine rebuild.
2. No billing automation.
3. No POS checkout behavior changes.
4. No offline sync/posting behavior changes beyond feature entitlement gating.
5. No tax, Z-read, GCT, e-journal, receipt, or accounting engine changes.
6. No broad pilot enablement or BIR certification claim.

## Decision

Slice D is accepted as implemented and target-locally validated. Story 29.1A
feature-gate enforcement coverage hardening is now closed for the planned Wave 2
surface.
