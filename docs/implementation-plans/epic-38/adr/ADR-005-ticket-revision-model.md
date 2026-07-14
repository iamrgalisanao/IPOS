# ADR-005: Ticket Revision Model

## Status

Accepted

## Date

2026-07-14

## Decision

Every successful dining mutation increments `ticket_revision`. Failed, rejected, or no-op operations do not increment it. Clients send expected revisions for concurrency-sensitive mutations.

## Context

Multiple terminals may interact with the same floor map and ticket. Without optimistic concurrency, stale terminals can overwrite newer dining state or create duplicate settlement operations.

## Alternatives Considered

1. Rely only on last-write-wins updates.
2. Use only database row locks without client revision checks.
3. Combine row locks with optimistic revisions.

## Decision

Use `ticket_revision` as the optimistic concurrency contract and row locks for critical server-side mutation paths. Revision snapshots also support audit, diagnostics, and read-model synchronization.

## Consequences

1. Stale terminal requests are rejected with refreshable conflicts.
2. Successful mutation ordering is visible in revision history.
3. Read models can detect and refresh stale ticket summaries.
4. Tests must assert both mutation success and stale-request rejection.

## Related Stories

1. Story 38.2
2. Story 38.4
3. Story 38.5
4. Story 38.6
5. Story 38.7

## Related ADRs

1. ADR-001 Aggregate Ownership
2. ADR-006 Timeline vs Audit
3. ADR-007 Read Model and Status Resolution
