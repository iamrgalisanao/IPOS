# Epic 39 Retrospective

## 1. Status

Complete

Date: 2026-07-15

## 2. Epic Reviewed

Epic 39: Store Credit and Loyalty Ledger

Completed scope:

1. Customer financial account foundation.
2. Append-only store credit ledger.
3. Store credit refund issuance.
4. Store credit redemption.
5. Store credit admin review.
6. Loyalty ledger specification.
7. Loyalty redemption specification.
8. Reporting and reconciliation.
9. Loyalty runtime implementation.
10. Loyalty reversal on void and refund.

## 3. Overall Outcome

Epic 39 succeeded because it treated customer value as ledger-backed evidence instead of mutable balance fields.

The strongest outcome is that monetary store credit and non-monetary loyalty points now share common architectural discipline without becoming the same domain. Both use append-only history, derived balances, tenant isolation, idempotency, audit evidence, and reporting surfaces, while keeping payment, refund, accounting, store credit, and loyalty boundaries separate.

## 4. What Went Well

1. The Epic 38 documentation pattern scaled well.
   - README, Architecture Lock, Implementation Guide, Stories, ADRs, and Diagrams gave each decision a clear home.
   - Story documents stayed focused on implementation contracts instead of becoming duplicate architecture documents.

2. The execution order was correct.
   - Customer financial accounts came before ledgers.
   - Store credit came before loyalty.
   - Reporting came after canonical ledger data existed.
   - Loyalty runtime and loyalty reversal were added only after the specifications were stable.

3. Ledger invariants stayed consistent.
   - Append-only rows.
   - Derived balances.
   - Immutable source evidence.
   - Idempotent replay.
   - Drift rejection.
   - No mutable balance shortcuts.

4. Boundary discipline improved across the epic.
   - Refund authority remained in `RefundService`.
   - Payment authority remained in the payment flow.
   - Accounting liability remained monetary and did not absorb loyalty points.
   - Loyalty reversal did not create store-credit rows or accounting liability.

5. Validation maturity increased.
   - Focused story tests were added first.
   - Regression clusters were run before commit.
   - Full backend suite and frontend build were used as completion gates.

## 5. What Was Difficult

1. Loyalty runtime required a follow-up story.
   - Stories 39.6 and 39.7 were initially specification-heavy and left runtime work deferred.
   - The follow-up Story 39.9 was necessary and appropriate, but it revealed that future epics should explicitly separate "specification complete" from "runtime complete."

2. Loyalty reversal was discovered late.
   - Story 39.10 closed an important lifecycle gap after the main runtime work.
   - This was handled cleanly, but the lesson is that every ledger epic should include reversal, void, refund, expiration, and reconciliation behavior in its lifecycle map from the beginning.

3. Negative balance policy needed stronger governance wording.
   - The first draft allowed interpretation where financial reversal could complete while loyalty correction only produced an audit flag.
   - The explicit Failure Atomicity Rule fixed this.

4. Reporting scope can expand quietly.
   - Store credit, loyalty, accounting, and customer statements touch the same user-facing surfaces.
   - Reporting requirements need a dedicated story whenever ledger behavior changes.

## 6. Lessons Learned

1. Every ledger needs a lifecycle, not just entry types.
   - Creation, consumption, reversal, restoration, expiration, forfeiture, reporting, and reconciliation should be visible before implementation begins.

2. "Specification Done" must be distinct from "Runtime Done."
   - Specification completion is valuable, but the epic status must show whether production code exists.

3. Idempotency keys are part of the domain contract.
   - They should be specified with material fields and drift behavior, not left to implementation taste.

4. Failure atomicity must be written explicitly.
   - If required domain consequences cannot be committed, the parent transaction must roll back unless a first-class pending workflow exists.

5. ADRs are useful after the story sequence stabilizes.
   - Creating ADRs after core implementation decisions are proven avoids speculative documentation while still preserving rationale.

6. Full-suite validation before push is worth keeping.
   - The project now has enough interconnected POS behavior that targeted tests alone are not enough for major subsystem changes.

## 7. Technical Debt and Follow-Ups

1. Expand ADR placeholders into final ADRs where still marked placeholder.
2. Consider a concrete `PendingLoyaltyReversal` aggregate only if business policy later allows approval-based reversal deferral.
3. Add customer-facing wallet views only after internal ledger statements are stable.
4. Consider richer fraud or abuse analytics for repeated refund-and-loyalty reversal patterns.
5. Keep store credit refund behavior for previously redeemed store credit explicit in future refund policy work.

## 8. Process Improvements to Carry Forward

1. Start every major epic with:
   - README
   - Architecture Lock
   - Implementation Guide
   - stories/
   - adr/
   - diagrams/

2. Track story status with these exact meanings:
   - Planned
   - Draft for Review
   - Approved for Implementation
   - In Progress
   - Done
   - Runtime Deferred

3. Require each story to define:
   - ownership boundary
   - idempotency behavior
   - failure atomicity
   - audit evidence
   - reporting impact
   - offline policy
   - rollback behavior

4. For ledgers and operational state machines, require lifecycle coverage:
   - create
   - mutate
   - reverse
   - reconcile
   - report
   - recover

## 9. Epic 40 Preparation Notes

Epic 40 should use the Epic 38 and Epic 39 structure from the start.

Recommended next epic direction:

```text
Epic 40 Inventory Operational Control and Reconciliation
```

Reason:

1. Inventory already has visibility, stocktake, unit conversion, and variance foundations.
2. Existing inventory planning documents show market-readiness value in operational control.
3. POS sales, refunds, voids, store credit, dining, and loyalty now depend on reliable inventory evidence.
4. The next risk is not basic inventory screens; it is inventory correctness, reconciliation, and operational recovery.

## 10. Retro Action Items

1. Create Epic 40 using the standard documentation structure.
2. Start Epic 40 with architecture constraints before story specs.
3. Review existing inventory services and planning locks before drafting stories.
4. Treat inventory movement history as canonical evidence.
5. Preserve clear boundaries between inventory, procurement, accounting, POS sales, and stocktake.
