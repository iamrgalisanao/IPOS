# ADR-039 Reporting and Reconciliation

Status: Approved

Date: 2026-07-15

## Context

Epic 39 introduces store credit and loyalty capabilities through customer financial accounts, append-only ledgers, derived balances, refund issuance, store credit redemption, admin review, loyalty accrual, and loyalty redemption.

After those mutation stories, the platform needs operational and financial visibility without creating another source of truth. Reports must explain customer balances, outstanding store credit liability, loyalty activity, and reconciliation exceptions while preserving the Epic 39 architectural constraints.

## Decision

Epic 39 reporting and reconciliation will be a read-side-only capability.

Reports may read ledger rows, deterministic projections, source transaction records, and accounting outbox evidence, but they must not mutate:

1. customer financial accounts;
2. store credit ledger entries;
3. loyalty ledger entries;
4. refund, sale, payment, or receipt records;
5. accounting outbox rows;
6. audit records.

Store credit financial liability is derived exclusively from the append-only store credit ledger. Loyalty reporting is derived exclusively from the loyalty ledger. Store credit and loyalty must remain separate domains and must never be combined into a single wallet, money-equivalent balance, or accounting liability total.

## Rationale

Ledger rows are the authoritative evidence for monetary and points movement. Reporting from ledgers keeps operational views, accounting views, and support diagnostics consistent with the mutation boundaries established by Stories 39.1 through 39.7.

Keeping reports read-only prevents reconciliation screens from becoming hidden repair workflows. Corrections must continue to use approved append-only reversal or adjustment mechanisms from their owning services.

## Consequences

1. Reports can answer operational questions without changing business state.
2. Store credit liability reports must group totals by currency and must never create cross-currency grand totals.
3. Loyalty reports must present integer points only and must not imply monetary liability.
4. Projections are allowed only when rebuildable, versioned, freshness-aware, and non-authoritative.
5. Reconciliation reports can surface pending, failed, missing, or mismatched evidence, but they cannot retry exports or repair records.
6. Report responses should include report schema version, report instance id, basis metadata, deterministic ordering, and projection freshness metadata where applicable.
7. CSV exports must preserve report authorization and protect against CSV injection.

## Alternatives Considered

1. Use mutable balance snapshots as report authority.
   - Rejected because it creates a second financial source of truth.
2. Use accounting outbox rows as primary liability totals.
   - Rejected because accounting outbox rows are reconciliation evidence, not the source ledger.
3. Combine store credit and loyalty into a customer wallet report.
   - Rejected because store credit is monetary liability and loyalty is non-monetary rewards activity.
4. Add repair and retry actions directly to reconciliation reports.
   - Rejected because repairs and retries belong to owning services and accounting workflows, not read-side reports.

## Related Artifacts

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.8-reporting-and-reconciliation.md`
