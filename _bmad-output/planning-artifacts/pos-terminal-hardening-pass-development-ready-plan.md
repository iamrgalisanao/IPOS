---
project: IPOS
artifact: POS Terminal Hardening Pass Development-Ready Plan
date: 2026-07-11
status: Development Checkpointed; UAT Release Gate Pending
owner: Product Management
sourceDocuments:
  - docs/roadmap/validated-implementation-roadmap.md
  - docs/validation/pos-terminal-offline-stabilization-2026-07-10.md
  - docs/validation/pos-terminal-offline-uat-2026-07-11.md
  - docs/validation/epic-28-phase-2-controlled-offline-sales-closure-report.md
  - docs/validation/epic-28-phase-2-pilot-runbook.md
  - _bmad-output/planning-artifacts/story-28.11-epic-28-phase-2-slice-g-scope-lock.md
  - _bmad-output/planning-artifacts/story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md
---

# POS Terminal Hardening Pass Development-Ready Plan

## Product Decision

The POS terminal is classified as **controlled early partner pilot ready after UAT**, not full production hardened.

This hardening pass prepares the terminal for safer pilot and production-readiness review by closing operational UX, route hardening, support recovery, and validation gaps found after July 2026 cashier-led offline testing.

## Locked Compliance Boundary

This hardening pass must not introduce:

- local official GCT finalization
- local official Z-read finalization
- local official e-journal finalization
- BIR-certified offline receipt claims
- client-side totals as official truth
- uncontrolled offline sales outside tenant, branch, and terminal enablement

Offline terminal records remain provisional claims until server-side reconciliation and posting complete.

## Primary User Outcomes

1. Cashiers can clearly understand whether they are online, offline, queued, failed, or in review-required state.
2. Cashiers can continue eligible cash-only offline sales without hidden controls, stuck buttons, or unclear disabled states.
3. Admins can review and resolve offline sync conflicts from a usable surface, not only from raw APIs.
4. Support can diagnose terminal queue, service-worker, route, and hardware readiness issues without destructive local cleanup.
5. Product and QA can complete UAT with repeatable evidence before pilot expansion.

## Development Slices

### Story PTH-1: Terminal Route Surface Hardening

**Goal:** Replace or remove stubbed terminal routes so every visible terminal route has an intentional behavior.

**Problem:** `/pos/terminal/shift`, `/pos/terminal/sync-status`, and `/pos/terminal/settings` are currently routed back to checkout as future stubs. This creates false product surface and makes support/debugging ambiguous.

**In Scope:**

- Decide per route whether it is implemented, redirected, or intentionally unavailable.
- Implement minimum viable terminal screens where product intent is clear:
  - shift screen: current shift, open/close shift entry points, timecard state
  - sync-status screen: queue state, retry, conflict/review messaging
  - settings screen: terminal identity, service-worker version, offline capability, hardware adapter state
- Ensure each route keeps `auth`, `tenant`, `branch`, `terminal`, and `subscription.feature:sales.pos` protection.
- Add empty/error states for missing terminal context.

**Out of Scope:**

- New offline posting engine behavior.
- New admin conflict approval mutations.
- Hardware driver implementation beyond status display.

**Acceptance Criteria:**

1. No `/pos/terminal/*` route silently renders checkout unless it is an explicit redirect with user-visible intent.
2. Each terminal route has a stable title, primary state, and recovery path.
3. Terminal middleware remains enforced on all tablet production routes.
4. Deep-linking to each terminal route works after login and branch/terminal context resolution.
5. Feature tests cover route access for valid terminal, missing terminal, and unauthorized user.

**Validation:**

- Feature tests for terminal route access and middleware enforcement.
- Frontend smoke test for route rendering.
- Manual tablet check at primary landscape viewport.

### Story PTH-2: Offline Sync Admin Review UX

**Goal:** Give authorized admins a usable UI to inspect, review, hold, override-approve, and post offline imports that require review.

**Problem:** Backend review APIs exist, and the cashier queue can show review/conflict states, but the operational admin path is incomplete for real support use.

**In Scope:**

- Add or complete Admin offline sync/import review page.
- List imports with filters:
  - pending/server verified
  - conflict/rejected
  - hold
  - override approved
  - posted/duplicate
  - terminal, branch, batch reference, sequence
- Detail panel shows:
  - offline sequence number
  - local transaction reference
  - terminal profile
  - submitted totals
  - server recalculation
  - conflict notes
  - rejection reason
  - review notes/history
- Authorized actions:
  - mark hold
  - mark override approved
  - post eligible import
- Permission gate: `review_offline_sync_conflicts`.
- Link or copyable identifiers from cashier queue to admin review workflow.

**Out of Scope:**

- Allowing cashier to override conflicts.
- Trusting client totals over server recalculation.
- Changing import posting rules.

**Acceptance Criteria:**

1. Admin can find a conflict using terminal, sequence, or batch reference from the POS queue.
2. Admin can see both client payload and server recalculation before taking action.
3. Review actions are permission-gated and audit-stamped.
4. Posted imports surface the official sale identifier after successful posting.
5. Cashier-facing conflict text tells the cashier escalation is required and preserves the affected reference.

**Validation:**

- Feature tests for RBAC on list/detail/review/post endpoints.
- Frontend tests or smoke test for list/detail/action flow.
- UAT conflict scenario using a sequence-out-of-order record.

### Story PTH-3: Offline Queue Diagnostics, Retention, and Support Recovery

**Goal:** Make local terminal queue state supportable without unsafe IndexedDB manipulation.

**Problem:** The local queue has hash-chain diagnostics and statuses, but there is no clear retention, export, or support recovery policy for long-lived synced/conflict records.

**In Scope:**

- Add support-facing queue diagnostics in terminal sync status:
  - IndexedDB enabled/disabled
  - queue DB version
  - service-worker version
  - last sync attempt
  - last sync success
  - active pending/failed/conflict counts
  - historical synced count
- Add export diagnostic bundle action for authorized support/admin users:
  - queue summary
  - selected transaction metadata
  - hash-chain verification result
  - browser/user-agent and terminal identifiers
- Define retention behavior for local synced records:
  - keep by default for a defined local retention window
  - allow safe pruning of synced/cancelled records only
  - never prune pending, syncing, failed, conflict, or accepted-with-warning records from cashier UI
- Show clear “admin review required” for terminal conflicts that cannot be retried.

**Out of Scope:**

- Editing queued payloads.
- Clearing unresolved records.
- Bypassing server sequence validation.

**Acceptance Criteria:**

1. Support can export a diagnostic bundle without exposing secrets or full payment sensitive data.
2. Hash-chain verification result is visible or included in the diagnostic bundle.
3. Synced/cancelled pruning cannot remove unresolved records.
4. Retry buttons are disabled for review-only conflict records and explain why.
5. Queue counts match active queue drawer records after refresh.

**Validation:**

- Frontend tests for summary counts, conflict classification, and pruning guards.
- Manual test with pending, synced, failed, and conflict records.

### Story PTH-4: POS Route and Session Hardening

**Goal:** Eliminate ambiguous access paths and reduce reconnect/session failure confusion.

**Problem:** `/pos/terminal/checkout` is terminal protected, but legacy `/pos` remains available under lighter middleware. Reconnect can still expose expected 401/419/session states as cashier confusion if not presented consistently.

**In Scope:**

- Decide whether `/pos` should remain a legacy route, redirect to `/pos/terminal/checkout`, or enforce terminal middleware in production mode.
- Ensure all checkout, sync, search, timecard, and payment flows consistently classify:
  - network unreachable
  - unauthenticated/stale session
  - unauthorized permission
  - terminal context missing
- Add user-facing recovery messaging for stale session and terminal mismatch.
- Add regression tests for `/pos`, `/pos/terminal/checkout`, `/pos/search`, `/pos/offline-sync`, and payment split access boundaries.

**Out of Scope:**

- Reworking authentication.
- Changing cashier permission model.

**Acceptance Criteria:**

1. Production tablet route is the canonical terminal route.
2. Legacy `/pos` behavior is explicitly documented and tested.
3. 401/419 reconnect failures do not look like generic network failures to the cashier.
4. Terminal missing/mismatch state fails closed and gives support-actionable messaging.
5. Route-level tests prove terminal middleware cannot be bypassed for production terminal checkout.

**Validation:**

- Feature tests for route middleware matrix.
- Frontend reconnect/session tests.
- Manual stale-session reconnect test.

### Story PTH-5: Hardware and Provisional Receipt Readiness Review

**Goal:** Prepare hardware and receipt readiness for pilot claims without making BIR-certified offline receipt claims.

**Current Status:** Deferred/blocked for physical validation because receipt printer and cash drawer hardware are not yet available.

**Problem:** Hardware adapter readiness is still listed as a follow-up, and the provider defaults to a no-op adapter unless initialized.

**In Scope:**

- Add terminal settings/status display for active hardware adapter:
  - no-op
  - browser print
  - unavailable/error
- Add readiness checks for receipt printer and cash drawer where supported.
- Document and display whether receipt printing is available for:
  - online official receipt
  - offline provisional receipt
- Ensure offline receipt wording remains provisional and non-certified if rendered.
- Add failure states for printer unavailable and cash drawer unavailable.

**Out of Scope:**

- Native hardware driver development.
- BIR-certified offline receipt wording.
- Local official ledger finalization.

**Acceptance Criteria:**

1. Terminal settings show current hardware adapter and readiness.
2. Hardware unavailable does not block eligible cash-only offline capture unless product explicitly requires printed provisional receipt.
3. Offline receipt copy clearly says provisional/pending sync and not official final posting.
4. Hardware status is included in support diagnostics.

**Validation:**

- Frontend smoke test for no-op and browser print adapter states.
- Manual receipt/cash drawer test on pilot hardware once devices are available.
- Compliance review of provisional wording before pilot expansion.

### Story PTH-6: Offline UAT Execution and Release Gate

**Goal:** Convert the existing UAT checklist from ready-to-test into signed evidence for pilot expansion.

**Problem:** The UAT checklist exists but is not executed or signed off.

**In Scope:**

- Run all current UAT cases from `docs/validation/pos-terminal-offline-uat-2026-07-11.md`.
- Add cases for this hardening pass:
  - terminal subroutes
  - admin conflict review
  - queue diagnostic export
  - legacy route behavior
  - hardware adapter status
- Capture screenshots and console excerpts for pass/fail evidence.
- Record sign-off from cashier tester, branch/admin reviewer, and support/QA reviewer.
- Update stabilization note with final hardening validation evidence.

**Out of Scope:**

- Changing UAT criteria during execution without PM approval.
- Declaring production readiness with open high-severity failures.

**Acceptance Criteria:**

1. All required UAT rows have Pass/Fail/Blocked/N/A result.
2. No critical or high-severity UAT failure remains unresolved for pilot expansion.
3. Evidence bundle includes offline, reconnect, sync, conflict, and refresh scenarios.
4. Sign-off table is complete.
5. Roadmap status is updated with the hardening pass result.

**Validation:**

- Completed UAT checklist.
- Linked evidence artifacts.
- PM/QA release gate note.

## Cross-Cutting Non-Functional Requirements

- **Reliability:** No cashier-facing control should remain indefinitely loading after offline capture, retry, or reconnect failure.
- **Recoverability:** Every blocked terminal state must have a visible recovery path or escalation reference.
- **Security:** Terminal identity and cashier permissions must fail closed.
- **Auditability:** Offline queue records remain immutable; review and posting actions are audit-stamped.
- **Usability:** Important cart, checkout, queue, and remove-item controls must remain visible at tablet landscape viewport.
- **Compliance:** Offline sales remain provisional until server reconciliation and posting.

## Development Readiness Checklist

- [x] Source gaps identified from code, roadmap, validation note, and UAT.
- [x] Compliance boundary restated.
- [x] Stories decomposed into independently shippable slices.
- [x] Acceptance criteria defined per story.
- [x] Validation plan defined per story.
- [x] PTH-1 terminal route surface implemented for shift, sync status, and settings routes.
- [x] PTH-4 route/session hardening started with dedicated tablet routes and terminal middleware regression coverage.
- [x] PTH-3 queue diagnostics started with support-safe export bundle and resolved-record pruning guard.
- [x] PTH-2 admin offline sync review UX started with searchable review console and import detail/action workflow.
- [x] PTH-4 route/session hardening continued with canonical `/pos` redirect, terminal-bound POS operational/API routes, and cashier-facing session/terminal error copy.
- [x] POS terminal hardening checkpoint committed as `6c2b5d0` with repository clean-slate cleanup completed.
- [ ] PTH-5 physical printer/drawer validation deferred until hardware is available.
- [ ] Engineering estimates assigned.
- [ ] Story owners assigned.
- [ ] UAT execution window scheduled.
- [ ] Pilot branch/terminal selected for final hardening validation.

## Implementation Notes

### 2026-07-11 Slice 1

Implemented the first hardening slice:

- `/pos/terminal/shift` now renders a dedicated terminal shift status page.
- `/pos/terminal/sync-status` now renders a dedicated read-only sync guidance page.
- `/pos/terminal/settings` now renders a dedicated terminal settings/readiness page.
- Tablet header navigation now exposes Checkout, Shift, Sync, and Settings.
- Terminal middleware coverage was extended to all terminal surfaces.

Validation:

- `php -l app/Http/Controllers/CheckoutController.php`
- `php -l routes/web.php`
- `php -l tests/Feature/POS/TerminalIdentityBindingTest.php`
- `php artisan test tests/Feature/POS/TerminalIdentityBindingTest.php` - 11 passed / 57 assertions
- `npm run build`

### 2026-07-11 Slice 2

Implemented the first queue diagnostics hardening slice:

- Offline sales queue now exposes a support-safe diagnostics bundle with storage status, DB version, summary counts, hash-chain verification, and sanitized transaction metadata.
- Offline sales queue now has a guarded resolved-record pruning method that can remove old synced/cancelled records only.
- Pending, syncing, failed, conflict, and accepted-with-warning records remain non-prunable from the queue service.
- POS queue drawer now includes an **Export** action for a JSON diagnostics bundle, including sanitized sales queue, payment queue, browser, and terminal queue state.
- POS queue drawer now surfaces support diagnostics for IndexedDB, queue DB version, hash-chain verification, and stored record count.

Validation:

- `node --test tests/Frontend/offlineQueueSync.test.js` - 9 passed
- `npm run build`

### 2026-07-11 Slice 3

Implemented the first admin offline sync review UX slice:

- Terminal Sync Monitor now includes an **Offline Import Review Console** for conflict, hold, override-approved, server-verified, rejected, posted, or all statuses.
- Admins can search review records by offline sequence, batch reference, payload hash, terminal profile code, terminal identifier, or machine identification number.
- Admins can open a review modal showing terminal/branch identifiers, local reference, client total, server total, conflict/rejection notes, client payload, and server recalculation.
- Review modal exposes permission-gated API actions already protected by `review_offline_sync_conflicts`: hold, return conflict, override approve, and post eligible import.
- Offline import API list payload now returns summarized branch, terminal, batch, totals, local reference, review, and reconciled sale metadata for the review UI.

Validation:

- `php -l app/Http/Controllers/Admin/OfflineImportController.php`
- `php artisan test tests/Feature/Admin/OfflineImportReviewTest.php` - 14 passed / 38 assertions
- `npm run build`

### 2026-07-11 Slice 4

Implemented the next route/session hardening slice:

- Legacy `/pos` now redirects to `/pos/terminal/checkout` instead of rendering a second POS shell.
- POS operational web endpoints now require verified terminal context:
  - product search
  - active shift
  - layout
  - unlock
  - timecard status
  - web offline sync
- POS API sync/diagnostic endpoints now require verified terminal context before accepting sync, sandbox, submission lookup, drawer, discount, or manager authorization calls.
- Checkout failure copy now distinguishes stale session (`401`/`419`), terminal context failure, timecard required, permission failure, validation failure, and generic failures.
- Terminal binding tests now cover legacy route behavior, POS operational route middleware, API route middleware, and product search terminal enforcement.

Validation:

- `php -l routes/web.php`
- `php -l routes/api.php`
- `php artisan test tests/Feature/POS/TerminalIdentityBindingTest.php` - 16 passed / 106 assertions
- `node tests/Frontend/checkoutFailureState.test.js`
- `npm run build`

### 2026-07-11 Slice 5

Implemented POS reconnect/session UX follow-through after route hardening:

- Added shared POS access issue classification for:
  - stale web/session responses (`401`/`419`)
  - terminal context failures (`TERMINAL_CONTEXT_INVALID`)
  - timecard-required failures (`TIMECARD_REQUIRED`)
- Added an in-flow POS access banner under the connectivity banner so session and terminal issues are visible without covering the cart or checkout controls.
- Added recovery actions:
  - stale session routes the cashier to sign in again
  - terminal context retries connectivity/context checks
  - clock-in requirement routes to the shift-open flow
- Sent verified POS terminal headers through remaining `fetch()` calls that previously bypassed axios defaults:
  - product search (`/pos/search`)
  - checkout status (`/pos/checkout/status`)
  - receipt fetch (`/pos/sales/{sale}/receipt`)
- Checkout validation and sale creation failures now also surface access issues through the POS banner instead of only showing generic submission failure copy.

Validation:

- `node tests/Frontend/checkoutFailureState.test.js`
- `npm run build`

### 2026-07-11 Slice 6

Checkpointed the POS terminal hardening baseline for UAT reference:

- Created clean repository checkpoint commit `6c2b5d0` (`chore: checkpoint POS terminal hardening`).
- Removed generated Electron `dist`/`node_modules`, local auth env, and `.DS_Store` noise from the working tree and ignore rules.
- Aligned roadmap, UAT, user guide, troubleshooting, current-focus, and task-ledger references around the POS terminal offline UAT/release gate.
- Explicitly deferred physical receipt printer and cash drawer validation until hardware is available.

Validation before checkpoint:

- `git diff --check`
- `npm run build`
- `node tests/Frontend/checkoutFailureState.test.js`
- `node tests/Frontend/catalogCache.test.js`
- `node tests/Frontend/offlineQueueSync.test.js`
- `node tests/Frontend/offlinePaymentQueue.test.js`
- `node tests/Frontend/connectivityStore.test.mjs`

## Recommended Build Order

1. PTH-4 Route and session hardening.
2. PTH-1 Terminal route surface hardening.
3. PTH-3 Queue diagnostics and retention.
4. PTH-2 Admin offline sync review UX.
5. PTH-6 UAT execution and release gate without hardware-dependent pass claims.
6. PTH-5 Hardware and provisional receipt readiness after printer/drawer devices are available.

## Release Gate

This hardening pass may be marked complete only when:

1. PTH-1 through PTH-4 pass targeted automated validation, and PTH-5 is either hardware-validated or explicitly marked deferred for the selected pilot scope.
2. PTH-6 UAT is executed and signed.
3. No high-severity offline checkout, route access, sync, or conflict-review defect remains open.
4. Product explicitly confirms whether the resulting status is:
   - pilot expansion ready, or
   - production readiness candidate, or
   - blocked pending compliance/hardware review.
