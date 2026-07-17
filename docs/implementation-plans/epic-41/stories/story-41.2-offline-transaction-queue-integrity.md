# Story 41.2 Offline Transaction Queue Integrity

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Harden local queue identity, persistence, diagnostics, and support visibility for offline provisional sales.

## Dependencies

Requires:

1. Story 41.1.
2. Existing IndexedDB/local queue implementation.
3. Existing queue diagnostics.
4. Existing terminal identity binding.

## Complexity

Large

## Deliverables

1. Local offline transaction UUID contract.
2. Stable tenant, branch, terminal, cashier, shift, and drawer binding.
3. Terminal binding epoch and local sequence contract.
4. Queue state model.
5. Immutable business envelope versus mutable queue metadata.
6. Business payload fingerprint and queue integrity checksum distinction.
7. Durable write, read-back, and checksum verification.
8. Queue ownership lease and single-writer rules.
9. Retry scheduling and backoff metadata.
10. Local cash-status tracking.
11. Local cancellation blocked after durable cash capture.
12. Storage quota and storage-unavailable behavior.
13. Accepted tombstone and retention/compaction policy.
14. Multi-tab and service-worker race tests.
15. Queue access rules after cashier switching.
16. Uncertain-storage recovery behavior.
17. Policy-limit boundary calculations.
18. Operator-visible queue status dashboard data.
19. Cash-status transition evidence.

## Required Queue State Vocabulary

Story 41.2 must lock the concrete queue state machine.

Expected states:

```text
persisting
locally_captured
pending_sync
syncing
retryable_failed
review_required
rejected
accepted
replayed
resolution_pending
resolved
accepted_retained
accepted_compacted
purged
```

Each state must define:

1. legal previous states,
2. legal next states,
3. whether automatic retry is allowed,
4. whether cashier action is allowed,
5. whether final shift close is blocked,
6. whether support action is required.

## Policy Limit Calculations

Queue limit checks must be explicit:

```text
new_pending_count > maximum_unsynced_transaction_count
new_pending_exposure > maximum_unsynced_cash_amount
age > configured maximum
```

Rules:

1. the current transaction is allowed only when adding it remains within the maximum,
2. a value equal to the maximum remains allowed,
3. anything exceeding the maximum is blocked before capture success,
4. exposure is based on unresolved offline sale totals or net collected exposure, not gross tender before change.

## Cash Status Transition Evidence

Cash-status transitions must be append-only.

When cash changes state, especially:

```text
collected
returned
```

the transition must preserve:

1. actor,
2. timestamp,
3. reason,
4. support case,
5. amount returned,
6. acknowledgment evidence where applicable.

## Non-Reopened Policy Decisions

Story 41.2 must consume Story 41.1 as a non-negotiable contract and must not reopen:

1. offline tender scope,
2. statutory discount blocking,
3. local cancellation blocking after durable cash capture,
4. final shift-close blocking while unresolved records exist,
5. no local stock deduction,
6. receipt authority,
7. loyalty promise restrictions.

## Out of Scope

1. Server posting behavior.
2. Hardware behavior.
3. Non-cash offline payment.
4. Official invoice creation.

## Acceptance Checks

1. Queue records preserve enough evidence for safe sync.
2. Cashier success is shown only after durable write and verification.
3. Queue records survive page refresh where browser storage remains available.
4. Terminal reinstall or storage loss cannot silently claim old queue identity.
5. Only one valid lease holder can transition or submit a queue record.
6. Material business payload is immutable after durable capture.
7. Retryable failures use bounded retry policy.
8. Review-required and rejected records do not auto-retry.
9. Cashier switching does not alter envelope ownership or actor evidence.
10. Accepted records retain minimal tombstones through retention policy.
11. Queue states define legal transitions, retry behavior, cashier action, and shift-close blocking.
12. Policy limits block before capture success when adding the current transaction would exceed configured maximums.
13. Cash-status changes preserve append-only transition evidence.

## Notes

This story owns local durability and queue integrity. It does not decide whether a queued envelope is accepted by the server.
