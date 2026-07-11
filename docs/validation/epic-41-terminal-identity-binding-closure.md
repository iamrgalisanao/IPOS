# Epic 41 Residual Hardening — Terminal Identity Binding Closure

## 1. Closure Summary

**Date:** 2026-07-08
**Task ID:** G-079
**Status:** Implemented & Locally Validated

The terminal identity binding gap for `/pos/terminal/checkout` has been
hardened. The tablet POS shell entry route now requires a verified terminal
identity via the `terminal` middleware, and the unsafe first-active-profile
fallback has been removed from `POSController::index()`.

## 2. Problem Addressed

Before this change:

1. `/pos/terminal/checkout` did not enforce terminal identity at page-entry time.
2. `POSController::index()` implicitly selected the first active
   `SalesMachineProfile` for the current branch.
3. A branch with multiple active terminal profiles could render the tablet shell
   while attaching to the wrong terminal identity.
4. Physical pilot validation on multi-terminal branches was less trustworthy.

## 3. Changes Made

### 3.1 Route Middleware Hardening

**File:** `routes/web.php`

Added `terminal` middleware to the `/pos/terminal/*` route group so terminal
identity is verified before the shell renders.

### 3.2 Controller Terminal Resolution

**File:** `app/Http/Controllers/POSController.php`

Removed the unsafe first-active-profile fallback. Terminal identity is now
resolved from:

1. The `terminal_profile` request attribute set by `IdentifyTerminalContext`
   middleware.
2. An explicit `X-Terminal-ID` header or `test_terminal_id` query parameter,
   validated against the current tenant and branch.

No implicit branch-level terminal selection occurs.

### 3.3 Middleware Testing Guard

**File:** `app/Http/Middleware/IdentifyTerminalContext.php`

Added `config('app.enforce_terminal_binding')` as an independent testing guard
so terminal shell route tests can exercise fail-closed behavior without forcing
timecard enforcement on every test.

### 3.4 Focused Test Coverage

**File:** `tests/Feature/POS/TerminalIdentityBindingTest.php`

7 tests covering:

1. Valid terminal shell access renders checkout (200).
2. Missing terminal context rejects shell entry (403).
3. Invalid terminal ID rejects shell entry (403).
4. Cross-branch terminal rejects shell entry (403).
5. Cross-tenant terminal rejects shell entry (403).
6. Terminal identifier string (not just UUID) resolves correctly (200).
7. `terminal` middleware is applied to the `pos.terminal.checkout` route.

## 4. Validation Evidence

### Focused Suite

```
TerminalIdentityBindingTest: 7 passed / 8 assertions
```

### Regression Sweep

```
tests/Feature/POS/ + ShiftDashboardIntegrationTest:
  328 passed / 1188 assertions / 0 failures

PosLayoutTerminalTest: 8 passed / 17 assertions
TimecardControllerTest + RouteFeatureGateTest: 41 passed / 124 assertions
```

## 5. Boundary Preservation

This slice did not change:

1. Receipt, tax, accounting, settlement, or BIR behavior.
2. Hardware adapter integration or ESC/POS device support.
3. Offline queue engine or shift lifecycle.
4. Timecard PIN flow.
5. Inertia-based POS reuse model or checkout business logic.

## 6. Acceptance Criteria Verification

| Criterion | Status |
|:---|:---|
| `/pos/terminal/checkout` no longer falls back to first active branch terminal | ✅ |
| Valid terminal identity explicitly resolved before shell render | ✅ |
| Invalid or ambiguous terminal context returns fail-closed response | ✅ |
| Tenant and branch ownership checks preserved | ✅ |
| Downstream checkout and offline-sync terminal assumptions compatible | ✅ |
| Focused tests cover valid access, missing, invalid, cross-branch, cross-tenant | ✅ |

## 7. Next Steps

1. Update task ledger G-079 status to closed.
2. Next Epic 41 residual reviews:
   - Hardware adapter integration into receipt/drawer behavior
   - Local active-shift recovery completeness
   - Tablet-specific route surface cleanup (`shift`, `sync-status`, `settings`)
