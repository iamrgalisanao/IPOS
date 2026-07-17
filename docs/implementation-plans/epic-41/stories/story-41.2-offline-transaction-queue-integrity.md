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

## Notes

This story owns local durability and queue integrity. It does not decide whether a queued envelope is accepted by the server.
