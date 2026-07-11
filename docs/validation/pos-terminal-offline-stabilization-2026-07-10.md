# POS Terminal Offline Stabilization Validation Note

Date: 2026-07-10
Status: Implemented and Locally Validated
System Area: POS Terminal, Controlled Offline Sales, Timecards, Terminal Identity

## Summary

This note records the July 2026 stabilization pass performed after live POS
terminal offline testing. The work did not change the Epic 28 compliance
boundary: terminal-side offline sales remain provisional, and official posting
still happens only through server-side reconciliation.

## Changes Confirmed

1. Offline `Ready to Complete` opens the Split Payment Wizard instead of
   bypassing cashier payment review.
2. Offline provisional capture is cash-only. Card, e-wallet, bank transfer, and
   other tenders stay disabled until connectivity returns.
3. Empty abandoned split rows no longer block a fully paid cash transaction.
4. Cart controls were hardened so item removal and footer checkout actions stay
   reachable on tablet layouts.
5. The POS shell service-worker cache was rolled to
   `ipos-terminal-shell-v22-20260708`.
6. Terminal identity binding now remains enforced for timecard-bound flows in
   test/runtime contexts that require timecards, preventing terminal-less
   timecard records.

## 2026-07-11 Stabilization Addendum

Additional cashier-led offline testing found reconnect and queue visibility
edge cases after using **Check Connection**, refreshing the POS shell, and
retrying queue sync.

Changes confirmed:

1. Expected `401/419` and network reachability failures during reconnect are
   handled as offline/session state instead of hard console errors.
2. Local sync broker discovery falls back to **Local Sync Offline** when broker
   endpoints are unavailable or unauthenticated.
3. Product search rejects non-JSON responses before parsing and falls back to
   cached catalog results when an HTML login/error page is returned.
4. Offline payment records tied to `offline-draft-*` sale identifiers are not
   submitted to the server payment endpoint; offline draft completion uses the
   offline sale capture queue instead.
5. Retryable sync failures and review-required conflicts are separated in the
   cashier UI so sequence conflicts are not retried as ordinary network
   failures.
6. POS shell service-worker cache was rolled forward to
   `ipos-terminal-shell-v31-20260711`.
7. Current production build asset for the POS terminal page is
   `Index-Ba8-w-pW.js`.

## Validation Evidence

- `node --test tests/Frontend/offlineQueueSync.test.js tests/Frontend/splitPaymentHelper.test.mjs tests/Frontend/splitPaymentFailureState.test.mjs tests/Frontend/checkoutUncertaintyState.test.mjs tests/Frontend/cartDraftStorage.test.js tests/Frontend/connectivityStore.test.mjs tests/Frontend/catalogCache.test.js`
  - Result: 38 passed
- `php artisan test tests/Feature/POS/TimecardControllerTest.php`
  - Result: 14 passed / 53 assertions
- `php artisan test tests/Feature/POS/TerminalIdentityBindingTest.php`
  - Result: 7 passed / 8 assertions
- `php artisan test tests/Feature/POS/OfflineSalesAuditPayloadTest.php tests/Feature/POS/OfflineSyncValidationTest.php tests/Feature/POS/OfflineSyncIdempotencyTest.php tests/Feature/POS/TimecardControllerTest.php tests/Feature/POS/PaymentRecordingTest.php tests/Feature/POS/SplitPaymentRecordingTest.php`
  - Result: 70 passed / 206 assertions
- `php artisan test tests/Feature/POS/CheckoutFailureTest.php tests/Feature/POS/CheckoutStatusRecoveryTest.php tests/Feature/POS/CheckoutValidationTest.php tests/Feature/POS/OfflineBootstrapCacheTest.php`
  - Result: 39 passed / 150 assertions
- `npm run build`
- `node --check public/sw.js`
- `node tests/Frontend/catalogCache.test.js`
  - Result: passed
- `node tests/Frontend/offlineQueueSync.test.js`
  - Result: passed
- `node tests/Frontend/offlinePaymentQueue.test.js`
  - Result: passed
- `node tests/Frontend/connectivityStore.test.mjs`
  - Result: passed
- `npm run build`
  - Result: passed

## Locked Boundary

1. Browser IndexedDB/local queue is provisional only.
2. No local official GCT, Z-read, or e-journal finalization is introduced.
3. No BIR-certified offline receipt claim is introduced.
4. Server reconciliation remains authoritative for final posting.

## Follow-Ups

1. Continue monitoring retry sync failures by terminal and branch.
2. Confirm production tablet deployments receive the latest service-worker
   cache version.
3. Review hardware adapter readiness before pilot claims involving receipt
   printing or cash drawer hardware.
4. Run the 2026-07-11 UAT checklist before early partner pilot rollout:
   [pos-terminal-offline-uat-2026-07-11.md](pos-terminal-offline-uat-2026-07-11.md).
5. Execute the POS terminal hardening pass before broader rollout:
   [_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md](../../_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md).
