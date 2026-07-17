# Story 41.8 Pilot UAT and Release Gate

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Validate the complete offline terminal readiness chain and produce a release decision.

## Dependencies

Requires:

1. Story 41.1.
2. Story 41.2.
3. Story 41.3.
4. Story 41.4.
5. Story 41.5.
6. Story 41.6.
7. Story 41.7.

## Complexity

Medium

## Deliverables

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
30. Compliance signoff for offline acknowledgment wording, presentation, numbering distinction, and official invoice delivery process.

## Out of Scope

1. New runtime behavior.
2. Production deployment automation.
3. Certification claims.
4. Skipping unresolved pilot evidence.

## Acceptance Checks

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
18. Production offline acknowledgment is not enabled without compliance approval.

## Notes

This story is the release decision gate. It should distinguish implemented behavior, pilot-proven behavior, hardware-deferred behavior, and production rollout approval.
