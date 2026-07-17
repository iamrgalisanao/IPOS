# Story 41.7 Hardware, Storage-Loss, and Terminal Recovery

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Validate terminal recovery, printer/drawer boundaries, hardware availability, browser storage-loss behavior, and support procedures.

## Dependencies

Requires:

1. Story 41.1.
2. Story 41.2.
3. Relevant portions of Story 41.3.
4. Existing hardware adapters.
5. Browser storage behavior.

## Complexity

Large

## Deliverables

1. Terminal reinstall recovery specification.
2. Storage-loss recovery specification.
3. Service-worker upgrade, stale service-worker, and cache recovery.
4. IndexedDB corruption and quota behavior.
5. Browser-data clearing behavior.
6. Browser uncertain-write-result recovery.
7. Local record exists but UI did not show success recovery.
8. Local success displayed but storage disappears afterward recovery.
9. Queue tombstone exists but full payload is gone recovery.
10. Terminal-binding epoch recovery.
11. Accepted tombstone recovery.
12. Queue extraction for support.
13. Support extraction with masked data.
14. Cashier changed after queue creation.
15. Receipt printer validation matrix.
16. Cash drawer validation matrix.
17. Hardware-deferred evidence rules.
18. Physical hardware UAT checklist where devices are available.
19. Support playbook.

## Out of Scope

1. Claiming hardware readiness without physical devices.
2. Replacing hardware adapters wholesale.
3. Fiscal receipt certification.
4. New sync acceptance rules.

## Acceptance Checks

1. Terminal identity loss fails closed.
2. Orphaned local queue records require support review.
3. Hardware unavailable scenarios are documented separately from hardware failure scenarios.
4. No hardware readiness claim is made without physical validation.
5. Storage quota, corruption, or browser-data clearing cannot falsely report successful capture.
6. Accepted tombstones support recovery without retaining unnecessary customer/cart data.
7. Uncertain write or storage-loss states do not create false accepted-sale evidence.
8. Support recovery preserves original cashier attribution.

## Notes

Hardware readiness is evidence-based. Browser storage recovery should be honest about what can and cannot be recovered.
