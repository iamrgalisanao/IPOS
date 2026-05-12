# Story 9.1: Settlement / Reconciliation Scope Lock and Architecture

Date: 2026-05-12
Status: Planning Only
Implementation Scope: Not Started

## Goal

Define the reconciliation model before implementation so IPOS can compare POS financial activity, payment capture, accounting sync state, and future settlement reporting without mixing reconciliation behavior into checkout or QuickBooks sync execution.

This story is intentionally architecture-first. It does not implement reconciliation logic, posting rules, auto-matching, settlement reports, or accounting automation.

## Scope Lock

This story defines only:

- reconciliation definitions
- settlement period model
- source-of-truth rules
- variance categories
- approval and lock rules
- audit trail requirements
- reporting boundaries

This story explicitly does not implement:

- reconciliation calculations
- QuickBooks balance import or statement import
- auto-match or auto-close logic
- settlement posting workflows
- cashier-facing settlement status
- payout gateway settlement adapters
- accounting report generation
- automated adjustment or journal creation

## Why This Is The Next Phase

Epic 8 completed the accounting foundation layer:

- immutable accounting outbox capture
- processor and retry controls
- persisted accounting mappings
- sync dashboard and manual retry surface
- QuickBooks connection onboarding and token security

The next financial layer should be a review-and-control surface that interprets operational and sync data without mutating checkout behavior. Reconciliation must therefore sit above POS and sync infrastructure, not inside them.

## Definitions

### Settlement Period

A settlement period is a tenant-scoped financial review window with optional branch scope. It represents the timebox over which IPOS summarizes sales, payment intake, refund activity, void activity, and accounting sync readiness.

Initial conceptual fields:

- `id`
- `tenant_id`
- `branch_id` nullable for tenant-wide periods
- `period_start_at`
- `period_end_at`
- `status`
- `opened_by`
- `opened_at`
- `submitted_by` nullable
- `submitted_at` nullable
- `approved_by` nullable
- `approved_at` nullable
- `locked_by` nullable
- `locked_at` nullable
- `reopened_by` nullable
- `reopened_at` nullable
- `closing_notes` nullable
- `metadata` nullable

### Reconciliation Snapshot

A reconciliation snapshot is a derived, point-in-time summary attached to a settlement period. It does not replace source records. It preserves what the reviewer saw when the period was reviewed or locked.

Initial conceptual summary fields:

- gross sales total
- net sales total
- refund total
- void total
- payment totals by payment method
- outbox counts by status
- QuickBooks sync success and failure counts
- variance totals by category

### Variance

A variance is any mismatch between expected financial state and observed operational or sync state for a settlement period.

### Source Record

A source record is an append-only operational or integration record used to derive settlement and reconciliation views. Examples include:

- `sales`
- `sale_payments`
- `sale_refunds`
- `sale_voids`
- `accounting_outbox`
- `accounting_sync_attempts`

## Source-of-Truth Rules

### POS Transaction Truth

The POS system of record remains the append-only operational ledger:

- sale totals come from `sales`
- payment capture comes from `sale_payments`
- refunds come from `sale_refunds`
- void reversals come from `sale_voids`

Settlement must not use QuickBooks responses as the source of truth for whether a sale occurred or whether a payment was captured.

### Accounting Sync Truth

The accounting sync system of record remains:

- `accounting_outbox` for event existence and current sync status
- `accounting_sync_attempts` for retry history, failure chronology, and operator review context

Settlement must treat QuickBooks sync as an integration status layer, not a replacement financial ledger inside IPOS.

### Mapping Truth

Mapping readiness remains sourced from persisted accounting mappings. Reconciliation may later report mapping readiness gaps, but it must not create or modify mappings automatically.

### Connection Truth

QuickBooks connectivity remains sourced from `quickbooks_connections`. Reconciliation may surface connection state as operational context, but it must not own token lifecycle or OAuth behavior.

### Branch and Tenant Rollup Truth

Branch summaries are derived from branch-scoped source records.

Tenant-wide summaries are derived by rolling up all permitted branch records plus any tenant-level records. Branch filtering must not depend on ambient branch context alone; it must follow explicit branch access rules from the authenticated user and route surface.

## Settlement Period Model

### Proposed Statuses

- `open`: period is collecting activity and can be reviewed
- `in_review`: reviewer is actively assessing variances
- `approved`: reviewer has accepted the summary but period is not yet locked
- `locked`: period is final for routine operations
- `reopened`: previously locked period was explicitly reopened through an audited exception flow

### Period Scope Rules

- A settlement period belongs to one tenant.
- A period may be tenant-wide or branch-specific.
- Branch-specific periods may only include records for the assigned branch.
- Tenant-wide periods may aggregate across branches only for users with tenant-wide visibility.
- A branch-scoped reviewer must not lock or approve a tenant-wide period unless explicitly granted broader authority.

### Period Boundary Rules

- Records are included based on business event timestamps, not review timestamps.
- Late-arriving sync outcomes do not rewrite the historical source transaction; they create reconciliation variances against the relevant period.
- Reopened periods must preserve their previous locked snapshot and record the reason for reopening.

## Variance Categories

Initial variance taxonomy:

- `timing_gap`: transaction exists in POS but sync is still pending or processing
- `sync_failure`: transaction failed accounting sync
- `mapping_gap`: required accounting mapping was missing or inactive
- `connection_gap`: QuickBooks connection was disconnected, expired, or errored during the period
- `payment_mismatch`: recorded payments do not match expected settlement totals by method
- `refund_mismatch`: refund totals differ from expected reversal totals
- `void_mismatch`: void reversals are incomplete or out of balance
- `branch_scope_mismatch`: transaction or review activity appears outside the allowed branch scope
- `manual_review_required`: anomaly exists but cannot yet be automatically categorized with confidence

These categories are intentionally review-oriented. They describe the reason a period is not clean without implying automated correction.

## Approval and Lock Rules

### Approval Rules

- Only authorized accounting/admin roles may move a period into `approved`.
- Approval must record actor, timestamp, and reviewer notes.
- Approval does not mutate operational or outbox records.

### Lock Rules

- `locked` periods are final for normal workflows.
- Locking freezes the reconciliation snapshot, not the underlying append-only source records.
- New activity discovered after lock is represented as a late variance or carried into a future period, not by rewriting the prior period's source transactions.
- Reopening a locked period requires an explicit reason and audited actor trail.

### Review Safety Rules

- Settlement and reconciliation must remain read-mostly over source transactions.
- The feature must not alter POS totals, payment records, inventory records, refunds, voids, mappings, or outbox payloads.
- Manual reviewer annotations may be stored separately from source financial records.

## Audit Trail Requirements

The future reconciliation layer must log, at minimum:

- period opened
- period status changed
- period approved
- period locked
- period reopened
- reviewer notes added or amended
- variance classification overridden
- export requested
- report viewed if needed for sensitive closure actions

Audit records must include:

- tenant context
- branch context when applicable
- actor user id
- action name
- before and after values where status or classification changes
- reason and remarks where relevant
- timestamps

Audit logging must never store:

- QuickBooks access tokens
- refresh tokens
- OAuth headers
- bearer tokens
- client secrets
- provider private keys

## Reporting Boundaries

This future layer may present:

- period summary totals
- payment method summaries
- sync readiness summaries
- variance counts and totals
- branch or tenant rollups

This layer must not, in its initial phase:

- expose raw provider credentials
- expose cashier-facing reconciliation state
- auto-create accounting mappings
- auto-close periods without approval
- auto-post journals or accounting adjustments
- trigger QuickBooks sync from review actions

## Architecture Direction

### Layering Rule

Reconciliation is a financial review layer above:

- POS transaction capture
- payment recording
- refund and void logic
- accounting outbox capture
- QuickBooks connectivity and sync processing

It must consume those layers through stable, read-oriented services rather than embed new financial mutation rules into checkout.

### Suggested Service Boundaries

Future implementation should likely separate:

- `SettlementPeriodService` for lifecycle and scope validation
- `SettlementSnapshotService` for derived summary generation
- `SettlementVarianceService` for categorization and rollup
- `SettlementReportQueryService` for read models and filtering
- `SettlementAuthorizationPolicy` or equivalent controller/service guard for approval and lock actions

### Suggested Persistence Shape

Future implementation will likely need dedicated tables similar to:

- `settlement_periods`
- `settlement_snapshots`
- `settlement_variances`
- `settlement_actions` or audited state transitions

That persistence should remain additive and append-friendly, consistent with the project's financial immutability posture.

## Suggested Story Follow-On Sequence

After Story 9.1, the implementation sequence should likely be:

1. Settlement period schema and lifecycle foundation
2. Read-only reconciliation summary query service
3. Variance classification and summary surface
4. Approval and lock workflow with audit trail
5. Export and reporting boundaries

## Story 9.1 Acceptance Output

This planning story is complete when the project has a documented agreement on:

- reconciliation definitions
- settlement period statuses and scope rules
- source-of-truth hierarchy
- variance taxonomy
- approval and lock semantics
- audit trail requirements
- reporting boundaries
- explicit non-goals

## Final Recommendation

The next major phase should begin with settlement and reconciliation planning, not additional QuickBooks plumbing, unless client priorities explicitly change. The architecture should preserve the separation between transactional capture, accounting sync execution, and financial review controls.