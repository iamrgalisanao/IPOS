# Story 38.6: Bill Split Allocator Engine

## Status

Draft for Story Specification

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`

## Objective

Split tickets while preserving exact centavos totals.

## Dependencies

1. Story 38.2.
2. Story 38.4.
3. Story 38.5.

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
3. Split math preserves exact centavos.
4. Multi-record split operations are transactionally atomic.
5. Mutation endpoints enforce applicable guards.
6. No architecture constraints are violated.
7. Code review is approved.
8. Relevant documentation or story notes are updated.

