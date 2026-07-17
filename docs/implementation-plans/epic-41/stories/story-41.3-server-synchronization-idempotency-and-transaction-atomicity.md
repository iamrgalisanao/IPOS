# Story 41.3 Server Synchronization, Idempotency, and Transaction Atomicity

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Make server synchronization of offline provisional sales deterministic, idempotent, replay-safe, and consequence-complete.

## Dependencies

Requires:

1. Story 41.1.
2. Story 41.2.
3. Existing offline sync endpoints.
4. Existing server checkout and `SaleCreationService` path.

## Complexity

Very Large

## Deliverables

1. Offline sync request contract.
2. Canonical business payload fingerprint fields.
3. Exact replay response behavior.
4. Suspected duplicate detection beyond exact UUID replay.
5. SaleCreationService boundary confirmation.
6. Server-side transaction boundary.
7. Per-envelope consequence atomicity.
8. Consequence status schema.
9. Official identity allocation.
10. Official invoice retrieval or delivery contract.
11. Cash-collected review record handling.
12. Duplicate source-effect prevention.
13. Sync audit payload.
14. Outbox behavior where unavoidable.
15. Capture-time terminal authorization validation versus sync-time revocation handling.
16. Tests for accepted, replayed, retryable, rejected, review-required, and suspected-duplicate results.

## Out of Scope

1. Browser queue UI redesign.
2. Hardware printing.
3. Expanding offline tender types.
4. Offline void/refund implementation.

## Acceptance Checks

1. Exact replay creates no duplicate sale, payment, inventory, loyalty, or store-credit consequences.
2. Drift is rejected before mutation.
3. SaleCreationService remains the authority for committed sale creation.
4. Accepted status is returned only when required consequences are complete or explicitly represented by a durable pending state.
5. Local reference, server sale identity, and official invoice identity remain separate.
6. Consequence-specific pending states live in consequence status fields, not top-level sync status.
7. Suspected duplicate business captures enter review unless exact replay can be proven.
8. Cash-collected records that cannot safely post are preserved for support resolution.
9. Records captured before terminal/profile revocation follow explicit server policy and never auto-transfer to a replacement terminal.

## Notes

This story is the server authority checkpoint. It must not move committed sale, fiscal, inventory, loyalty, or store-credit authority into the browser.
