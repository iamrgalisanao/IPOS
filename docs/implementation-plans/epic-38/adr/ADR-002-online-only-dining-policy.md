# ADR-002: Online-Only Dining Policy

## Status

Accepted

## Date

2026-07-14

## Decision

Dining mutations require online server authority. Offline dining mutations are prohibited. Cached floor maps are informational only.

## Context

Dining tickets are long-lived, collaborative, and concurrency-sensitive. Multiple terminals may view or attempt to mutate the same table or ticket. Offline mutation queues would introduce conflicts around table occupancy, split allocation, checkout, and audit ordering.

## Alternatives Considered

1. Permit offline dining mutations and reconcile later.
2. Permit only selected offline dining mutations.
3. Prohibit offline dining mutations and keep offline sales separate.

## Decision

Prohibit offline dining mutations. The existing offline direct-sale POS flow remains separate and may continue to support walk-in cash sales. Dining floor-map cache is read-only while offline.

## Consequences

1. Dining state remains server-authoritative.
2. Table occupancy and ticket revisions avoid offline conflict merges.
3. Cashiers need clear online-required messaging for dining actions.
4. Future offline dining support would require a new architecture review.

## Related Stories

1. Story 38.3
2. Story 38.8

## Related ADRs

1. ADR-005 Ticket Revision Model
2. ADR-007 Read Model and Status Resolution
3. ADR-010 Checkout Boundary
