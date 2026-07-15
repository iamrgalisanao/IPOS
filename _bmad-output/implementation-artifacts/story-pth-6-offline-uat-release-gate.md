# Story PTH-6: POS Terminal Offline UAT Execution and Release Gate

Status: Approved for Execution
Date: 2026-07-14
Project: IPOS
System Area: POS Terminal, Controlled Offline Sales, Offline Sync Queue, Release Governance
Story Type: Validation / Release Gate

## 1. Objective

Convert the existing POS terminal offline UAT checklist from `Ready for User Acceptance Testing` into signed release-gate evidence for controlled early partner pilot expansion.

This story does not implement new POS behavior. It executes, records, and governs validation of the already checkpointed terminal hardening baseline.

## 2. Source References

1. `docs/validation/pos-terminal-offline-uat-2026-07-11.md`
2. `docs/validation/pos-terminal-offline-stabilization-2026-07-10.md`
3. `_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md`
4. `docs/validation/epic-28-phase-2-controlled-offline-sales-closure-report.md`
5. `docs/validation/epic-41-terminal-identity-binding-closure.md`
6. `docs/roadmap/validated-implementation-roadmap.md`
7. `.agents/rules/03-current-focus.md`
8. `docs/user-guide/04-module-guides/terminal-sync-monitor.md`
9. `docs/user-guide/05-common-errors-and-troubleshooting.md`

## 3. Current Baseline

Checkpoint:

```text
6c2b5d0 chore: checkpoint POS terminal hardening
```

Current roadmap status:

```text
Development Checkpointed; UAT Release Gate Pending
```

Current expected service-worker shell:

```text
ipos-terminal-shell-v31-20260711
```

Physical receipt printer and cash drawer devices are not available for this pass. Hardware-dependent validation remains blocked/deferred and must not be represented as physical hardware readiness.

## 3A. Execution Manifest

Each UAT execution report must record the exact environment under test.

Required manifest fields:

```text
Execution Date:
Execution Window:
Commit:
Branch:
Application Version:
Frontend Build Hash:
Service Worker Version:
API Version:
Database Snapshot:
Tenant:
Branch:
Terminal Profile:
Cashier User:
Browser:
Browser Version:
Operating System:
Device / Form Factor:
Network Mode:
Hardware Printer:
Cash Drawer:
Evidence Folder / Link:
```

Known baseline values for this story:

```text
Checkpoint Commit:
6c2b5d0

Expected Service Worker:
ipos-terminal-shell-v31-20260711

Hardware Printer:
Blocked / Deferred unless physical device is available

Cash Drawer:
Blocked / Deferred unless physical device is available
```

## 4. User Story

As a product, QA, and operations reviewer,
I want the offline POS terminal UAT checklist executed with captured evidence and sign-off,
so that IPOS can make a truthful pilot-readiness decision without overstating hardware or production readiness.

## 5. Scope

In scope:

1. Execute all UAT rows in `docs/validation/pos-terminal-offline-uat-2026-07-11.md`.
2. Mark each UAT row as `Pass`, `Fail`, `Blocked`, `N/A`, or `Not Executed`.
3. Capture screenshots and console excerpts for required evidence.
4. Record the exact environment used for the UAT pass.
5. Record sign-off from:
   - cashier tester
   - branch/admin reviewer
   - support/QA reviewer
6. Produce a release-gate result:
   - pilot expansion ready
   - blocked pending fixes
   - blocked pending hardware/compliance review
7. Update governance artifacts after review approval.

Out of scope:

1. Implementing new POS terminal features.
2. Changing UAT acceptance criteria during execution without explicit approval.
3. Claiming production readiness from this pass alone.
4. Claiming physical printer or cash drawer readiness without physical device evidence.
5. Certifying BIR offline receipt behavior.
6. Changing official posting, Z-read, GCT, e-journal, tax, settlement, or accounting behavior.

## 6. Execution Preconditions

Before UAT execution, confirm:

1. A tenant, branch, cashier, and terminal profile exist.
2. The cashier has `can sell` permission.
3. The cashier has a valid POS PIN where timecard flow is required.
4. The terminal has a valid sales machine profile.
5. Controlled offline capture is enabled for the selected terminal/branch.
6. Catalog data has been loaded online at least once.
7. The cashier is clocked in where required.
8. A cashier shift is open where required.
9. Browser console and dev tools are available.
10. The test operator knows how to stop and restore the local server/network.
11. Hardware printer and cash drawer availability is explicitly recorded.

## 6A. Result Status Taxonomy

Use these result values consistently:

1. `Pass` - actual result matches expected result.
2. `Fail` - actual result does not match expected result.
3. `Blocked` - prerequisite is unavailable, such as missing hardware, missing environment access, or missing required test data.
4. `N/A` - intentionally outside the approved scope for this execution pass.
5. `Not Executed` - skipped due to time, scheduling, or operator availability.

`Blocked` and `Not Executed` are not interchangeable. A blocked test needs prerequisite resolution. A not-executed test needs scheduling or execution follow-through.

## 6B. Severity Model

Every failed row must include severity:

1. `Critical` - release blocker. Data loss, duplicate sale risk, broken terminal identity, or official posting/compliance boundary violation.
2. `High` - pilot blocker. Cashier cannot complete eligible offline capture, queue state is unreliable, sync/review flow is unusable, or recovery path is missing.
3. `Medium` - pilot may proceed only with explicit approval and documented workaround.
4. `Low` - informational, cosmetic, or minor usability issue that does not affect pilot safety.

Failed rows must also include defect linkage:

```text
Status:
Fail

Severity:
High

Defect:
DEF-PTH-017

Owner:

Target Fix:

Notes:
```

## 7. Test Coverage

Execute the existing UAT rows:

1. `UAT-POS-OFF-001` Online baseline checkout controls
2. `UAT-POS-OFF-002` Offline catalog fallback
3. `UAT-POS-OFF-003` Add item while offline
4. `UAT-POS-OFF-004` Offline split payment opens
5. `UAT-POS-OFF-005` Cash-only offline capture
6. `UAT-POS-OFF-006` Incomplete payment blocked
7. `UAT-POS-OFF-007` Non-cash offline blocked
8. `UAT-POS-OFF-008` Close payment wizard preserves cart
9. `UAT-POS-OFF-009` Page refresh while offline
10. `UAT-POS-OFF-010` Check connection while unauthenticated/stale
11. `UAT-POS-OFF-011` Product search receives HTML/login response
12. `UAT-POS-OFF-012` Manual retry sync online
13. `UAT-POS-OFF-013` Sequence conflict review
14. `UAT-POS-OFF-014` Offline draft payment path
15. `UAT-POS-OFF-015` Local sync broker unavailable
16. `UAT-POS-OFF-016` Stale shell rollover
17. `UAT-POS-OFF-017` Stale session access banner
18. `UAT-POS-OFF-018` Invalid terminal context banner
19. `UAT-POS-OFF-019` Offline queue diagnostic visibility
20. `UAT-POS-OFF-020` Hardware unavailable boundary

Additional hardening checks from the development-ready plan:

1. Terminal subroutes render intentionally:
   - `/pos/terminal/checkout`
   - `/pos/terminal/shift`
   - `/pos/terminal/sync-status`
   - `/pos/terminal/settings`
2. Legacy `/pos` behavior is documented and tested as canonical redirect or equivalent approved behavior.
3. Admin offline import review console is reachable by authorized users.
4. Offline queue diagnostic export is available and support-safe.
5. Hardware adapter status is visible without claiming physical validation.

## 8. Evidence Requirements

Create a UAT evidence bundle containing:

1. Completed UAT checklist with results for every row.
2. Screenshot of offline banner and cached product listing.
3. Screenshot of Split Payment Wizard in offline cash-only mode.
4. Screenshot of successful offline capture banner and queue count increment.
5. Screenshot of View Queue showing local transaction reference.
6. Screenshot or note for reconnect/sync result.
7. Screenshot or note for review/conflict state.
8. Browser console excerpt showing:
   - current shell/cache version
   - current build manifest or bundle confirmation
   - no blocking uncaught errors for passing scenarios
9. Screenshot of terminal settings or diagnostics showing hardware adapter state.
10. Hardware validation note:
    - `Blocked/Deferred` if devices are unavailable
    - physical evidence only if devices are actually tested
11. Sign-off table with names, roles, result, date, and notes.

Evidence completion matrix:

| Evidence | Present | Notes |
| --- | :---: | --- |
| Execution manifest |  |  |
| Environment hashes |  |  |
| Screenshots |  |  |
| Console logs |  |  |
| Queue export |  |  |
| Diagnostics |  |  |
| Defect links |  |  |
| Sign-off |  |  |
| Hardware note |  |  |

Recommended evidence output path:

```text
docs/validation/pos-terminal-offline-uat-execution-2026-07-14.md
```

If screenshots are captured as files, store them under a dated folder such as:

```text
docs/validation/evidence/pos-terminal-offline-uat-2026-07-14/
```

## 9. Acceptance Criteria

1. Every required UAT row has one of: `Pass`, `Fail`, `Blocked`, `N/A`, or `Not Executed`.
2. Every `Fail` includes severity, reproduction notes, and owner/next action.
3. No critical or high-severity failure remains unresolved for pilot expansion.
4. Hardware-dependent checks are explicitly marked blocked/deferred when devices are unavailable.
5. Offline capture remains cash-only and provisional.
6. No local official GCT, Z-read, e-journal, or BIR-certified offline receipt claim is made.
7. Queue and sync evidence proves pending, failed, and review/conflict states are distinguishable.
8. Stale session and invalid terminal context states show recoverable, user-facing guidance.
9. Evidence bundle includes all required screenshots/console excerpts or written notes explaining why a capture is unavailable.
10. Cashier, branch/admin, and support/QA sign-off rows are complete.
11. Release-gate decision is explicitly recorded.
12. Roadmap/current-focus/task-ledger are updated only after the UAT result is approved.
13. Execution manifest and environment hashes are recorded.
14. Evidence completion matrix is complete.

## 10. Release-Gate Decision Rules

Pilot expansion ready:

1. All required non-hardware cases pass or have approved non-blocking exceptions.
2. No critical or high-severity defect remains open.
3. Hardware cases are marked deferred when devices are unavailable.
4. Product/QA signs off that the status is pilot-ready, not full production-certified.

Blocked pending fixes:

1. Any critical/high offline checkout, sync, session, route, or terminal identity failure remains open.
2. Cashier cannot complete eligible cash-only offline capture.
3. Queue records can be lost, hidden, or duplicated.
4. Stale session or terminal context failures hide cart/queue controls without recovery guidance.

Blocked pending hardware/compliance review:

1. Pilot requires physical receipt printer or cash drawer claims, but devices are unavailable.
2. Offline receipt wording is interpreted as official final posting.
3. Compliance reviewer rejects provisional offline capture language.

Governance safeguard:

If the release-gate decision is `Blocked pending fixes` or `Blocked pending hardware/compliance review`, roadmap, current-focus, and task-ledger updates must reflect the blocked state rather than advancing implementation status.

## 11. Implementation Notes for Execution Agent

1. Do not modify application code while executing this story unless a separate defect story is approved.
2. Keep UAT evidence separate from implementation notes.
3. Do not edit expected results after seeing failures. Record failures honestly.
4. Treat browser-console extension noise separately from IPOS application errors.
5. If a test requires sequence conflict simulation and cannot be created safely, mark it blocked with exact reason instead of manufacturing misleading evidence.
6. Preserve the hardware boundary in every release note.
7. If UAT produces defects, create follow-up defect stories rather than expanding this story.
8. Screenshots may be committed under the dated evidence folder for this team. If evidence is later moved to external storage, keep stable links in the execution report.

## 12. Validation Commands

Before UAT execution, run the lightweight regression baseline already used for the checkpoint where feasible:

```bash
git diff --check
npm run build
node tests/Frontend/checkoutFailureState.test.js
node tests/Frontend/catalogCache.test.js
node tests/Frontend/offlineQueueSync.test.js
node tests/Frontend/offlinePaymentQueue.test.js
node tests/Frontend/connectivityStore.test.mjs
```

Optional backend targeted checks if environment is ready:

```bash
php artisan test tests/Feature/POS/TerminalIdentityBindingTest.php
php artisan test tests/Feature/Admin/OfflineImportReviewTest.php
```

These commands do not replace manual UAT sign-off.

## 13. Deliverables

1. Completed UAT execution report.
2. Evidence folder or evidence links.
3. Updated `docs/validation/pos-terminal-offline-uat-2026-07-11.md` or dated execution copy.
4. Release-gate decision note.
5. Governance updates after approval:
   - `docs/roadmap/validated-implementation-roadmap.md`
   - `docs/ai-governance/task-ledger.md`
   - `.agents/rules/03-current-focus.md`
   - `docs/validation/pos-terminal-offline-stabilization-2026-07-10.md`
6. Defect records for each unresolved `Fail`.
7. Evidence completion matrix.

## 13A. Sign-Off Sequence

Sign-off should happen in this order:

```text
Cashier Tester
        ↓
Branch/Admin Reviewer
        ↓
Support / QA Reviewer
        ↓
Product Approval
```

Operational validation must happen before governance approval.

## 14. Definition of Done

This story is done when:

1. UAT execution report is complete.
2. Evidence has been captured and linked.
3. Sign-off table is complete.
4. Release-gate decision is recorded.
5. Follow-up defects are created for any unresolved failures.
6. Governance docs reflect the approved release-gate result.
7. No hardware readiness or full production readiness is claimed without evidence.

## 15. Review Questions

1. Who will act as cashier tester, branch/admin reviewer, support/QA reviewer, and product approver?
2. Which tenant, branch, cashier, and terminal profile should be used?
3. Should this UAT produce a `pilot expansion ready` decision only, or also a narrower `internal demo ready` decision?
4. Is physical printer/cash drawer hardware available for this pass, or should all hardware cases remain blocked/deferred?
5. Should screenshots be committed into the repository, or stored externally with links in the execution report?

## 16. Review Outcome

Approved for execution on 2026-07-14.

Reviewer guidance incorporated:

1. Execution manifest required.
2. Environment hashes required.
3. Defect linkage required for failed rows.
4. `Blocked`, `N/A`, and `Not Executed` are distinct result states.
5. Evidence completion matrix required.
6. Severity model added.
7. Sign-off order defined.
8. Blocked release-gate decisions must be reflected honestly in governance docs.

Recommended execution posture:

1. Use a dedicated pilot-like environment with one tenant, one branch, one configured terminal, one cashier, one open shift, realistic catalog data, and controlled offline mode enabled.
2. Use `Pilot Expansion Ready` as the formal positive release decision.
3. Keep hardware-dependent rows `Blocked / Deferred` unless physical printer and cash drawer validation is actually performed.
4. Commit screenshots under the dated evidence folder unless the team chooses an external evidence repository.
