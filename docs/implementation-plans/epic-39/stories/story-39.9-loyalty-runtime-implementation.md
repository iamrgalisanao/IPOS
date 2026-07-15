# Story 39.9 Loyalty Runtime Implementation

## 1. Status

Approved for Implementation

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/README.md`
2. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
3. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
4. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
5. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
6. `docs/implementation-plans/epic-39/stories/story-39.4-store-credit-redemption.md`
7. `docs/implementation-plans/epic-39/stories/story-39.6-loyalty-ledger.md`
8. `docs/implementation-plans/epic-39/stories/story-39.7-loyalty-redemption.md`
9. `docs/implementation-plans/epic-39/stories/story-39.8-reporting-and-reconciliation.md`
10. `_bmad-output/implementation-artifacts/epic-39-retro-2026-07-15.md`
11. `app/Models/CustomerFinancialAccount.php`
12. `app/Models/StoreCreditLedgerEntry.php`
13. `app/Services/StoreCredit/StoreCreditLedgerService.php`
14. `app/Services/StoreCredit/StoreCreditPaymentCoordinator.php`
15. `app/Services/POS/SaleCreationService.php`
16. `app/Services/POS/PaymentRecordingService.php`
17. `app/Services/Reports/Epic39ReportingService.php`

## 3. Objective

Implement the deferred loyalty runtime from Stories 39.6 and 39.7.

This story turns the approved loyalty specifications into executable runtime behavior:

1. Loyalty ledger storage.
2. Loyalty point accrual from finalized eligible sales.
3. Loyalty point redemption as a discount-style checkout reduction.
4. Loyalty redemption evidence.
5. Loyalty reporting backed by real ledger rows.

This story must preserve the Epic 39 monetary boundary: loyalty points are not money, not store credit, not payment tender, and not accounting liability.

## 4. User Story

As a merchant,
I want loyalty points to accrue and redeem through auditable runtime services,
so that customers can earn and use rewards without weakening sale, payment, store-credit, tax, accounting, or reporting integrity.

## 5. Closeout Context

Epic 39 closeout found that store-credit runtime flows are implemented, but loyalty runtime storage and redemption execution are deferred.

Current code evidence:

1. `Epic39ReportingService::loyaltyActivity()` returns empty point activity.
2. Customer statements include a loyalty section with zero rows.
3. The reporting service explicitly notes that no loyalty ledger table exists yet.
4. Store-credit ledger patterns are implemented and should be mirrored where appropriate.

Story 39.9 closes that runtime gap.

## 6. Locked Decisions

1. Loyalty points are not money.
2. Loyalty points are not store credit.
3. Loyalty points must never write `store_credit_ledger_entries`.
4. Loyalty points must never create monetary accounting outbox rows.
5. Loyalty point balances are derived from append-only `loyalty_ledger_entries`.
6. `CustomerFinancialAccount` groups customer identity but must not store authoritative point balances.
7. Loyalty accrual and redemption must use loyalty-specific services.
8. Controllers, UI components, seeders, tests, and payment services must not write loyalty ledger rows directly.
9. Loyalty accrual posts credit entries only after the sale is finalized as paid.
10. Training sales do not accrue points.
11. Offline sales do not accrue points until server-side validation and official posting are complete.
12. Loyalty redemption is a discount-style checkout reduction, not a payment method.
13. Loyalty redemption must not create `SalePayment` rows.
14. Loyalty redemption must not alter finalized sale totals after sale creation.
15. Loyalty redemption discount evidence must be applied before or during sale creation, while sale totals are still mutable inside `SaleCreationService`.
16. Payment finalization may debit points only for an already-persisted loyalty redemption intent tied to the sale.
17. If payment finalization fails, loyalty points must not be debited.
18. If loyalty point debit fails, payment finalization must roll back.
19. Exact idempotency replay must not duplicate accrual, redemption evidence, ledger rows, sale discount evidence, payments, audit rows beyond replay evidence, or reporting rows.
20. Idempotency drift must be rejected before mutation.
21. Loyalty runtime must not cause promotions, statutory discounts, taxes, inventory, receipt numbering, fiscal records, or store-credit balances to be recalculated outside their owning services.
22. First-release loyalty accrual runs through an after-commit asynchronous listener/job from the paid-sale boundary.
23. Accrual integration uses an explicit `SalePaid` event contract.
24. Loyalty earning and redemption rules are tenant-configurable with seeded default rules.
25. Rule snapshots must include immutable `rule_id`, rule code, rule version, and rule schema version.
26. Loyalty redemption evidence should reuse the existing immutable `SaleDiscount` evidence model if it can safely represent loyalty; otherwise implementation must introduce a dedicated sale-loyalty discount evidence table before coding the redemption path.
27. Statutory discounts suppress loyalty redemption in the first release.
28. Automatic loyalty reversals for voids/refunds are deferred to a dedicated follow-up story.
29. Loyalty reports read ledger rows on demand in the first release; no separate projection refresh pipeline is introduced by this story.

## 7. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Store Credit Ledger, as the ledger-pattern reference.
3. Story 39.4 Store Credit Redemption, as the payment-boundary reference.
4. Story 39.6 Loyalty Ledger specification.
5. Story 39.7 Loyalty Redemption specification.
6. Story 39.8 Reporting and Reconciliation.
7. Existing `SaleCreationService`.
8. Existing `PaymentRecordingService`.
9. Existing active shift, branch, tenant, terminal, training-mode, and offline guards.

## 8. Runtime Scope

In scope:

1. Loyalty ledger migration.
2. Loyalty ledger sequence migration.
3. Loyalty redemption evidence migration.
4. Tenant-configurable loyalty rule table with seeded defaults.
5. `LoyaltyLedgerEntry` model with append-only guards.
6. `LoyaltyLedgerSequence` model or equivalent allocator.
7. `LoyaltyLedgerService`.
8. `LoyaltyBalanceService`.
9. `LoyaltyAccrualService`.
10. `LoyaltyRuleService`.
11. `LoyaltyRedemptionRuleService`.
12. `LoyaltyRedemptionService`.
13. `LoyaltyCheckoutRedemptionCoordinator`.
14. Sale-creation payload support for a loyalty redemption request.
15. Payment-finalization integration that debits approved points transactionally.
16. Loyalty runtime audit events.
17. Loyalty reporting backed by ledger rows.
18. Backend feature tests for ledger, accrual, redemption, idempotency, rollback, reporting, and isolation.
19. RBAC seeding for loyalty runtime permissions if missing.

Out of scope:

1. Points-as-cash tender.
2. Creating `SalePayment` rows for points.
3. Store-credit conversion.
4. Customer self-service wallet.
5. Third-party loyalty provider integration.
6. Marketing campaign builder.
7. Customer segmentation.
8. Automatic point expiration execution.
9. Manual loyalty admin adjustments.
10. Monetary accounting liability for points.
11. Customer merge.
12. Gift cards.
13. Offline loyalty redemption.
14. Negative loyalty balances.
15. Automatic loyalty reversal execution for voids/refunds.

## 9. Data Model Requirements

### 9.1 Loyalty Ledger Sequences

Create `loyalty_ledger_sequences`.

Required fields:

1. `id`
2. `tenant_id`
3. `customer_financial_account_id`
4. `next_sequence`
5. timestamps

Required constraints:

1. Unique `(tenant_id, customer_financial_account_id)`.
2. Restrict delete for customer financial accounts.
3. Sequence allocation must happen inside the same transaction as ledger posting.

### 9.2 Loyalty Ledger Entries

Create `loyalty_ledger_entries`.

Required fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `customer_financial_account_id`
5. `ledger_sequence`
6. `ledger_schema_version`
7. `ledger_category`
8. `entry_type`
9. `direction`
10. `points`
11. `source_type`
12. `source_id`
13. `source_reference`
14. `source_snapshot`
15. `idempotency_key`
16. `request_fingerprint`
17. `fingerprint_version`
18. `business_date`
19. `posted_by`
20. `posted_at`
21. timestamps

Required constraints:

1. Unique `(customer_financial_account_id, ledger_sequence)`.
2. Unique `(tenant_id, customer_financial_account_id, idempotency_key)`.
3. Index `(tenant_id, customer_financial_account_id)`.
4. Index `(tenant_id, customer_financial_account_id, posted_at)`.
5. Index `(tenant_id, business_date)`.
6. Index `(tenant_id, source_type, source_id)`.

`points` must be a positive integer. It must not be centavos and must not have currency code.

Approved executable entry types:

```text
sale_accrual
redemption_debit
```

Reserved-only entry types:

```text
void_reversal
refund_reversal
admin_points_credit
admin_points_debit
expiration_debit
forfeiture_debit
```

Reserved types may exist as constants but must not be executable through public runtime services in this story.

### 9.3 Loyalty Redemptions

Create `loyalty_redemptions`.

Required fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `sale_id`
5. `customer_financial_account_id`
6. `loyalty_ledger_entry_id`
7. `points_redeemed`
8. `benefit_centavos`
9. `redemption_rule_id`
10. `redemption_rule_code`
11. `redemption_rule_version`
12. `redemption_rule_schema_version`
13. `rule_snapshot`
14. `source_snapshot`
15. `idempotency_key`
16. `authorized_points_balance`
17. `status`
18. `failure_reason`
19. `redeemed_by`
20. `authorized_at`
21. `redeemed_at`
22. timestamps

Required constraints:

1. Unique `loyalty_ledger_entry_id`.
2. Unique `(tenant_id, sale_id, customer_financial_account_id)` for first release.
3. Unique `(tenant_id, customer_financial_account_id, idempotency_key)`.
4. Restrict deletes for sale, account, and ledger entry evidence.

Lifecycle:

```text
pending
    |
    v
finalized
    |
    v
failed
```

Rules:

1. `pending` means the sale was created with approved loyalty redemption evidence but points have not yet been debited.
2. `finalized` means payment finalization succeeded and the loyalty debit ledger entry exists.
3. `failed` means finalization was rejected or rolled back before the sale became paid.
4. A sale must not be marked paid with a `pending` loyalty redemption.
5. A `finalized` redemption must have `loyalty_ledger_entry_id`.
6. A `failed` redemption must not have a debit ledger entry.
7. Failed redemption records are retained permanently as audit and support evidence.
8. Failed redemption records are not retried directly; a new checkout/payment attempt must use a fresh idempotency key or exact replay rules.

### 9.4 Loyalty Rules

Create tenant-configurable loyalty rule storage.

Required first-release rule records:

1. Earning rule.
2. Redemption rule.

Required fields:

1. `id`
2. `tenant_id`
3. `branch_id` nullable
4. `rule_code`
5. `rule_type`
6. `rule_version`
7. `rule_schema_version`
8. `status`
9. `priority`
10. `starts_at`
11. `ends_at`
12. `configuration`
13. timestamps

Required rule types:

```text
earning
redemption
```

Required constraints:

1. Active rules are tenant-scoped.
2. Branch-specific rules may override tenant defaults only if explicitly active.
3. Rule configuration is snapshotted into accrual and redemption evidence.
4. Rule changes never mutate historical loyalty ledger or redemption evidence.
5. Seeders must create a conservative default earning rule and redemption rule for existing tenants.

Conflict resolution:

```text
active branch rule
    |
    v
active tenant rule
    |
    v
seeded default rule
```

If multiple active rules exist at the same specificity, the highest priority wins. If priority ties remain, the newest active rule version wins. The selected rule identity and version must be snapshotted before ledger posting.

## 10. Service Requirements

### 10.1 LoyaltyLedgerService

Owns all point ledger posting.

Must enforce:

1. Tenant isolation.
2. Account lock with `lockForUpdate()`.
3. Account active/suspended/closed rules.
4. Positive integer points.
5. Direction matches entry type.
6. Source uniqueness for executable source types.
7. Idempotency replay.
8. Idempotency drift rejection.
9. Non-negative derived balance for debits.
10. Sequence allocation in transaction.
11. Append-only row creation.
12. Audit events.

Must not:

1. Create sale payments.
2. Create store-credit rows.
3. Create accounting outbox rows.
4. Mutate sale totals.

### 10.2 LoyaltyBalanceService

Owns derived point balance calculation.

Required methods:

1. `availablePoints(CustomerFinancialAccount $account): int`
2. `balanceAsOf(CustomerFinancialAccount $account, Carbon|string $asOf): int`
3. `statementTotals(CustomerFinancialAccount $account, array $filters): array`

The service must calculate from ledger rows, not from mutable account columns.

### 10.3 LoyaltyAccrualService

Owns earning points from finalized paid sales.

Required behavior:

1. Accrue only after sale is paid.
2. Skip training sales.
3. Skip offline sales until official server posting is complete.
4. Skip sales without a customer financial account.
5. Reject duplicate accrual for the same sale/account source.
6. Replay exact same idempotency request.
7. Reject drift.
8. Snapshot earning rule version and material sale inputs.
9. Post `sale_accrual` through `LoyaltyLedgerService`.
10. Never mutate sale totals, payments, store credit, inventory, receipt data, or accounting outbox.

Integration point:

1. Accrual must be triggered by a `SalePaid` event after the payment transaction commits.
2. The listener/job must be idempotent by sale/account/rule source.
3. Payment success must not be blocked by loyalty accrual processing.
4. Operational retry must be safe and must not create duplicate point credits.
5. Failed accrual attempts must be auditable and retryable.

`SalePaid` payload contract:

1. `event_version`
2. `tenant_id`
3. `branch_id`
4. `sale_id`
5. `sale_number`
6. `customer_financial_account_id`
7. `business_date`
8. `paid_at`
9. `sale_total_centavos`
10. `eligible_amount_centavos`
11. `is_training_mode`
12. `source`
13. `idempotency_key`

The listener must treat the payload as immutable source evidence. If a later event payload version is introduced, the loyalty accrual listener must explicitly support or reject that version.

### 10.4 LoyaltyRedemptionService

Owns redemption eligibility, rule validation, immutable snapshot creation, and redemption evidence.

Required behavior:

1. Validate active account.
2. Validate online context.
3. Validate non-training sale/checkout.
4. Validate sufficient available points.
5. Validate redemption rule.
6. Build immutable redemption snapshot.
7. Persist sale discount/adjustment evidence through the sale-pricing boundary.
8. Coordinate final ledger debit only when payment finalization succeeds.
9. Replay exact same redemption request.
10. Reject drift.
11. Prevent duplicate redemption for the same sale/account in first release.
12. Reject redemption when the sale includes a statutory discount.
13. Snapshot `rule_id`, rule code, rule version, rule schema version, available balance, and material checkout inputs.

Rule evaluation cache:

1. Rule evaluation may use caching only if cache keys include tenant, branch, rule type, rule version, and schema version.
2. Cached rule evaluation must remain deterministic and must not bypass active-window checks.
3. The selected rule snapshot stored on ledger and redemption evidence is authoritative, not the cache state.

### 10.5 LoyaltyCheckoutRedemptionCoordinator

This coordinator bridges sale creation and payment finalization.

Required behavior:

1. During sale creation, validate requested loyalty redemption and persist a pending redemption intent/evidence tied to the sale.
2. Apply the benefit as a discount-style reduction before sale totals are persisted.
3. Persist discount evidence through `SaleDiscount` if it can safely represent loyalty with immutable snapshots.
4. Introduce a dedicated sale-loyalty discount evidence table if `SaleDiscount` cannot safely represent loyalty.
5. During payment finalization, lock and finalize the pending intent.
6. Post `redemption_debit` through `LoyaltyLedgerService`.
7. Mark redemption evidence finalized only after the ledger debit succeeds.
8. Roll back payment finalization if debit fails.

Must not:

1. Create `SalePayment` rows for points.
2. Treat points as tender.
3. Recalculate promotions after sale creation.
4. Modify finalized sale totals.

## 11. Sale Creation Contract

Add optional sale-creation payload support:

```json
{
  "loyalty_redemption": {
    "customer_financial_account_id": "uuid",
    "points_to_redeem": 100,
    "redemption_rule_code": "POINTS_FOR_DISCOUNT_V1",
    "client_request_uuid": "uuid"
  }
}
```

Validation:

1. `customer_financial_account_id` is required when `loyalty_redemption` is present.
2. `points_to_redeem` must be a positive integer.
3. `redemption_rule_code` must be active.
4. `client_request_uuid` is required for idempotency.
5. Offline checkout must reject loyalty redemption.
6. Training checkout must reject loyalty redemption.
7. Checkout with statutory discount must reject loyalty redemption in the first release.

Sale total behavior:

1. Loyalty benefit reduces sale total before `Sale::create()`.
2. Loyalty benefit contributes to `discount_total`.
3. Loyalty benefit should be represented through `SaleDiscount` if it can safely carry loyalty metadata and immutable snapshots.
4. If `SaleDiscount` cannot safely represent loyalty, implementation must add a dedicated immutable sale-loyalty discount evidence table.
5. `other_adjustment_total` may summarize the amount only if the immutable evidence row remains authoritative.
6. The final implementation must not mutate sale financial totals after `Sale::create()`.
7. Statutory discounts suppress loyalty redemption in the first release.
8. Existing promotion ordering must remain unchanged unless this story is formally revised.

If no safe sale-pricing boundary exists, implementation must stop for architecture review before treating loyalty points as tender.

## 12. Payment Finalization Contract

During `PaymentRecordingService::recordSplit()`:

1. Load any pending loyalty redemption intent for the sale.
2. Ensure the sale is not training mode.
3. Ensure payment total matches the already loyalty-reduced sale total.
4. Inside the existing payment transaction, after payment validation and before marking the sale paid, finalize loyalty debit.
5. If debit fails, roll back payment rows and sale status.
6. If exact replay occurs, return the existing redemption result without duplicate point debit.

Store credit and loyalty may coexist:

1. Store credit remains a payment tender.
2. Loyalty remains a discount-style reduction.
3. Store credit payment amount is calculated against the loyalty-reduced sale total.
4. Loyalty redemption must not write store-credit redemption rows.
5. Store credit redemption must not write loyalty rows.

## 13. Reporting Contract

Update `Epic39ReportingService`.

Customer statement:

1. Replace zero-row loyalty placeholder with real ledger rows.
2. Include opening, period activity, and closing point balances.
3. Keep store credit and loyalty sections separate.

Loyalty activity:

1. Return point accrual rows.
2. Return redemption debit rows.
3. Return reversal rows if present.
4. Support tenant, branch, account, date, entry type, and direction filters.
5. Maintain pagination shape consistent with store-credit reports.

Reconciliation:

1. Monetary store-credit reconciliation remains separate.
2. Loyalty reconciliation must not expect accounting outbox rows.
3. Loyalty exceptions should focus on missing source evidence, duplicate source, invalid negative balance prevention, and orphaned pending redemption intents.

Refresh behavior:

1. Loyalty reporting reads `loyalty_ledger_entries` and `loyalty_redemptions` on demand.
2. No asynchronous report projection refresh is introduced in the first release.
3. If a later story introduces loyalty read-model projections, it must define refresh timing and replay semantics separately.

## 14. Authorization and Permissions

Required permissions:

1. `loyalty.view`
2. `loyalty.accrue`
3. `loyalty.redeem`
4. `reports.loyalty.view`
5. `reports.loyalty.export`

Rules:

1. Cashiers may redeem only at POS with valid terminal, branch, shift, and online context.
2. Managers/admins may view loyalty ledger review surfaces if implemented.
3. Accountants may view loyalty reports, but loyalty must not appear as accounting liability.

## 15. Audit Events

Required audit actions:

1. `LOYALTY_LEDGER_ENTRY_POSTED`
2. `LOYALTY_LEDGER_IDEMPOTENCY_REPLAYED`
3. `LOYALTY_LEDGER_IDEMPOTENCY_DRIFT_REJECTED`
4. `LOYALTY_ACCRUAL_POSTED`
5. `LOYALTY_ACCRUAL_SKIPPED`
6. `LOYALTY_REDEMPTION_AUTHORIZED`
7. `LOYALTY_REDEMPTION_FINALIZED`
8. `LOYALTY_REDEMPTION_REJECTED`
9. `LOYALTY_REDEMPTION_ROLLED_BACK`

Audit payloads must include tenant, branch, account, sale, ledger entry, points, rule version, idempotency key hash, and actor where applicable.

## 16. Failure Semantics

Accrual failure:

1. Must not roll back an already committed payment.
2. Must be replayable without duplicate points.
3. Must be visible in logs/audit if skipped or failed.

Redemption pre-sale failure:

1. Reject sale creation before sale financial totals are persisted.
2. Do not create sale, payment, ledger, or redemption rows.

Redemption payment failure:

1. Roll back payment rows.
2. Roll back loyalty ledger debit.
3. Keep pending redemption evidence either rolled back or marked failed inside the same transactional contract.
4. Do not mark sale paid.

Replay:

1. Same request returns existing sale/redemption/ledger evidence.
2. Drift rejects before mutation.

## 17. Implementation Slices

Recommended PR sequence:

1. Ledger runtime foundation
   - migrations
   - models
   - factories
   - `LoyaltyLedgerService`
   - `LoyaltyBalanceService`
   - ledger tests

2. Accrual runtime
   - rule service
   - accrual service
   - paid-sale integration
   - skip/replay/drift tests

3. Redemption pricing integration
   - sale creation payload validation
   - sale total/discount evidence integration
   - pending redemption intent
   - no post-finalization total mutation tests

4. Redemption payment finalization
   - payment transaction coordinator
   - ledger debit finalization
   - rollback/idempotency tests
   - store-credit coexistence tests

5. Reporting activation and docs
   - real loyalty activity report rows
   - customer statement loyalty rows
   - reconciliation exception updates
   - user/admin docs updates

## 18. Test Plan

Required feature tests:

1. `tests/Feature/Loyalty/LoyaltyLedgerRuntimeTest.php`
2. `tests/Feature/Loyalty/LoyaltyAccrualRuntimeTest.php`
3. `tests/Feature/Loyalty/LoyaltyRedemptionRuntimeTest.php`
4. `tests/Feature/Reports/Epic39LoyaltyReportingTest.php`
5. `tests/Feature/POS/PaymentRecordingTest.php`
6. `tests/Feature/StoreCredit/StoreCreditRedemptionTest.php`

Required assertions:

1. Loyalty ledger tables exist.
2. Loyalty entries are append-only.
3. Point balances are derived.
4. Accrual posts points once for eligible paid sale.
5. Accrual skips training sales.
6. Accrual skips offline unposted sales.
7. Accrual exact replay does not duplicate points.
8. Accrual drift is rejected.
9. Redemption reduces sale total before sale persistence.
10. Redemption does not create `SalePayment`.
11. Redemption posts `redemption_debit` only during successful payment finalization.
12. Redemption rollback leaves no paid sale with missing point debit.
13. Redemption cannot create negative point balance.
14. Store credit and loyalty coexist without cross-ledger writes.
15. No loyalty path creates accounting outbox liability rows.
16. Customer statement returns loyalty rows from ledger.
17. Loyalty report returns real point activity.
18. Tenant and branch isolation are enforced.
19. Two concurrent redemption attempts against the same account cannot overspend points.
20. In concurrent redemption attempts where only one can be funded, one succeeds and the other fails cleanly with no negative balance.
21. Statutory-discount sale creation rejects loyalty redemption.
22. Accrual is triggered from `SalePaid` after commit and is safe to retry.

Recommended commands:

```bash
php artisan test tests/Feature/Loyalty
php artisan test tests/Feature/Reports/Epic39LoyaltyReportingTest.php
php artisan test tests/Feature/POS/PaymentRecordingTest.php tests/Feature/StoreCredit/StoreCreditRedemptionTest.php
php artisan test
npm run build
```

## 19. Acceptance Criteria

1. Loyalty ledger tables and models exist.
2. Loyalty ledger entries are append-only.
3. Loyalty ledger sequence is monotonic per customer financial account.
4. Loyalty balances are derived from ledger rows.
5. Loyalty accrual creates point credit entries for eligible paid sales.
6. Loyalty accrual does not duplicate points on replay.
7. Loyalty accrual drift is rejected before mutation.
8. Loyalty accrual does not affect sale totals, payments, store credit, inventory, receipt data, or accounting outbox.
9. Loyalty redemption is available only online.
10. Loyalty redemption is rejected for training sales.
11. Loyalty redemption applies as discount-style reduction before sale totals are persisted.
12. Loyalty redemption does not create `SalePayment` rows.
13. Loyalty redemption posts point debit only through `LoyaltyLedgerService`.
14. Loyalty redemption cannot make point balance negative.
15. Loyalty redemption exact replay does not duplicate sale discount, redemption evidence, or ledger debit.
16. Loyalty redemption drift is rejected before mutation.
17. Failed payment finalization rolls back loyalty point debit.
18. Failed loyalty debit rolls back payment finalization.
19. Store-credit payment and loyalty redemption can coexist without cross-ledger contamination.
20. Loyalty reporting uses real ledger rows, not placeholder zero responses.
21. Customer statement includes real loyalty point activity.
22. No loyalty runtime path creates monetary accounting liability events.
23. Permissions protect loyalty viewing and redemption.
24. Audit events capture accrual, redemption, replay, drift, and rejection cases.
25. Existing store-credit tests remain green.
26. Existing payment and checkout tests remain green.
27. Automatic loyalty reversal execution remains deferred to a follow-up story.

## 20. Definition of Done

Story 39.9 is done when:

1. Acceptance criteria pass.
2. Feature tests pass.
3. Full backend test suite passes.
4. Frontend build passes.
5. Store-credit regressions pass.
6. Payment-recording regressions pass.
7. Reporting regressions pass.
8. No accounting liability outbox is introduced for loyalty.
9. No direct controller/UI ledger writes exist.
10. Documentation status is updated from deferred to runtime done.
11. Local PR review is complete.
12. CI is green before merge.

## 21. Resolved Review Decisions

Resolved by review:

1. First-release accrual uses after-commit asynchronous processing from `SalePaid`.
2. First-release earning and redemption rules are tenant-configurable with seeded defaults.
3. Loyalty redemption reuses `SaleDiscount` only if it safely supports loyalty metadata and immutable snapshots; otherwise implementation adds dedicated loyalty discount evidence.
4. Statutory discounts suppress loyalty redemption in the first release.
5. Automatic loyalty reversal for void/refund is deferred to a dedicated follow-up story.

Remaining implementation clarification:

1. Confirm whether `SaleDiscount` can safely represent loyalty before coding the sale-pricing integration. If not, create the dedicated evidence table as part of this story.

## 22. Recommended Review Focus

Review this story against five contracts:

1. Loyalty remains non-monetary.
2. Redemption reduces sale total before immutable sale persistence.
3. Payment finalization and point debit are transactionally safe.
4. Store-credit and loyalty ledgers remain separate.
5. Reporting switches from placeholder loyalty rows to real ledger evidence.
