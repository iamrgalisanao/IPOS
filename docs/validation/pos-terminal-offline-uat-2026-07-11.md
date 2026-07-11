# POS Terminal Offline Checkout and Sync UAT

Date: 2026-07-11  
Status: Ready for User Acceptance Testing  
System Area: POS Terminal, Controlled Offline Sales, Offline Sync Queue  
Primary Roles: Cashier, Owner/Admin, Support Team

## Purpose

This UAT checklist validates the current POS terminal behavior when the server
connection is interrupted during product selection, checkout, split payment,
offline capture, page refresh, reconnect, and queue synchronization.

Reference baseline: checkpoint commit `6c2b5d0` (`chore: checkpoint POS terminal
hardening`). Physical receipt printer and cash drawer devices are not available
for this UAT pass, so hardware-dependent cases are marked blocked/deferred and
must not be used as readiness claims.

## Preconditions

1. A tenant, branch, cashier, and terminal profile exist.
2. The cashier has `can sell` permission.
3. The terminal has a valid machine profile with controlled offline capture
   enabled.
4. The catalog has already been loaded online at least once so IndexedDB has a
   cached product/category snapshot.
5. The cashier is clocked in and the cashier shift is open where the test case
   requires it.
6. Browser console is available for observing bundle/service-worker state.
7. Current expected service-worker shell: `ipos-terminal-shell-v31-20260711`.
8. POS JavaScript bundle names are build-hashed. Confirm the current manifest
   and browser console are using the latest build instead of relying on a stale
   hard-coded bundle name.
9. Physical receipt printer and cash drawer hardware are not required for this
   pass. Hardware validation remains deferred until devices are available.

## UAT Result Legend

- Pass: Actual result matches expected result.
- Fail: Actual result does not match expected result.
- Blocked: Test cannot be completed because a prerequisite is unavailable.
- N/A: Test does not apply to the selected environment.

## Test Cases

| ID | Scenario | Steps | Expected Result | Result |
| --- | --- | --- | --- | --- |
| UAT-POS-OFF-001 | Online baseline checkout controls | Open POS while server is online, add one product, review cart. | Cart shows item, quantity controls, remove button, special discount action where applicable, and **Ready to Complete** button without overlap. |  |
| UAT-POS-OFF-002 | Offline catalog fallback | Load POS online once, stop the server, refresh or navigate categories. | POS shell remains available, products load from cached catalog, and console does not show a blocking product parse error. |  |
| UAT-POS-OFF-003 | Add item while offline | With server stopped, open a category and add an in-stock item. | Item appears in cart, subtotal/total update, and cart actions remain visible. |  |
| UAT-POS-OFF-004 | Offline split payment opens | With cart populated and server stopped, click **Ready to Complete**. | Split Payment Wizard opens in offline mode with cash selected and non-cash methods disabled. |  |
| UAT-POS-OFF-005 | Cash-only offline capture | Enter cash tendered equal to or above total, then click **Capture Offline Sale**. | Sale is captured locally, cart clears, success banner indicates provisional offline capture, and queue count increments. |  |
| UAT-POS-OFF-006 | Incomplete payment blocked | Open offline Split Payment Wizard, leave an extra payment row without a method or remove it. | Empty abandoned rows do not block a fully paid cash sale; incomplete active rows show a clear validation message. |  |
| UAT-POS-OFF-007 | Non-cash offline blocked | While offline, try GCash/card/bank/other payment method. | Non-cash method remains disabled or blocked with message that offline capture supports cash only. |  |
| UAT-POS-OFF-008 | Close payment wizard preserves cart | Add items, open Split Payment Wizard, close it before capture. | Cart remains intact and cashier can edit/remove items or reopen payment. |  |
| UAT-POS-OFF-009 | Page refresh while offline | Stop server, refresh POS main screen and payment screen after the shell was cached. | Browser does not show `ERR_CONNECTION_REFUSED` for the page shell; POS loads cached shell and offline state where available. |  |
| UAT-POS-OFF-010 | Check connection while unauthenticated/stale | With server restored but stale browser session, click **Check Connection**. | POS does not crash; expected 401/419 protected endpoint failures are handled as session/offline state and user can re-authenticate. |  |
| UAT-POS-OFF-011 | Product search receives HTML/login response | Expire session or force product search to return login HTML, then search category/products. | POS rejects non-JSON response, falls back to cached catalog, and does not show `Unexpected token '<'` as a blocking error. |  |
| UAT-POS-OFF-012 | Manual retry sync online | Restore server, confirm POS shows Online, click **Retry Sync** or **Sync Queue**. | Pending queue starts syncing. Accepted records leave pending state; failed records stay retryable; conflicts move to review. |  |
| UAT-POS-OFF-013 | Sequence conflict review | Attempt to sync records where an older sequence is missing or quarantined. | UI shows review/conflict state, not a generic retryable network failure. Message explains admin review is required. |  |
| UAT-POS-OFF-014 | Offline draft payment path | Capture an offline sale after opening the offline Split Payment Wizard. | No request is sent to `/pos/sales/offline-draft-*/payments/split`; sale is queued through offline sale capture. |  |
| UAT-POS-OFF-015 | Local sync broker unavailable | Reconnect with broker endpoint unavailable or unauthenticated. | Terminal shows **Local Sync Offline** and does not block checkout or cart actions. |  |
| UAT-POS-OFF-016 | Stale shell rollover | Deploy latest build, reconnect server, refresh terminal. | Browser installs `ipos-terminal-shell-v31-20260711`; console shows the current manifest/build asset, not older bundles. |  |
| UAT-POS-OFF-017 | Stale session access banner | Restore server after a stale/expired browser session and trigger checkout validation or product/status refresh. | POS shows a recoverable session/access banner instead of a generic sale failure; cashier can sign in again or refresh online. |  |
| UAT-POS-OFF-018 | Invalid terminal context banner | Trigger a request with missing or mismatched terminal context. | POS fails closed and shows terminal-context recovery guidance; sale/cart controls are not hidden behind the message. |  |
| UAT-POS-OFF-019 | Offline queue diagnostic visibility | Capture an offline sale, open **View Queue**, then return to checkout. | The local transaction reference and status are visible in queue diagnostics; returning to checkout does not clear unrelated queue history. |  |
| UAT-POS-OFF-020 | Hardware unavailable boundary | Run offline cash capture with no receipt printer or cash drawer connected. | Missing physical hardware does not block eligible cash-only offline capture; test notes clearly mark printer/drawer validation as deferred. |  |

## Acceptance Criteria

1. Cashiers can continue a cash sale while the server is unreachable when the
   terminal has a valid cached catalog and controlled offline capture profile.
2. The Split Payment Wizard remains part of the offline sale flow.
3. Offline provisional capture is cash-only.
4. Cart contents are not cleared when the cashier closes the payment wizard
   before capture.
5. POS shell refresh does not depend on live server response after the shell is
   cached.
6. Reconnect and product search failures are visible as recoverable terminal
   state, not uncaught application crashes.
7. Queue counts and statuses separate pending, failed, and review/conflict
   records.
8. Review-required sequence conflicts are escalated to admin review instead of
   retried indefinitely.
9. No official ledger posting, GCT finalization, Z-read finalization, or
   e-journal finalization happens locally in the browser.
10. Hardware printer and cash drawer behavior is not certified by this UAT pass;
    related checks remain blocked/deferred until physical devices are available.

## Evidence to Capture

1. Screenshot of offline banner and cached product listing.
2. Screenshot of Split Payment Wizard in offline cash-only mode.
3. Screenshot of successful offline capture banner and queue count increment.
4. Screenshot of **View Queue** showing the local transaction reference.
5. Screenshot of reconnect/sync result.
6. Browser console excerpt showing current bundle and absence of blocking
   uncaught errors.
7. Admin/support note for any sequence conflict or review-required record.
8. Note confirming hardware printer/drawer cases were blocked/deferred when no
   physical devices were available.

## Sign-Off

| Role | Name | Result | Date | Notes |
| --- | --- | --- | --- | --- |
| Cashier Tester |  |  |  |  |
| Branch/Admin Reviewer |  |  |  |  |
| Support/QA Reviewer |  |  |  |  |
