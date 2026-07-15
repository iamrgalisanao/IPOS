# Epic 39 Store Credit and Loyalty Ledger Implementation Guide

## 1. Status

Store Credit Complete; Loyalty Runtime Deferred

Date: 2026-07-15

This guide defines the intended execution order for Epic 39. It does not replace:

```text
docs/implementation-plans/epic-39/epic-39-architecture-lock.md
```

If this guide conflicts with the Architecture Lock, the Architecture Lock wins.

## 2. Implementation Order

Recommended order:

1. Story 39.1
2. Story 39.2
3. Story 39.3
4. Story 39.4
5. Story 39.5
6. Story 39.6
7. Story 39.7
8. Story 39.8
9. Story 39.9

Reason:

1. Customer account identity must exist before any ledger.
2. Store credit ledger must exist before refund issuance or redemption.
3. Refund issuance should precede redemption because it creates the monetary liability.
4. Admin review should follow first financial mutations.
5. Loyalty points should build on the customer/account foundation after store credit boundaries are proven.
6. Reporting should consume stable ledger events instead of temporary projections.
7. Story 39.9 implements the loyalty runtime that was deferred at Epic 39 closeout.

## 3. Story Status

| Story | Status | Owner | Sprint |
| --- | --- | --- | --- |
| 39.1 | Done | - | - |
| 39.2 | Done | - | - |
| 39.3 | Done | - | - |
| 39.4 | Done | - | - |
| 39.5 | Done | - | - |
| 39.6 | Specification Done; Runtime Deferred | - | - |
| 39.7 | Specification Done; Runtime Deferred | - | - |
| 39.8 | Done | - | - |
| 39.9 | Approved for Implementation | - | - |

## 4. Story Dependencies and Complexity

| Story | Depends On | Complexity |
| --- | --- | --- |
| 39.1 | Architecture lock | Medium |
| 39.2 | 39.1, accounting liability decision | Large |
| 39.3 | 39.2, existing `RefundService` | Large |
| 39.4 | 39.2, 39.3, existing payment recording flow | Very Large |
| 39.5 | 39.1, 39.2, 39.3, 39.4 | Medium |
| 39.6 | 39.1, 39.2, existing `SaleCreationService` finalized sale event | Large |
| 39.7 | 39.6, payment boundary decision | Large |
| 39.8 | 39.2, 39.3, 39.4, 39.6, 39.7 | Medium |
| 39.9 | 39.6, 39.7, 39.8, payment boundary decision | Very Large |

## 5. Common Definition of Done

Every story is done when:

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass where UI is touched.
4. Tenant and branch isolation are verified.
5. Ledger entries are append-only where financial or points movement occurs.
6. Derived balances are verified against ledger history.
7. Relevant audit events are verified.
8. No architecture constraints are violated.
9. Code review is approved.
10. Documentation or story notes are updated.
11. Database migrations include indexes, foreign-key behavior, and rollback verification.
12. Mutation endpoints enforce authorization and idempotency where applicable.
13. No offline mutation path is introduced for store credit or loyalty.
14. Idempotency replay and drift are tested wherever ledger entries can be created.

## 6. Story 39.1 Customer Account Foundation

Objective:

Introduce tenant-scoped customer financial accounts that can later own store credit and loyalty ledgers.

Deliverables:

1. Customer financial account schema.
2. Tenant-scoped customer/account relationships.
3. Account status model.
4. Read-only account lookup.
5. Account creation path tied to customer identity.

Out of scope:

1. Store credit balance mutation.
2. Loyalty point mutation.
3. Customer merge.
4. Redemption.

Acceptance checks:

1. Account is tenant-scoped.
2. Cross-tenant account access is blocked.
3. Account can exist without ledger entries.
4. Customer deletion/anonymization cannot destroy future ledger evidence.

## 7. Story 39.2 Append-Only Store Credit Ledger

Objective:

Create the monetary store credit ledger and derived balance calculation.

Deliverables:

1. Store credit ledger schema.
2. Integer centavo amounts.
3. Ledger entry types.
4. Derived balance service.
5. Ledger immutability guards.
6. Account-scoped ledger sequence support.
7. Accounting liability event/outbox contract shape.

Out of scope:

1. Refund issuance.
2. Redemption.
3. Loyalty points.
4. Accounting export.
5. External accounting provider delivery, credentials, or provider-specific chart-of-account mapping.

Acceptance checks:

1. Credit and debit entries derive expected balance.
2. Ledger rows cannot be edited or deleted after posting.
3. Negative balance is blocked unless explicitly approved by architecture revision.
4. Balance can be rebuilt from ledger history.
5. Repeated rebuilds produce identical balances.
6. Ledger sequence ordering is deterministic per account.
7. Ledger snapshots include schema version metadata.

## 8. Story 39.3 Store Credit Refund Issuance

Objective:

Allow eligible refunds to issue store credit through the existing refund authority.

Deliverables:

1. Refund-to-store-credit issuance flow.
2. Source refund reference on ledger entry.
3. Supervisor authorization where required.
4. Audit and receipt/reporting note.

Out of scope:

1. Store credit redemption.
2. Standalone credit issuance unrelated to refunds.
3. Offline refund credit issuance.

Acceptance checks:

1. Store credit issuance cannot bypass `RefundService`.
2. Refund and ledger write are transactionally safe.
3. Duplicate issuance for the same refund is blocked.
4. Closed-shift and refund rules remain enforced.
5. Repeated refund replay does not create duplicate credit entries.
6. Accounting event/outbox evidence is created with the store credit issuance when required by the liability contract.

## 9. Story 39.4 Store Credit Redemption

Objective:

Allow store credit to be used as controlled payment tender through the existing POS payment flow.

Deliverables:

1. Store credit tender integration.
2. Ledger debit on successful payment.
3. Idempotency and insufficient-balance checks.
4. Payment, sale, receipt, and future reversal-source snapshot references.

Out of scope:

1. Offline redemption.
2. Loyalty point redemption.
3. Gift cards.
4. Bypassing payment recording services.

Acceptance checks:

1. Redemption cannot bypass payment authority.
2. Debit ledger entry is created only for successful payment.
3. Failed payment does not debit store credit.
4. Store credit tender appears in payment history/reporting as appropriate.
5. Duplicate payment replay does not create duplicate debit entries.
6. The authorized available balance snapshot is preserved for the redemption.

## 10. Story 39.5 Store Credit Admin Review

Objective:

Provide authorized admin review and audit surfaces for store credit accounts and ledger history.

Deliverables:

1. Account balance view.
2. Ledger history table.
3. Source transaction links.
4. Adjustment request/review policy as documentation only unless separately approved.
5. Audit evidence.

Out of scope:

1. Bulk balance edits.
2. Unapproved manual credit/debit adjustment.
3. Customer self-service wallet.
4. Full adjustment execution workflow unless separately approved.

Acceptance checks:

1. Admin can inspect ledger entries without mutating them.
2. Adjustment policy is visible as review guidance only unless a later approved story implements execution.
3. Cross-tenant/customer leakage is blocked.
4. Operational views distinguish customer account history from financial liability reporting.

## 11. Story 39.6 Loyalty Ledger

Objective:

Introduce loyalty point accrual as a separate append-only ledger.

Deliverables:

1. Loyalty ledger schema.
2. Points accrual rules for eligible paid sales.
3. Derived point balance.
4. Point ledger immutability.

Out of scope:

1. Redemption.
2. Store credit conversion.
3. Customer segmentation.
4. Cashback campaigns.

Acceptance checks:

1. Points are earned only from eligible finalized sales.
2. Points do not alter sale totals.
3. Points ledger is separate from store credit ledger.
4. Voids/refunds reverse or adjust points through append-only entries.
5. The same finalized sale cannot accrue points twice.
6. Accrual entries snapshot the earning rule version.

## 12. Story 39.7 Loyalty Redemption

Objective:

Allow loyalty points to be redeemed through explicit rules without treating points as cash by default.

Deliverables:

1. Redemption rule model.
2. Points debit ledger entry.
3. Approval/customer verification where required.
4. Checkout integration through approved discount/payment boundary.

Out of scope:

1. Offline redemption.
2. Store credit equivalence without rule.
3. Marketing campaign engine.

Acceptance checks:

1. Points cannot be redeemed without an active rule.
2. Redemption cannot create negative point balance.
3. Redemption is auditable and reversible through append-only entries.
4. Rule version used for redemption is snapshotted.
5. Redemption replay does not create duplicate point debit entries.

## 13. Story 39.8 Reporting and Reconciliation

Objective:

Provide liability, redemption, and loyalty reporting for operations and accounting review.

Deliverables:

1. Store credit liability report.
2. Store credit issuance/redemption report.
3. Loyalty accrual/redemption report.
4. CSV export if approved.
5. Settlement/accounting reconciliation notes.

Out of scope:

1. Official compliance certification format.
2. Scheduled report automation.
3. Accounting provider transport changes.

Acceptance checks:

1. Store credit liability is derived from ledger.
2. Reports are tenant/branch scoped.
3. Exports avoid CSV injection.
4. Reports do not mutate ledger state.
5. Liability totals reconcile to ledger entries.
6. Operational reports and financial reports are separated by purpose and audience.
