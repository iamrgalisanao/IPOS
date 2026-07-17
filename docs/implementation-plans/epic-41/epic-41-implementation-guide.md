# Epic 41 POS Terminal Offline Readiness and Release Validation Implementation Guide

## 1. Status

Approved for Story Implementation

Date: 2026-07-16

This guide defines the intended execution order for Epic 41. It does not replace:

```text
docs/implementation-plans/epic-41/epic-41-architecture-lock.md
```

If this guide conflicts with the Architecture Lock, the Architecture Lock wins.

## 2. Implementation Order

Recommended order:

1. Story 41.1
2. Story 41.2
3. Story 41.3
4. Story 41.4
5. Story 41.5
6. Story 41.6
7. Story 41.7
8. Story 41.8

Reason:

1. Offline policy must be locked before queue or sync behavior changes.
2. Queue integrity must be stable before server idempotency can be trusted.
3. Server synchronization, idempotency, and transaction atomicity must be proven before conflict handling is expanded.
4. Conflict, drift, ordering, and review rules must exist before release-gate UAT.
5. Offline permissions, shift, payment, discount, and receipt restrictions protect cashier behavior before cross-domain sync validation.
6. Inventory and loyalty validation must consume stable sale-sync behavior.
7. Hardware and terminal recovery should be tested after core queue/sync behavior is stable.
8. Pilot UAT and release gate should validate the complete operational chain.

## 3. Story Status

| Story | Status | Owner | Sprint |
| --- | --- | --- | --- |
| 41.1 | Done | - | - |
| 41.2 | Implemented - Local Verification Complete | - | - |
| 41.3 | Implemented - Local Verification Complete | - | - |
| 41.4 | Approved for Implementation | - | - |
| 41.5 | Ready for Implementation | - | - |
| 41.6 | Planned | - | - |
| 41.7 | Planned | - | - |
| 41.8 | Planned | - | - |

## 4. Story Dependencies and Complexity

| Story | Depends On | Complexity |
| --- | --- | --- |
| 41.1 | Existing offline stabilization notes, terminal identity closure, POS offline queue implementation | Medium |
| 41.2 | 41.1, existing IndexedDB/local queue and queue diagnostics | Large |
| 41.3 | 41.1, 41.2, existing offline sync endpoints and server sale creation path | Very Large |
| 41.4 | 41.2, 41.3 | Large |
| 41.5 | 41.1, 41.2, existing permission/payment UI restrictions, shift policy, fiscal document policy | Large |
| 41.6 | 41.3, 41.4, Epic 39 loyalty runtime, Epic 40 inventory evidence | Very Large |
| 41.7 | 41.1, 41.2, relevant portions of 41.3, existing hardware adapters, browser storage behavior | Large |
| 41.8 | 41.1 through 41.7 | Medium |

## 5. Common Definition of Done

Every story is done when:

1. Acceptance criteria pass.
2. Required backend feature tests pass.
3. Required frontend tests pass where UI/offline behavior is touched.
4. Tenant, branch, terminal, and cashier isolation are verified.
5. Offline state remains provisional unless accepted by server sync.
6. Replay is idempotent where applicable.
7. Drift is rejected before mutation.
8. Review-required conflicts are not retried as ordinary network failures.
9. Online-only operations remain blocked offline.
10. No fiscal, inventory, loyalty, store-credit, or official receipt authority is moved into the browser.
11. Terminal identity remains mandatory for shell access and sync.
12. Documentation or story notes are updated.
13. Code review is approved.
14. CI passes before merge.

## 6. Story 41.1 Offline Architecture and Policy Lock

Objective:

Create the detailed implementation specification for the offline architecture, policy boundary, and release-gate assumptions.

Deliverables:

1. Offline allowed/blocked operation matrix.
2. Terminal policy contract.
3. Local queue versus server authority boundary.
4. Online-only list for payment, refund, void, inventory, dining, loyalty, store credit, admin, and fiscal behavior.
5. Offline transaction envelope shape.
6. Evidence retention requirements.
7. Failure, replay, drift, and review-required vocabulary.
8. Hardware validation/deferment policy.
9. Provisional receipt/invoice policy.
10. Shift policy.
11. Trusted-time and business-date policy.
12. Catalog/offline age limits.
13. Customer identity policy.
14. Standalone checkout versus dining scope.
15. Cash-collected but rejected or review-required sync policy.
16. Queued-record immutability and cancellation policy.
17. Offline inventory visibility policy.
18. First-release statutory discount restriction.
19. Cashier switching and cached-session expiry policy.

Out of scope:

1. Runtime queue rewrite.
2. New sync endpoint behavior.
3. Hardware adapter implementation.

Acceptance checks:

1. Architecture decisions are explicit enough for independent implementation.
2. Cash-only provisional offline capture remains the first-release offline mutation boundary.
3. Server authority is preserved for all committed business consequences.
4. Offline policy does not conflict with Epic 39 loyalty or Epic 40 inventory architecture.
5. Provisional acknowledgments cannot be mistaken for official invoices.
6. Offline dine-in ticket mutation is explicitly unsupported.
7. Cash-collected unresolved records have explicit review and resolution rules.
8. Cached stock is not presented as authoritative offline.
9. Statutory discounts are online-only for the first release.

## 7. Story 41.2 Offline Transaction Queue Integrity

Objective:

Harden local queue identity, persistence, diagnostics, and support visibility for offline provisional sales.

Deliverables:

1. Local offline transaction UUID contract.
2. Stable terminal/cashier/branch/tenant binding.
3. Terminal binding epoch and local monotonic ordering or sequence evidence.
4. Queue state model.
5. Retry count and last-error tracking.
6. Queue diagnostics view requirements.
7. Page refresh and stale shell behavior validation.
8. Storage-loss and terminal-reinstall behavior specification.
9. Single-writer lease.
10. Durable write, read-back, and checksum verification.
11. Storage quota and storage-unavailable behavior.
12. Accepted tombstones.
13. Local data retention and compaction.
14. Multi-tab/service-worker race tests.
15. Immutable business envelope versus mutable queue metadata.
16. Local cancellation blocked after durable cash capture.
17. Retry scheduling and backoff metadata.
18. Local cash-status tracking.
19. Queue access after cashier switch.
20. Uncertain-storage recovery behavior.

Out of scope:

1. Server posting changes.
2. Hardware behavior.
3. Non-cash offline payment.

Acceptance checks:

1. Offline queue preserves enough evidence for safe sync.
2. Queue records are not silently lost on page refresh where browser storage remains available.
3. Queue diagnostics distinguish pending, failed, review-required, accepted, and rejected states.
4. Terminal reinstall or storage loss cannot silently claim old queue identity.
5. Cashier success is shown only after the envelope is durably written and verified.
6. Only one valid queue lease holder can transition or submit a queue record.
7. Accepted records preserve minimal tombstones through retention policy.
8. Material business payload is immutable after durable capture.
9. Retryable failures use bounded retry policy while review/rejected records do not auto-retry.
10. Cashier switching does not alter envelope ownership or actor evidence.

## 8. Story 41.3 Server Synchronization, Idempotency, and Transaction Atomicity

Objective:

Make server synchronization of offline provisional sales deterministic, idempotent, replay-safe, and consequence-complete.

Deliverables:

1. Offline sync request contract.
2. Request fingerprint material fields.
3. Exact replay response behavior.
4. SaleCreationService boundary confirmation.
5. Server-side transaction boundary.
6. Duplicate source-effect prevention.
7. Sync audit payload.
8. Tests for accepted, replayed, retryable, rejected, and review-required results.
9. Per-envelope consequence atomicity.
10. Consequence status payload.
11. Official identity allocation.
12. Result completeness rules.
13. Outbox behavior where unavoidable.
14. Server handling for cash-collected review records.
15. Suspected duplicate detection beyond exact UUID replay.
16. Strict consequence-status schema.
17. Official invoice retrieval or delivery contract.
18. Tenant-scoped offline transaction UUID uniqueness.
19. Server-side fingerprint recomputation.
20. Insert-or-lock concurrency pattern.
21. Status lookup endpoint.
22. OpenAPI contract fragment.
23. Completed versus asynchronous HTTP response semantics.
24. Attempt-history separation from envelope outcome.
25. Raw-payload storage protection.
26. Outbox idempotency effect keys.
27. Consequence status history.
28. Deadlock retry policy.

Out of scope:

1. Browser queue UI redesign.
2. Hardware printing.
3. Expanding offline tender types.

Acceptance checks:

1. Exact replay creates no duplicate sale, payment, inventory, loyalty, or store-credit consequences.
2. Drift is rejected before mutation.
3. Accepted sync returns stable server sale reference.
4. SaleCreationService remains the authority for committed sale creation.
5. Accepted status is returned only when required consequences are complete or explicitly represented by a durable pending state.
6. Local reference, server sale identity, and official invoice identity remain separate.
7. `accepted_with_pending_loyalty` is not used as a top-level status; consequence-specific pending states live in consequence status fields.
8. Suspected duplicate business captures enter review unless exact replay can be proven.
9. Cash-collected records that cannot safely post are preserved for support resolution.
10. Same UUID with changed context is treated as drift or review, not a new sale.
11. The server recomputes the canonical fingerprint before mutation.
12. Synchronous completed sync returns HTTP 200; HTTP 202 is used only with durable asynchronous status lookup.
13. Retryable failures remain attempt outcomes and may be reprocessed when policy permits.
14. Attempt history, consequence history, and outbox idempotency are preserved.

## 9. Story 41.4 Conflict, Drift, Ordering, and Review Handling

Objective:

Define and implement safe handling for sync conflicts, drift, stale policy, sequence gaps, batch ordering, predecessor blockage, and review-required records.

Deliverables:

1. Conflict taxonomy.
2. Drift detection contract.
3. Review-required state behavior.
4. Retryable versus non-retryable distinction.
5. Support resolution requirements.
6. Cashier-facing messaging.
7. Admin/support diagnostics.
8. Tests for sequence conflict and fingerprint drift.
9. Batch ordering.
10. Predecessor blockage rules.
11. Terminal revocation handling.
12. Clock drift and business-date review.
13. Catalog-drift severity.
14. Cash-collected review reason.
15. Suspected duplicate review reason.
16. Strict stock policy review reason.
17. Conflict classification for physical cash resolution.

Out of scope:

1. Automatic data repair.
2. Manual forced posting without review evidence.
3. Fiscal override behavior.

Acceptance checks:

1. Review-required records do not retry as ordinary network failures.
2. Cashier UI clearly separates retryable failure from support review.
3. Drift does not partially mutate sale, inventory, loyalty, or store-credit state.
4. Support can identify the reason a record entered review.
5. Records process according to local ordering policy.
6. Review-required predecessors block later records only where dependency policy requires it.
7. Device time is not blindly accepted as committed business-date authority.
8. Conflicts define whether later records can proceed, must pause, or require physical cash resolution.
9. Review-required cash-collected records remain visible in support and drawer accountability.

## 10. Story 41.5 Offline Permission, Shift, Payment, Discount, and Receipt Restrictions

Objective:

Validate and harden offline restrictions for permissions, shift accountability, payment methods, approval paths, discounts, provisional receipt behavior, and privileged operations.

Deliverables:

1. Offline payment method matrix.
2. Cash-only enforcement checks.
3. Non-cash disabled-state validation.
4. Manager approval boundary.
5. Offline discount policy with statutory discounts blocked for first release.
6. Online-only route/action guard review.
7. UI tests for blocked offline actions.
8. Permission and branch/terminal isolation tests.
9. Open-shift requirement.
10. Pending-queue shift-close behavior.
11. Provisional document wording and printing behavior.
12. Mixed-tender restoration tests.
13. Statutory discounts entirely blocked offline.
14. Cashier-switch and logout restrictions.
15. Provisional expected-cash presentation.
16. Pre-sync error behavior with local cancellation blocked after durable cash capture.
17. Customer acknowledgment when final invoice is pending.

Out of scope:

1. New payment provider integrations.
2. Offline external payment authorization.
3. Offline manager approval issuance unless separately approved.
4. Official offline invoice issuance unless compliance separately approves it.

Acceptance checks:

1. Card, e-wallet, bank transfer, and external tenders remain blocked offline.
2. Offline UI cannot bypass online-only permission checks.
3. Privileged operations show recoverable online-required guidance.
4. Cash-only provisional capture remains the only allowed offline payment path.
5. Offline capture is blocked without valid cached open-shift authority.
6. Shift close with unsynced sales is blocked or clearly provisional.
7. Offline payment UI cannot construct mixed-tender transactions through split-payment components or restored local state.
8. Provisional acknowledgments cannot be mistaken for official invoices.
9. Statutory discount requests are blocked with online-required guidance.
10. Pre-sync correction does not edit or erase a durably captured envelope.
11. Provisional expected-cash display is not treated as official drawer accounting.

## 11. Story 41.6 Inventory, Loyalty, and Cross-Domain Consequence Validation

Objective:

Validate that accepted offline sale synchronization produces inventory and loyalty consequences exactly once through server-authoritative services.

Deliverables:

1. Inventory movement sync validation.
2. Recipe deduction sync validation.
3. Negative-stock policy behavior during sync.
4. Loyalty accrual sync validation.
5. Store credit no-offline-redemption validation.
6. Replay invariant tests.
7. Drift and failure atomicity tests.
8. Reporting evidence checks.
9. Customer identity validation.
10. Loyalty status messaging.
11. Partial-consequence prevention.
12. Reconciliation after delayed multi-day sync.
13. Server business-date effects.
14. Cached-stock visibility restrictions.
15. Strict-stock sync conflict behavior.
16. Sale accepted without loyalty versus review policy.
17. Report treatment for late business-date posting.
18. Reconciliation of provisional drawer cash to committed server payments.

Out of scope:

1. Local inventory mutation.
2. Local loyalty ledger mutation.
3. Store credit offline redemption.
4. New inventory or loyalty rules.

Acceptance checks:

1. Accepted sync creates inventory movement/recipe effects exactly once.
2. Accepted sync creates loyalty accrual exactly once when eligible.
3. Replayed sync does not duplicate inventory or loyalty.
4. Failure to produce required server consequences prevents silent successful posting.
5. Store credit redemption remains online-only.
6. Terminal UI does not promise loyalty accrual before server acceptance.
7. Delayed sync uses server-resolved business date and preserves reporting evidence.
8. Cached stock is labelled provisional with last-sync time and is not locally deducted.
9. Strict-stock conflicts after cash collection enter review instead of disappearing from accountability.

## 12. Story 41.7 Hardware, Storage-Loss, and Terminal Recovery

Objective:

Validate terminal recovery, printer/drawer boundaries, hardware availability, and support procedures.

Deliverables:

1. Terminal reinstall recovery specification.
2. Storage-loss recovery specification.
3. Service-worker upgrade, stale service-worker, and cache recovery.
4. Receipt printer validation matrix.
5. Cash drawer validation matrix.
6. Hardware-deferred evidence rules.
7. Support playbook.
8. Physical hardware UAT checklist where devices are available.
9. IndexedDB corruption/quota behavior.
10. Browser-data clearing behavior.
11. Queue extraction for support.
12. Terminal-binding epoch recovery.
13. Accepted tombstone recovery.
14. Browser uncertain-write-result recovery.
15. Local record exists but UI did not show success recovery.
16. Local success displayed but storage disappears afterward recovery.
17. Queue tombstone exists but full payload is gone recovery.
18. Support extraction with masked data.
19. Cashier changed after queue creation.

Out of scope:

1. Claiming hardware readiness without physical devices.
2. Replacing hardware adapters wholesale.
3. Fiscal receipt certification.

Acceptance checks:

1. Terminal identity loss fails closed.
2. Orphaned local queue records require support review.
3. Hardware unavailable scenarios are documented separately from hardware failure scenarios.
4. No hardware readiness claim is made without physical validation.
5. Storage quota, corruption, or browser-data clearing cannot falsely report successful capture.
6. Accepted tombstones support recovery without retaining unnecessary customer/cart data.
7. Uncertain write or storage-loss states do not create false accepted-sale evidence.
8. Support recovery preserves original cashier attribution.

## 13. Story 41.8 Pilot UAT and Release Gate

Objective:

Validate the complete offline terminal readiness chain and produce a release decision.

Deliverables:

1. Pilot UAT checklist.
2. Evidence manifest.
3. Role-based UAT matrix.
4. Offline/online transition scenarios.
5. Sync replay and drift scenarios.
6. Hardware validation/deferment record.
7. Support diagnostics checklist.
8. Go/no-go criteria.
9. Release decision record.
10. Durable-capture failure scenarios.
11. Shift-close-with-unsynced-records scenarios.
12. Device-clock-change scenarios.
13. Terminal-revoked-while-offline scenarios.
14. Review-required predecessor scenarios.
15. Provisional acknowledgment versus official invoice scenarios.
16. Multiple-tab sync scenarios.
17. Catalog-age-limit scenarios.
18. Browser-storage-cleared scenarios.
19. Pending/failed loyalty consequence scenarios.
20. Cash accepted but server later places the record in review.
21. Cashier notices an error after local durable capture.
22. Cached stock differs from server stock at synchronization.
23. Strict stock policy rejects or reviews a delayed sale.
24. Cashier switches while records remain pending.
25. Statutory discount is attempted offline.
26. Same business sale is recaptured under a different local UUID.
27. Retry backoff does not resubmit review-required records.
28. Official invoice is retrieved or delivered after acceptance.
29. Shift reconciliation includes accepted and unresolved offline cash.

Out of scope:

1. New runtime behavior.
2. Production deployment automation.
3. Certification claims.

Acceptance checks:

1. Online baseline checkout passes.
2. Offline shell and cached catalog pass.
3. Cash-only provisional capture passes.
4. Sync accepted records post exactly once.
5. Replay and drift behavior pass.
6. Online-only operations are blocked offline.
7. Hardware readiness or deferral is explicit.
8. Release gate decision is recorded with owner and evidence.
9. Durable local persistence failure blocks offline capture success.
10. Provisional and official fiscal identities remain distinct.
11. Queue lease and predecessor-blocking behavior pass.
12. Customer and loyalty messaging remains server-acceptance aware.
13. Cash-collected review records are retained, visible, and resolution-gated.
14. Envelope immutability and local cancellation blocking are validated.
15. Offline stock presentation is stale/provisional and not locally deducted.
16. Status dimensionality is validated with consequence-specific pending states.
17. Cashier-switch protection preserves original actor evidence.
