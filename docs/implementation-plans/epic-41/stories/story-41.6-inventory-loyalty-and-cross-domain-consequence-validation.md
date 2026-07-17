# Story 41.6 Inventory, Loyalty, and Cross-Domain Consequence Validation

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Validate that accepted offline sale synchronization produces inventory and loyalty consequences exactly once through server-authoritative services.

## Dependencies

Requires:

1. Story 41.3.
2. Story 41.4.
3. Epic 39 loyalty runtime.
4. Epic 40 inventory evidence.

## Complexity

Very Large

## Deliverables

1. Inventory movement sync validation.
2. Recipe deduction sync validation.
3. Negative-stock policy behavior during sync.
4. Cached-stock visibility restrictions.
5. Strict-stock sync conflict behavior.
6. Loyalty accrual sync validation.
7. Customer identity validation.
8. Loyalty status messaging.
9. Sale accepted without loyalty versus review policy.
10. Store credit no-offline-redemption validation.
11. Partial-consequence prevention.
12. Replay invariant tests.
13. Drift and failure atomicity tests.
14. Report treatment for late business-date posting.
15. Reconciliation of provisional drawer cash to committed server payments.
16. Reporting evidence checks.

## Out of Scope

1. Local inventory mutation.
2. Local loyalty ledger mutation.
3. Store credit offline redemption.
4. New inventory or loyalty rules.
5. Browser-local stock authority.

## Acceptance Checks

1. Accepted sync creates inventory movement/recipe effects exactly once.
2. Accepted sync creates loyalty accrual exactly once when eligible.
3. Replayed sync does not duplicate inventory or loyalty.
4. Failure to produce required server consequences prevents silent successful posting.
5. Store credit redemption remains online-only.
6. Terminal UI does not promise loyalty accrual before server acceptance.
7. Delayed sync uses server-resolved business date and preserves reporting evidence.
8. Cached stock is labelled provisional with last-sync time and is not locally deducted.
9. Strict-stock conflicts after cash collection enter review instead of disappearing from accountability.

## Notes

This story validates downstream consequence integrity. It should consume the server acceptance result from Story 41.3 rather than adding new browser-side ledgers.
