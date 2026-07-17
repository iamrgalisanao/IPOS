# Story 41.4 Conflict, Drift, Ordering, and Review Handling

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Define and implement safe handling for sync conflicts, drift, stale policy, sequence gaps, batch ordering, predecessor blockage, and review-required records.

## Dependencies

Requires:

1. Story 41.2.
2. Story 41.3.

## Complexity

Large

## Deliverables

1. Conflict taxonomy.
2. Drift detection contract.
3. Review-required state behavior.
4. Retryable versus non-retryable distinction.
5. Cash-collected review reason.
6. Suspected duplicate review reason.
7. Strict stock policy review reason.
8. Clock drift and business-date review.
9. Terminal revocation handling.
10. Catalog-drift severity classifications.
11. Batch ordering and predecessor blockage rules.
12. Physical cash resolution classification.
13. Cashier-facing messaging.
14. Admin/support diagnostics.
15. Tests for sequence conflict, fingerprint drift, predecessor blocking, and review isolation.

## Out of Scope

1. Automatic data repair.
2. Manual forced posting without review evidence.
3. Fiscal override behavior.
4. Direct inventory or loyalty mutation.

## Acceptance Checks

1. Review-required records do not retry as ordinary network failures.
2. Cashier UI separates retryable failure from support review.
3. Drift does not partially mutate sale, inventory, loyalty, or store-credit state.
4. Support can identify why a record entered review.
5. Records process according to local ordering policy.
6. Review-required predecessors block later records only where dependency policy requires it.
7. Device time is not blindly accepted as committed business-date authority.
8. Cash-collected review records remain visible in support and drawer accountability.

## Notes

This story should make support states boring and explicit. The failure mode to avoid is a queue record disappearing from cashier/accountability views because the server could not accept it.
