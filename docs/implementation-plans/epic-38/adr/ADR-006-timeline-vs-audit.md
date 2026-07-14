# ADR-006: Timeline vs Audit

## Status

Accepted

## Date

2026-07-14

## Decision

Timeline, audit, and revision history remain separate records with separate purposes.

## Context

Operators, support teams, and compliance reviewers ask different questions. A single event log would either expose too much detail to operators or omit compliance and diagnostic information needed later.

## Alternatives Considered

1. Use audit logs as the operator timeline.
2. Use timeline events as compliance audit.
3. Maintain separate timeline, audit, and revision records.

## Decision

Use timeline events for operator-readable history, audit records for compliance and accountability, and revision history for state snapshots, diagnostics, and concurrency support.

## Consequences

1. Operator screens can stay readable.
2. Compliance payloads can remain complete and structured.
3. Revision snapshots can support debugging without replacing audit.
4. Mutations that affect dining state must write the relevant records together.

## Related Stories

1. Story 38.5
2. Story 38.6
3. Story 38.7

## Related ADRs

1. ADR-001 Aggregate Ownership
2. ADR-005 Ticket Revision Model
