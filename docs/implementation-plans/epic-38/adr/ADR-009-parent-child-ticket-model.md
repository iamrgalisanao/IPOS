# ADR-009: Parent/Child Ticket Model

## Status

Accepted

## Date

2026-07-14

## Decision

After split, the parent ticket is historical and orchestration state. Child tickets become the payable units. Parent settlement progress is derived from child ticket state.

## Context

Split bills need independent payment while keeping a clear relationship to the original table ticket. Storing mutable parent payment counters would introduce drift risk when child checkout succeeds, fails, or is retried.

## Alternatives Considered

1. Keep all split payments on the parent ticket.
2. Store mutable paid counters on the parent.
3. Create child tickets and derive parent settlement from children.

## Decision

Create child tickets for payable checks. Keep the parent as the original ticket reference and settlement container. Derive paid count, remaining count, paid centavos, and remaining centavos from child tickets and linked sales.

## Consequences

1. Each child bill can be checked out independently.
2. Parent settlement cannot drift from child truth.
3. Parent closure occurs only after all payable child tickets close.
4. Reports can trace both original ticket context and child payment outcomes.

## Related Stories

1. Story 38.6
2. Story 38.7

## Related ADRs

1. ADR-003 Bill Split Model
2. ADR-007 Read Model and Status Resolution
3. ADR-010 Checkout Boundary
