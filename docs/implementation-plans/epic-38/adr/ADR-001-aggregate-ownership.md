# ADR-001: Aggregate Ownership

## Status

Accepted

## Date

2026-07-14

## Decision

`DiningTicket` is the aggregate root for dining operations. Dining mutations must go through aggregate services, primarily `DiningTicketService` and specialized collaborators that preserve the aggregate boundary.

## Context

Dining operations touch tables, tickets, items, splits, revisions, timelines, audit, and checkout state. Allowing controllers or unrelated services to write directly to these records would create inconsistent state and make concurrency failures difficult to diagnose.

## Alternatives Considered

1. Let controllers update dining records directly.
2. Let each feature own its own dining mutation logic.
3. Use `DiningTicket` as the aggregate root with services as mutation entry points.

## Decision

Use `DiningTicket` as the aggregate root. Controllers validate requests and delegate. Collaborator services handle audit, timeline, revision, item mutation, split allocation, and checkout orchestration without becoming separate aggregate owners.

## Consequences

1. Mutation rules stay centralized.
2. Concurrency checks are easier to enforce.
3. Tests can target service behavior instead of scattered controller writes.
4. New restaurant modules must integrate through aggregate services rather than bypassing dining boundaries.

## Related Stories

1. Story 38.2
2. Story 38.4
3. Story 38.6
4. Story 38.7

## Related ADRs

1. ADR-005 Ticket Revision Model
2. ADR-006 Timeline vs Audit
3. ADR-009 Parent/Child Ticket Model
