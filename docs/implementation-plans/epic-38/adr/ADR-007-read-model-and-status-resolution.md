# ADR-007: Read Model and Status Resolution

## Status

Accepted

## Date

2026-07-14

## Decision

Dining floor status is derived by read models and resolvers. Operational presentation state is not treated as aggregate authority.

## Context

POS floor maps need fast, clear status indicators. However, table status depends on service area state, table activation, active ticket mappings, ticket status, offline state, and layout revision. Persisting runtime status directly would risk drift.

## Alternatives Considered

1. Persist table status directly on every mutation.
2. Compute all floor status ad hoc in controllers.
3. Use a resolver/read-model layer for presentation state.

## Decision

Use read models and status resolvers for floor-map presentation. Occupancy and payment progress are derived from authoritative tables and tickets. Layout revision and occupancy/ticket revision remain separate concepts.

## Consequences

1. Floor-map rendering stays fast and consistent.
2. Runtime status does not become a second source of truth.
3. Cached floor maps can be shown offline as informational views.
4. Mutations continue to target aggregate state, not presentation state.

## Related Stories

1. Story 38.3
2. Story 38.8

## Related ADRs

1. ADR-002 Online-Only Dining Policy
2. ADR-005 Ticket Revision Model
3. ADR-009 Parent/Child Ticket Model
