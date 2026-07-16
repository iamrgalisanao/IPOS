# Epic 40 Retrospective

## 1. Status

Complete

Date: 2026-07-16

Implementation Status: Complete

Pilot Validation Status: Pending execution

Epic implementation is complete. Production rollout remains subject to Story 40.8 pilot execution, hypercare evidence, and go/no-go approval.

## 2. Epic Reviewed

Epic 40: Inventory Operational Control and Reconciliation

Completed scope:

1. Inventory evidence and movement ledger hardening.
2. Unit conversion governance.
3. Negative-stock exception and resolution lifecycle.
4. Recipe deduction snapshot integrity.
5. Stocktake reconciliation integration.
6. Inventory adjustment authorization.
7. Inventory reporting and audit evidence.
8. Pilot UAT and operational recovery.

## 3. Overall Outcome

Epic 40 succeeded because it treated inventory as operational evidence rather than a loose stock counter.

The strongest outcome is that stock-affecting behavior now has a consistent evidence model: branch-scoped movement sequences, before/change/after snapshots, conversion and recipe snapshots, variance categories, stocktake reconciliation, governed manual adjustments, read-only reporting, and pilot recovery controls.

The epic also closed the gap between implementation and rollout readiness. Story 40.8 made pilot entry criteria, evidence capture, containment, go/no-go rules, hypercare, and recovery boundaries explicit before branch rollout.

## 4. What Went Well

1. The Epic 38 and Epic 39 documentation pattern scaled again.
   - README, Architecture Lock, Implementation Guide, story specs, ADR folder, and diagrams folder gave Epic 40 a familiar shape.
   - Story specifications stayed focused on implementation contracts and preserved architectural decisions in the Architecture Lock.

2. The story order was correct.
   - Movement evidence came before conversion, variance, recipes, stocktake, adjustments, reporting, and pilot readiness.
   - Reporting landed only after canonical movement, variance, recipe, and stocktake evidence existed.
   - Pilot readiness came last, after all operational behavior could be validated end-to-end.

3. Evidence discipline improved across inventory.
   - `branch_inventories.current_stock` remains operational state that must reconcile to movement evidence.
   - Movement rows preserve branch sequence, quantity before, signed delta, quantity after, source reference, and source effect key.
   - Recipe and conversion snapshots protect historical deductions from later configuration changes.

4. Variance semantics became clearer.
   - Negative-stock exceptions, physical count variance, system reconciliation exceptions, and configuration gaps are now distinct.
   - Inventory integrity exceptions are explicitly visible: current stock versus movement-derived mismatch, negative current stock without corresponding exception evidence, movement-chain discontinuity, missing required source-effect identity, and incomplete stocktake posting evidence.
   - Variance records do not directly mutate stock.
   - Reports explain variance state without becoming correction workflows.

5. Pilot governance matured.
   - Story 40.8 moved the epic from implemented features to documented branch-rollout readiness, subject to pilot execution and go/no-go approval.
   - Containment, recovery, evidence manifest, hypercare, and exit criteria now exist before live rollout.
   - The ambiguous rollback language was replaced with operational containment and governed recovery.

6. Validation stayed focused.
   - Inventory feature tests reached a stable checkpoint.
   - A dedicated `Epic40PilotReadinessTest` now guards readiness documentation and no-mutation behavior for pilot/reporting surfaces.

Validation checkpoint:

```text
Command: php artisan test tests/Feature/Inventory
Result: 134 tests passed, 840 assertions
Date: 2026-07-16
Branch/commit: feat/40.8-pilot-uat-recovery-plan / 95a5938
Environment: local development
```

## 5. What Was Difficult

1. Existing inventory behavior had multiple overlapping historical tracks.
   - Earlier market-readiness docs, stocktake docs, inventory visibility work, and Epic 40 docs all described related behavior.
   - Story 40.8 had to reconcile these without creating duplicate runbooks.

2. "Rollback" terminology was risky.
   - Existing pilot enablement language used rollback in a documentation-pack sense.
   - Epic 40 required stricter wording because inventory evidence is append-only and must not be deleted or rewritten.

3. Readiness and runtime boundaries needed extra care.
   - Story 40.8 could easily have become a new feature-flag or workflow-blocking story.
   - The final spec clarified that containment modes may be procedural unless existing routes already enforce them.

4. Offline behavior needed precise framing.
   - Offline inventory mutation remains prohibited.
   - Offline cash sale sync still needs validation because server-authoritative inventory movement should occur exactly once after synchronization.

5. Reporting can blur into operations.
   - Current Stock, Stock Card, Movement Summary, Reconciliation, Usage, and Integrity reports are all useful during support.
   - The epic needed repeated guardrails that reports are read-only and must not mutate inventory.

## 6. Lessons Learned

1. Every operational-state epic needs an evidence model.
   - State, movement, snapshots, reconciliation, reports, and recovery must be designed together.

2. Pilot readiness should be a first-class story.
   - UAT, go/no-go, hypercare, evidence handling, and recovery are not afterthoughts for POS and inventory features.

3. Rollback language must be domain-specific.
   - Application rollback may disable future behavior.
   - Inventory correction must happen through governed business workflows.
   - Database restore is disaster recovery, not item-level stock correction.

4. No-mutation tests are useful for reporting surfaces.
   - Inventory reports and readiness routes should be guarded so future changes do not quietly introduce side effects.

5. Existing enablement docs should be migrated, not duplicated.
   - Story 40.8 worked because it retired the old rollback note and replaced it with containment/recovery terminology.

6. Cross-epic integration risks should be named early.
   - Inventory now depends on POS sale, void, refund, recipe, terminal offline sync, and reporting flows.
   - Future epics should identify those integration touchpoints during architecture lock review.

## 7. Technical Debt and Follow-Ups

1. Run an actual branch pilot using `docs/validation/epic-40-pilot-uat-readiness.md`.
2. Fill a real evidence manifest during pilot execution rather than leaving it as a template.
3. Decide whether containment modes need runtime enforcement beyond operational procedure.
4. Add explicit offline-sale synchronization UAT evidence once a pilot terminal scenario is available.
5. Review whether `InventoryMovement` source-effect idempotency needs additional indexes after production-volume observation.
6. Consider an ADR expansion for inventory containment versus disaster recovery after pilot experience.
7. Review old market-readiness and roadmap docs for remaining pre-Epic-40 wording.

## 8. Decisions Preserved

1. Current stock is an operational projection, not the historical source of truth.
2. Inventory movements are append-only.
3. Branch movement sequence is the canonical ledger ordering authority.
4. Missing conversion or inventory configuration fails closed.
5. Recipe and conversion changes do not reinterpret historical deductions.
6. Negative-stock exceptions do not directly correct stock.
7. Stocktake posting is a governed reconciliation event.
8. Manual adjustments require structured reasons and authorization.
9. Reports are read-only and cannot hide mutation behavior.
10. Application rollback never deletes or rewrites committed inventory evidence.
11. Offline inventory mutation remains prohibited.
12. Server-authoritative offline sale synchronization must remain idempotent.

## 9. Metrics to Observe During Pilot

1. Movement/current-stock reconciliation exceptions.
2. Duplicate source-effect detections.
3. Negative-stock exception frequency and aging.
4. Stocktake correction frequency and magnitude.
5. Manual adjustment volume by reason.
6. Approval denial and stale-approval rate.
7. Recipe or conversion configuration failures.
8. Offline-sale synchronization latency and retry count.
9. Movement-chain discontinuities.
10. Inventory report/export failures.

## 10. Process Improvements to Carry Forward

1. Keep using the standard epic structure:
   - README
   - Architecture Lock
   - Implementation Guide
   - stories/
   - adr/
   - diagrams/
   - retrospective

2. Mark document status promptly after implementation.
   - The Epic 40 Implementation Guide was still labeled Draft after stories were complete.
   - Future epics should include a final status sync as part of the last story.

3. Require every major operational epic to define:
   - state owner,
   - evidence owner,
   - mutation entry points,
   - idempotency behavior,
   - offline policy,
   - reports,
   - recovery,
   - pilot readiness.

4. Keep local PR review as the standard:
   - inspect diff,
   - run focused tests,
   - commit intentionally,
   - push with CI-triggering branch prefix.

5. Avoid branch prefixes that bypass CI.
   - Feature implementation branches should use `feat/` when the workflow filters depend on that prefix.

## 11. Next Epic Preparation Notes

The next epic should start only after deciding which risk deserves priority:

1. POS terminal offline UAT/release gate.
   - Still called out in governance as a release-readiness action.
   - Best if the goal is to harden cashier operations before expanding platform scope.

2. Reporting modernization continuation.
   - The task ledger still references reporting roadmap follow-ups.
   - Best if the goal is management visibility and operational analytics.

3. Procurement or supplier-receiving hardening.
   - Natural follow-up after inventory operational controls.
   - Best if the goal is upstream stock evidence and receiving lifecycle integrity.

4. Hardware adapter readiness.
   - Still a residual terminal-readiness topic.
   - Best if physical printer/cash drawer/barcode device validation is now available.

Recommended next direction:

```text
Epic 41: POS Terminal Offline Readiness and Release Validation
```

Reason:

1. Inventory, dining, loyalty, store credit, statutory discounts, and terminal binding now depend on stable POS terminal operations.
2. Offline cashier behavior has already been identified as a release gate.
3. Hardware readiness should not be claimed until physical validation is available.
4. A release gate will expose whether the recent platform work is safe for branch pilot use.

Possible first-story sequence:

1. Offline architecture and policy lock.
2. Offline transaction queue integrity.
3. Server synchronization and idempotency.
4. Conflict and drift handling.
5. Offline permission and payment restrictions.
6. Inventory and loyalty synchronization validation.
7. Hardware and terminal recovery.
8. Pilot UAT and release gate.

## 12. Retro Action Items

1. Commit the Epic 40 retrospective and status sync.
2. Run the Epic 40 pilot readiness pack against a real or simulated branch.
3. Record pilot evidence and residual risks.
4. Decide whether to proceed with Epic 41 POS Terminal Offline Readiness and Release Validation or formally charter a different next epic.
5. If a new epic is approved, create its README, Architecture Lock, Implementation Guide, stories/, adr/, and diagrams/ folders before drafting Story 1.
