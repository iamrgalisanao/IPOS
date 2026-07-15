# Story 39.6 Loyalty Ledger

## 1. Status

Approved for Implementation

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
5. `docs/implementation-plans/epic-39/stories/story-39.3-store-credit-refund.md`
6. `docs/implementation-plans/epic-39/stories/story-39.4-store-credit-redemption.md`
7. `docs/implementation-plans/epic-39/stories/story-39.5-store-credit-admin-review.md`
8. `app/Models/CustomerFinancialAccount.php`
9. `app/Models/StoreCreditLedgerEntry.php`
10. `app/Services/StoreCredit/StoreCreditLedgerService.php`
11. `app/Services/StoreCredit/StoreCreditBalanceService.php`
12. `app/Services/POS/SaleCreationService.php`
13. `app/Services/POS/PaymentRecordingService.php`
14. `app/Services/POS/VoidService.php`
15. `app/Services/POS/RefundService.php`
16. `app/Models/Sale.php`
17. `app/Models/SaleItem.php`
18. `app/Models/SaleVoid.php`
19. `app/Models/SaleRefund.php`

## 3. Objective

Introduce the loyalty points ledger and accrual foundation as a separate append-only points domain under the customer financial account.

This story establishes point earning only. It must not introduce loyalty redemption, store credit conversion, customer wallet UI, third-party loyalty provider integration, automatic expiration, customer segmentation, cashback campaigns, or points-as-cash behavior.

## 4. User Story

As a merchant,
I want eligible finalized sales to earn loyalty points in an immutable ledger,
so that customers can accumulate auditable rewards without affecting sale totals, store credit balances, accounting liability, or payment workflows.

## 5. Locked Decisions

1. Loyalty points are not money.
2. Loyalty points must never share the store credit ledger.
3. Loyalty points must never be stored in `store_credit_ledger_entries`.
4. Loyalty point balances are derived from append-only `loyalty_ledger_entries`.
5. `CustomerFinancialAccount` groups the customer identity for store credit and loyalty, but it does not store authoritative mutable point balances.
6. `LoyaltyLedgerService` owns point ledger posting.
7. `LoyaltyBalanceService` owns derived point balance calculation from loyalty ledger entries.
8. `LoyaltyAccrualService` owns finalized-sale eligibility and earning-rule evaluation.
9. `LoyaltyRuleService` owns eligibility calculation, awarded points calculation, and immutable rule snapshots.
10. `LoyaltyAccrualResult` is the handoff object from rule evaluation into accrual posting.
11. Controllers, seeders, tests, sale services, refund services, and UI components must not write loyalty ledger rows directly.
12. Sale creation, payment recording, voiding, refunding, inventory, receipts, and accounting remain owned by their existing services.
13. Loyalty accrual must not alter sale totals, taxes, discounts, promotions, statutory discount evidence, receipt totals, payment totals, store credit ledgers, inventory, or accounting outbox rows.
14. Points accrue only from eligible finalized paid sales.
15. Training sales create no loyalty ledger entry.
16. Offline captured sales do not earn redeemable points until server reconciliation validates eligibility.
17. The same source sale may create at most one accrual credit entry for one customer financial account.
18. Exact accrual replay must return the original ledger entry without posting duplicate points.
19. Idempotency drift must be rejected before ledger mutation.
20. Accrual entries must snapshot the earning rule version, rule schema version, and material sale inputs.
21. Story 39.6 defines reversal entry types and ledger posting capability, but automatic void/refund hooks are deferred unless a later approved implementation explicitly adds them.
22. Loyalty redemption remains out of scope until Story 39.7.
23. Negative loyalty point balances are prohibited unless a later architecture revision explicitly approves them.
24. Loyalty expiration is a reserved ledger concept only; automated execution is out of scope.
25. Loyalty point visibility requires a permission appropriate to POS or admin context.
26. Loyalty does not create monetary accounting liability outbox events in Story 39.6.

## 6. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Append-Only Store Credit Ledger, for proven ledger infrastructure conventions.
3. Existing `SaleCreationService` and `PaymentRecordingService` sale lifecycle.
4. Existing `VoidService` and `RefundService` reversal boundaries.
5. Existing `CustomerFinancialAccount` tenant-scoped identity.
6. Existing tenant, branch, user, sale, sale item, void, and refund models.

## 7. Current Codebase Context

Existing customer account context:

1. `CustomerFinancialAccount` is tenant-scoped and customer-owned.
2. `CustomerFinancialAccount` has immutable customer ownership.
3. `CustomerFinancialAccount` has immutable store credit currency.
4. Accounts can be `active`, `suspended`, or `closed`.
5. Customer anonymization preserves account linkage.

Existing store credit ledger context to mirror:

1. `StoreCreditLedgerEntry` is append-only.
2. Store credit ledger rows use account-scoped `ledger_sequence`.
3. Store credit ledger posting is service-owned.
4. Store credit derived balance is replayed from ledger rows.
5. Store credit ledger posting enforces idempotency replay and drift detection.
6. Store credit ledger posting snapshots source evidence and schema versions.
7. Store credit ledger tests prove immutability, idempotency, isolation, non-negative balance, and no unrelated side effects.

Existing sale lifecycle context:

1. `SaleCreationService` creates sales and sale items with `status = created`.
2. `PaymentRecordingService` marks sales as `paid` after successful payment.
3. `PaymentRecordingService` records payment audit evidence and `sale_paid` accounting outbox evidence.
4. `VoidService` changes paid sales to `voided` and creates `SaleVoid` evidence.
5. `RefundService` creates `SaleRefund` evidence and changes sale status to `partially_refunded` or `refunded`.
6. Store credit refund and redemption already integrate through existing source authorities rather than bypassing them.

Implementation implication:

Story 39.6 should introduce loyalty services and ledger tables first, then integrate accrual through an explicit service call at the finalized-sale boundary. It should not put point logic inside controllers or directly mutate sales, payments, refunds, voids, or store credit records.

## 8. Loyalty Domain Invariants

1. Points are not money.
2. Points never enter store credit.
3. Points never affect sale totals.
4. Points never affect payment totals.
5. Points never create monetary accounting liability.
6. Loyalty accrual owns point creation.
7. Loyalty rule evaluation owns point calculation.
8. Loyalty ledger rows are immutable.
9. Loyalty balances are derived from ledger rows.
10. Available points equals the derived ledger balance for Story 39.6.
11. One finalized eligible sale creates at most one accrual entry for one customer financial account.
12. Replay never duplicates points.
13. Idempotency drift never posts points.
14. Rule snapshots are immutable.
15. Rule schema versions are snapshotted with rule versions.
16. Training sales create no ledger entry.
17. Store credit and loyalty ledgers use separate tables, services, balances, sequences, and source snapshots.

## 9. Domain Scope

In scope:

1. `loyalty_ledger_entries` schema.
2. Durable account-scoped loyalty ledger sequence source.
3. `LoyaltyLedgerEntry` model with append-only guards.
4. `LoyaltyLedgerSequence` model or equivalent sequence allocator.
5. `LoyaltyLedgerService` for point posting, idempotency, source uniqueness, and non-negative balance checks.
6. `LoyaltyBalanceService` or equivalent derived point balance calculation.
7. `LoyaltyAccrualService` for eligible finalized sale accrual.
8. `LoyaltyRuleService` for eligibility and point calculation.
9. `LoyaltyAccrualResult` value object for rule evaluation output.
10. Rule snapshot for earning rate/version/schema version.
11. Accrual source snapshot from sale, customer financial account, branch, business date, and eligible amount.
12. Idempotent replay and drift detection.
13. Duplicate source sale protection with a dedicated domain exception.
14. Append-only reversal entry types and ledger posting capability for future void/refund adjustment.
15. After-commit accrual integration from finalized paid sales.
16. Backend feature tests for schema, immutability, accrual, idempotency, duplicate prevention, non-negative balance, tenant isolation, and no store-credit/payment/accounting side effects.
17. Permission seed for loyalty balance visibility if an endpoint/read surface is added in this story.

Out of scope:

1. Loyalty point redemption.
2. Treating points as tender.
3. Treating points as store credit.
4. Store credit conversion.
5. Customer self-service wallet.
6. Third-party loyalty provider integration.
7. Customer segmentation.
8. Cashback campaigns.
9. Automatic expiration execution.
10. Automatic forfeiture execution.
11. Gift cards.
12. Monetary accounting liability for points.
13. Customer merge.
14. Manual loyalty admin adjustments.
15. Offline loyalty redemption.
16. Offline point accrual before server reconciliation validates the sale.

## 10. Loyalty Ledger Invariants

1. Points are integer values only.
2. Points are not centavos.
3. Points have no currency code.
4. Credit entries increase derived point balance.
5. Debit entries decrease derived point balance.
6. Accrual entries are credits.
7. Redemption entries are out of scope until Story 39.7.
8. Void/refund reversal entries must be append-only.
9. Ledger rows cannot be updated.
10. Ledger rows cannot be deleted.
11. Ledger sequence is monotonic per customer financial account for the loyalty ledger.
12. Sequence assignment occurs in the same transaction as ledger posting.
13. Derived point balance equals posted credits minus posted debits.
14. Derived point balance must not go below zero.
15. Exact idempotency replay returns the original ledger entry.
16. Idempotency drift rejects the request.
17. One finalized sale source can produce at most one accrual entry for an account.
18. Ledger snapshots are historical evidence and must not be recalculated from mutable sale records.

## 11. Loyalty Entry Types

Approved entry types for Story 39.6:

```text
sale_accrual
refund_reversal
void_reversal
redemption_debit
admin_points_credit
admin_points_debit
expiration_debit
```

Only `sale_accrual`, `void_reversal`, and `refund_reversal` may be executable in Story 39.6.

Reserved entry types may exist as constants for forward compatibility, but no endpoint, UI, seeder, or service path may execute them without a separately approved story.

Recommended categories:

```text
credit
debit
adjustment
reversal
expiration
```

Recommended directions:

```text
credit
debit
```

## 12. Earning Rule Contract

Story 39.6 should use a simple versioned earning rule that can evolve later.

Recommended first rule:

```text
rule_id: stable-rule-identifier
rule_code: base_sale_points
rule_version: 1
rule_schema_version: 1
earning_basis: eligible_paid_sale_total
points_per_currency_unit: configurable or fixed default
rounding: floor
minimum_eligible_amount_centavos: optional
```

Rules:

1. Stable rule identity must be snapshotted on every accrual entry.
2. Rule code must be snapshotted on every accrual entry.
3. Rule version must be snapshotted on every accrual entry.
4. Rule schema version must be snapshotted on every accrual entry.
5. The rule snapshot must include stable rule ID, rule code, earning basis, rate, rounding mode, excluded sale flags, rule schema version, and ledger schema version.
6. Changing future earning rules must not mutate historical ledger rows.
7. Renaming a rule code must not break historical ledger interpretation because the stable rule ID remains snapshotted.
8. Sale promotions, statutory discounts, and final paid total are already resolved before accrual and must not be recalculated by loyalty.
9. Story 39.6 may start with a conservative fixed rule if no admin rule UI exists yet.
10. Admin rule management UI is out of scope unless separately approved.

Recommended value object:

```text
LoyaltyAccrualResult
```

Fields:

```text
eligible
points
eligible_amount_centavos
rule_id
rule_code
rule_version
rule_schema_version
rounding
reason
calculation_metadata
rule_snapshot
```

Rules:

1. `LoyaltyRuleService` returns `LoyaltyAccrualResult`.
2. `LoyaltyAccrualService` consumes `LoyaltyAccrualResult`.
3. Ineligible sales return a result with `eligible = false` and `points = 0`.
4. Ineligible sales create no ledger entry.
5. Training sales return an ineligible result and create no ledger entry.
6. `calculation_metadata` is immutable diagnostic JSON and must not contain mutable transport metadata.

## 13. Accrual Source Contract

Accrual is based on finalized eligible sales.

A sale is eligible when:

1. Sale belongs to the active tenant.
2. Sale has a linked customer financial account.
3. Sale is finalized as paid.
4. Sale is not training mode.
5. Sale is not voided.
6. Sale is not refunded at the time of accrual.
7. Sale was not captured offline unless server reconciliation has validated eligibility.
8. Sale has not already produced a loyalty accrual source entry for the account.

Recommended accrual command:

```text
customer_financial_account_id
sale_id
branch_id
business_date
eligible_amount_centavos
points
rule_id
rule_code
rule_version
rule_schema_version
idempotency_key
source_snapshot
```

Source uniqueness must represent the business event being accrued, not a generic object reference. For sale accrual, use:

```text
source_type: sale_paid
source_id: sale_id
```

Future accrual sources must define their own event-specific `source_type` before posting ledger entries.

Recommended source snapshot:

```json
{
  "snapshot_version": 1,
  "ledger_schema_version": 1,
  "rule_version": 1,
  "source": "sale_paid",
  "sale_id": "sale-uuid",
  "sale_number": "INV-0001",
  "branch_id": "branch-uuid",
  "customer_financial_account_id": "account-uuid",
  "eligible_amount_centavos": 125000,
  "points_awarded": 125,
  "rule_id": "base-sale-points-v1",
  "rule_code": "base_sale_points",
  "rule_schema_version": 1,
  "rounding": "floor",
  "is_training_mode": false,
  "offline_sales_import_id": null
}
```

## 14. Data Model

### 14.1 `loyalty_ledger_sequences`

Purpose:

Durable sequence allocator for account-scoped loyalty ledger ordering.

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
customer_financial_account_id UUID NOT NULL
next_sequence UNSIGNED BIGINT NOT NULL DEFAULT 1
created_at TIMESTAMP
updated_at TIMESTAMP
```

Constraints:

1. Unique `(tenant_id, customer_financial_account_id)`.
2. Foreign key to `customer_financial_accounts`.
3. Sequence allocation must lock the row during posting.

### 14.2 `loyalty_ledger_entries`

Purpose:

Immutable point movement ledger.

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
branch_id UUID NULL
customer_financial_account_id UUID NOT NULL
ledger_sequence UNSIGNED BIGINT NOT NULL
ledger_schema_version UNSIGNED SMALLINT NOT NULL DEFAULT 1
ledger_category STRING NOT NULL
entry_type STRING NOT NULL
direction STRING NOT NULL
points UNSIGNED BIGINT NOT NULL
source_type STRING NOT NULL
source_id UUID NULL
source_reference STRING NULL
source_snapshot JSON NOT NULL
idempotency_key STRING NOT NULL
request_fingerprint STRING(64) NOT NULL
fingerprint_version UNSIGNED SMALLINT NOT NULL DEFAULT 1
business_date DATE NOT NULL
posted_by UUID NULL
posted_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

Required constraints and indexes:

1. `points > 0` where supported.
2. Unique `(customer_financial_account_id, ledger_sequence)`.
3. Unique `(tenant_id, customer_financial_account_id, idempotency_key)`.
4. Index `(tenant_id, customer_financial_account_id)`.
5. Index `(tenant_id, customer_financial_account_id, ledger_sequence)`.
6. Index `(tenant_id, business_date)`.
7. Index `(tenant_id, source_type, source_id)`.
8. No `amount_centavos`.
9. No `currency_code`.
10. No mutable balance column.

### 14.3 Optional Read Projection

Story 39.6 may defer projections.

If introduced, projections must be:

1. Rebuildable from `loyalty_ledger_entries`.
2. Non-authoritative.
3. Versioned.
4. Clearly marked as cache-derived if exposed.
5. Named consistently with Epic 39 projection terminology, such as `LoyaltyAccountSummaryProjection` or `LoyaltyReviewProjection`.

## 15. Service Design

### 15.1 `LoyaltyLedgerSequenceService`

Responsibilities:

1. Allocate next account-scoped loyalty ledger sequence.
2. Lock sequence row during allocation.
3. Create sequence row if it does not exist.
4. Mirror the store credit sequence pattern intentionally without sharing store credit tables or sequence generators.

### 15.2 `LoyaltyBalanceService`

Responsibilities:

1. Derive point balance from ledger entries.
2. Return zero for accounts without loyalty ledger entries.
3. Ignore store credit ledger rows.
4. Never persist an authoritative balance.
5. Expose available points as the derived ledger balance for Story 39.6.
6. Reserve future terminology for pending, reserved, promotional, or expiring points without implementing those states.

### 15.3 `LoyaltyLedgerService`

Responsibilities:

1. Normalize point posting payloads.
2. Enforce positive integer points.
3. Enforce account state.
4. Enforce tenant isolation.
5. Enforce idempotency replay and drift detection.
6. Enforce source uniqueness where `source_type` and `source_id` are present.
7. Throw `DuplicateSaleAccrualException` for duplicate sale accrual attempts.
8. Enforce non-negative derived balance for debit entries.
9. Assign ledger sequence inside the posting transaction.
10. Create immutable loyalty ledger rows.
11. Audit successful postings and rejected idempotency drift.

Forbidden behavior:

1. Writing store credit ledger rows.
2. Creating sale, payment, refund, void, inventory, receipt, or accounting records.
3. Mutating sale totals.
4. Mutating customer identity.
5. Persisting mutable point balance as authoritative.

### 15.4 `LoyaltyAccrualService`

Responsibilities:

1. Validate finalized sale eligibility.
2. Resolve the customer financial account.
3. Call `LoyaltyRuleService` to evaluate the active earning rule.
4. Consume `LoyaltyAccrualResult`.
5. Build immutable accrual snapshot.
6. Call `LoyaltyLedgerService` to post `sale_accrual`.
7. Return existing accrual entry on exact replay.
8. Reject duplicate or drifted source accrual attempts.

Forbidden behavior:

1. Marking a sale as paid.
2. Creating sale payments.
3. Recalculating sale discounts, taxes, statutory discount, promotions, or receipt totals.
4. Creating monetary accounting liability.
5. Redeeming points.

### 15.5 `LoyaltyRuleService`

Responsibilities:

1. Determine sale eligibility.
2. Calculate awarded points.
3. Return `LoyaltyAccrualResult`.
4. Build immutable rule snapshot.
5. Include `rule_version` and `rule_schema_version`.
6. Explain ineligible decisions with a reason code.

Forbidden behavior:

1. Posting ledger rows.
2. Mutating sales.
3. Reading or writing store credit ledgers.
4. Creating redemption or payment effects.

## 16. Integration Boundary

Approved Story 39.6 integration strategy:

1. Add loyalty ledger services and tests.
2. Add explicit service method for accrual from finalized sale.
3. Trigger accrual through an after-commit event/listener or equivalent after-commit dispatch.
4. Payment success must not be rolled back because loyalty posting failed.
5. Accrual retry must be idempotent.

Transaction rule:

```text
sale payment succeeds
        ↓
sale is finalized as paid
        ↓
commit payment transaction
        ↓
after-commit loyalty accrual event/listener runs
        ↓
loyalty rule service evaluates eligibility
        ↓
loyalty ledger credit posts
```

Failure policy:

1. Accrual failure must not roll back a successful payment.
2. Accrual failure must not create duplicate entries on retry.
3. Accrual failure must be recoverable through idempotent replay.
4. Silent duplicate accrual is prohibited.
5. Silent point loss must be visible through logs, audit, or retry diagnostics.

Story 39.6 should document the chosen implementation strategy in the implementation record.

## 17. Void and Refund Reversal Contract

Loyalty points earned from a sale must be reversible through append-only entries.

Void reversal:

1. Source authority remains `VoidService`.
2. A voided sale should reverse previously accrued points with `void_reversal`.
3. One sale void may create at most one loyalty reversal for one original accrual.
4. Reversal snapshot must reference sale, original accrual ledger entry, and void evidence.

Refund reversal:

1. Source authority remains `RefundService`.
2. A refund may reverse points proportionally or fully according to the approved rule.
3. One refund source may create at most one loyalty reversal for one account.
4. Reversal snapshot must reference sale, refund, original accrual ledger entry, and reversal basis.

Story 39.6 scope:

1. Story 39.6 must define constants and service contracts for reversals.
2. Story 39.6 must prove the ledger service can post append-only reversal entries and block negative balances.
3. Automatic void/refund service hooks are deferred from Story 39.6 unless a later approved revision explicitly adds them.
4. Direct mutation of original accrual entries is prohibited.

## 18. Account State Rules

Recommended first-release rules:

1. Active accounts may earn points.
2. Suspended accounts cannot receive new sale accrual entries.
3. Suspended accounts may not redeem points.
4. Suspended accounts may receive system reversal entries only when needed to unwind prior accrual evidence.
5. Closed accounts cannot receive new sale accrual.
6. Closed accounts may receive system reversal entries only when needed to correct prior evidence and explicitly allowed by service policy.
7. Reversal debit entries for suspended or closed accounts must still enforce non-negative derived point balance.
8. Generic suspended/closed account mutation conflicts apply to new accrual, redemption, and admin adjustment attempts, not to approved system reversal evidence.
9. Anonymized customers preserve loyalty ledger evidence and stable account references.

Story 39.6 does not implement redemption, so suspended/closed redemption behavior remains Story 39.7.

## 19. Idempotency and Fingerprinting

All loyalty ledger-producing commands must be idempotent.

Material fingerprint fields:

```text
tenant_id
branch_id
customer_financial_account_id
entry_type
direction
points
source_type
source_id
source_reference
business_date
rule_id
rule_code
rule_version
rule_schema_version
ledger_schema_version
fingerprint_version
```

Do not include:

1. Headers.
2. Request timestamps.
3. Non-material UI metadata.
4. Mutable customer display fields.

Behavior:

1. First request posts the ledger entry.
2. Exact replay returns the original ledger entry.
3. Drift rejects with `409`.
4. Duplicate source posting rejects with `409`.
5. Replay must not duplicate points, audit rows beyond replay audit evidence, or downstream side effects.

## 20. Error and Response Codes

| Condition | HTTP status |
| --- | ---: |
| Successful accrual/posting | `201` or `200` for replay |
| Successful derived balance lookup | `200` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant hidden resource | `404` |
| Idempotency drift | `409` |
| Duplicate source accrual | `409` |
| Insufficient loyalty balance | `409` |
| Closed/suspended account mutation | `409` |
| Offline mutation rejected | `409` |

Recommended idempotency drift payload:

```json
{
  "code": "LOYALTY_IDEMPOTENCY_DRIFT",
  "message": "The idempotency key was already used with different loyalty ledger details."
}
```

Recommended duplicate accrual payload:

```json
{
  "code": "DUPLICATE_SALE_ACCRUAL",
  "message": "This sale has already accrued loyalty points for the customer account."
}
```

## 21. Authorization and Permissions

If Story 39.6 exposes admin or POS balance lookup, add dedicated permissions:

```text
loyalty.view
loyalty.manage
```

Initial guidance:

1. `loyalty.view` permits balance and ledger review.
2. `loyalty.manage` is reserved for future rule/admin operations.
3. Cashiers should not receive loyalty management permissions by default.
4. Owner/Admin may receive both permissions.
5. Branch Manager and Accountant may receive `loyalty.view`.

No public customer wallet permissions are introduced in this story.

## 22. Audit and Privacy

Audit requirements:

1. Successful loyalty postings are audited.
2. Idempotent replays may be audited compactly.
3. Idempotency drift rejections are audited.
4. Audit does not replace ledger rows.
5. Audit payloads should include ledger id, account id, entry type, direction, points, sequence, business date, source type, source id, rule version, rule schema version, points before, and points after.
6. Audit payloads should not copy full customer PII.

Privacy requirements:

1. Anonymized customers must keep ledger evidence.
2. Review payloads must not expose removed customer PII.
3. Source snapshots should use stable non-personal identifiers where possible.

## 23. Test Plan

Backend feature tests:

1. Loyalty ledger schema exists and uses integer `points`.
2. Loyalty ledger schema has no centavo or currency fields.
3. Customer accounts do not gain mutable points balance columns.
4. Credit and debit point entries derive expected balance.
5. Accounts without loyalty entries derive zero balance.
6. Loyalty ledger rows cannot be updated.
7. Loyalty ledger rows cannot be deleted.
8. Negative point balance is blocked.
9. Idempotent replay returns the original entry.
10. Idempotency drift is rejected.
11. Duplicate source sale accrual is rejected.
12. Tenant isolation is enforced for posting and lookup.
13. Closed account sale accrual is rejected.
14. Finalized eligible paid sale accrues expected points.
15. Training sale accrual is rejected or returns no-op.
16. Offline unreconciled sale accrual is rejected.
17. Sale accrual snapshot includes rule version and ledger schema version.
18. Sale accrual snapshot includes rule schema version.
19. Training sale creates no loyalty ledger entry.
20. Accrual does not mutate sale totals, payment rows, store credit rows, inventory, receipt data, or accounting outbox.
21. Void reversal capability posts an append-only debit through `LoyaltyLedgerService`.
22. Refund reversal capability posts append-only debit through `LoyaltyLedgerService`.
23. Reversal cannot make point balance negative.

Regression tests:

1. Store credit ledger tests still pass.
2. Store credit refund issuance tests still pass.
3. Store credit redemption tests still pass.
4. Store credit admin review tests still pass.
5. Customer financial account tests still pass.
6. Payment recording tests related to sale finalization still pass if accrual integration touches payment.
7. Void/refund tests still pass if reversal integration touches those services.

## 24. Acceptance Criteria

1. Loyalty point ledger is separate from store credit ledger.
2. Loyalty entries use integer points, not centavos.
3. Loyalty entries have no currency code.
4. Loyalty ledger rows are append-only.
5. Derived point balance is calculated from ledger rows.
6. Negative point balance is blocked.
7. Account-scoped loyalty ledger sequence is deterministic.
8. Point posting is service-owned.
9. Controllers and seeders do not write loyalty ledger rows directly.
10. Eligible finalized paid sales can accrue points.
11. The same finalized sale cannot accrue points twice.
12. Exact accrual replay does not create duplicate points.
13. Accrual drift is rejected.
14. Accrual entries snapshot earning rule version.
15. Accrual entries snapshot rule schema version.
16. Points do not alter sale totals, payment totals, tax, discounts, promotions, receipts, inventory, store credit balances, or accounting outbox rows.
17. After-commit accrual failure does not roll back successful payment.
18. Voids/refunds reversal capability exists through append-only ledger entries, while automatic hooks are deferred from Story 39.6.
19. Offline unreconciled sales do not create redeemable loyalty points.
20. Training sales create no loyalty ledger entry.
21. Loyalty redemption remains unavailable.
22. Store credit conversion remains unavailable.
23. Tenant isolation and authorization are verified.

## 25. Implementation Checklist

1. Add loyalty ledger migrations.
2. Add loyalty ledger sequence migration.
3. Add `LoyaltyLedgerEntry` model with immutable guards.
4. Add `LoyaltyLedgerSequence` model.
5. Add loyalty-specific domain exceptions.
6. Add `LoyaltyLedgerSequenceService`.
7. Add `LoyaltyBalanceService`.
8. Add `LoyaltyLedgerService`.
9. Add `LoyaltyRuleService`.
10. Add `LoyaltyAccrualService`.
11. Add `LoyaltyAccrualResult` value object or DTO.
12. Add `DuplicateSaleAccrualException`.
13. Add idempotency fingerprinting for point postings.
14. Add source uniqueness checks.
15. Add audit events with points before and points after.
16. Add permissions if read surfaces are exposed.
17. Add after-commit accrual integration at the finalized-sale boundary.
18. Add void/refund reversal service-level capability tests without automatic source-service hooks.
19. Add factories.
20. Add backend feature tests.
21. Run focused loyalty tests.
22. Run Epic 39 regression tests.
23. Update story status and implementation record after implementation.

## 26. Definition of Done

Story 39.6 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Tenant isolation is verified.
4. Ledger immutability is verified.
5. Derived point balance is verified.
6. Idempotency replay and drift are verified.
7. Duplicate source accrual is blocked.
8. Negative point balance is blocked.
9. Rule snapshot versioning is verified.
10. No store credit coupling is introduced.
11. No redemption path is introduced.
12. No mutable point balance field is introduced.
13. No accounting liability event is introduced for points.
14. Existing store credit tests pass.
15. Existing customer account tests pass.
16. Payment/void/refund tests pass where integration is touched.
17. Code review is approved.
18. Documentation is updated.
