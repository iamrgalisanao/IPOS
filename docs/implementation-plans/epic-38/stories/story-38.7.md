# Story 38.7: Partial Payments and Ticket Split Checkout Integration

## Status

Draft for Story Specification

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`

## Objective

Convert split tickets into sale/payment flows without bypassing existing POS controls.

## Dependencies

1. Story 38.6.
2. Story 38.8.
3. Existing `SaleCreationService` flow.
4. Existing payment and shift guard behavior.

## Technical Approach

TBD during story specification.

## Database Migrations

TBD during story specification.

## API Contracts

TBD during story specification.

## UI Notes

TBD during story specification.

## Test Cases

TBD during story specification.

## Rollout Plan

TBD during story specification.

## Rollback Considerations

TBD during story specification.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass, where the story touches UI.
4. Checkout idempotency is verified.
5. Existing sale/payment authority is not bypassed.
6. No architecture constraints are violated.
7. Code review is approved.
8. Relevant documentation or story notes are updated.

