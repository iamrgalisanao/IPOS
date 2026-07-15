# Story 39.7 Loyalty Redemption

## 1. Status

Specification Done; Runtime Deferred

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
5. `docs/implementation-plans/epic-39/stories/story-39.4-store-credit-redemption.md`
6. `docs/implementation-plans/epic-39/stories/story-39.6-loyalty-ledger.md`
7. `app/Services/POS/SaleCreationService.php`
8. `app/Services/POS/PaymentRecordingService.php`
9. `app/Http/Controllers/POS/PaymentController.php`
10. `app/Http/Requests/RecordPaymentRequest.php`
11. `app/Http/Requests/RecordSplitPaymentRequest.php`
12. `app/Services/StoreCredit/StoreCreditPaymentCoordinator.php`
13. `app/Models/Sale.php`
14. `app/Models/SalePayment.php`
15. `app/Models/PaymentMethod.php`
16. `app/Models/CustomerFinancialAccount.php`
17. `app/Models/StoreCreditRedemption.php`
18. `app/Models/StoreCreditLedgerEntry.php`

## 3. Objective

Allow loyalty points to be redeemed through explicit, versioned redemption rules without treating points as cash, store credit, or accounting liability.

This story introduces first-release loyalty redemption only. It must not create a parallel payment engine, convert points into store credit, introduce customer wallet self-service, implement third-party loyalty providers, allow offline redemption, or create monetary accounting outbox events for loyalty points.

## 4. User Story

As a cashier,
I want to apply an eligible customer's loyalty points as an approved checkout reduction,
so that customers can redeem rewards while IPOS preserves immutable sale, payment, point-ledger, audit, and reporting evidence.

## 5. Locked Decisions

1. Loyalty points are not money.
2. Loyalty points are not store credit.
3. Loyalty redemption must use the existing sale/payment boundary and must not create a standalone checkout or payment engine.
4. First-release loyalty redemption uses a discount-style checkout reduction, not a cash-equivalent payment tender.
5. Loyalty redemption cannot create `SalePayment` rows unless a later architecture revision explicitly approves point tender.
6. Loyalty redemption cannot write store credit ledger rows.
7. Loyalty redemption posts `redemption_debit` to the loyalty ledger only through `LoyaltyLedgerService`.
8. `LoyaltyRedemptionService` owns redemption eligibility, rule validation, snapshot creation, and redemption coordination.
9. `LoyaltyRedemptionRuleService` owns redemption rule evaluation, point-to-benefit calculation, and immutable rule snapshots.
10. `SaleCreationService` remains responsible for sale totals, discounts, taxes, statutory discount ordering, receipt totals, and sale item evidence unless this story is formally revised.
11. `PaymentRecordingService` remains the authority for payment recording and paid-sale finalization.
12. `PaymentRecordingService` owns the final checkout payment transaction. No alternate final transaction owner may be introduced without formal story or architecture revision.
13. `LoyaltyCheckoutRedemptionCoordinator` orchestrates redemption inside the approved sale/payment transaction; it never opens or owns the final transaction.
14. Sale/pricing persistence records the loyalty discount evidence through the approved sale-pricing boundary before final payment.
15. A failed sale/payment operation must not debit loyalty points.
16. A loyalty point debit may be committed only inside the final payment transaction after payment validation succeeds.
17. A loyalty debit failure must roll back the final payment transaction and must not leave a paid sale with an unapplied or unposted point redemption.
18. Exact redemption replay must return the original redemption result without creating duplicate point debit entries.
19. Idempotency drift must be rejected before sale, payment, or ledger mutation.
20. The same sale may create at most one loyalty redemption debit per customer financial account in the first release.
21. Duplicate redemption attempts with a different idempotency key must throw `DuplicateLoyaltyRedemptionException` or an equivalent loyalty-specific domain exception; exact same-key replay must not throw.
22. Redemption must not create a negative derived point balance.
23. Redemption requires an active customer financial account.
24. Suspended and closed accounts cannot redeem points.
25. Offline loyalty redemption is prohibited.
26. Training sales cannot redeem loyalty points.
27. Redemption entries must snapshot the redemption rule ID, rule code, rule version, rule schema version, ledger schema version, point balance snapshot, checkout identity, sale identity, and sale/checkout source evidence.
28. Redemption source snapshots are immutable historical evidence and must not be recalculated from mutable sale, payment, customer, or rule records.
29. Loyalty redemption creates no monetary accounting liability event in Story 39.7.
30. Refund/void treatment for redeemed points must be append-only and must not mutate original redemption entries.

## 6. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.6 Loyalty Ledger.
3. Existing `LoyaltyLedgerService`, `LoyaltyBalanceService`, and loyalty ledger sequence behavior from Story 39.6.
4. Existing sale creation and payment recording lifecycle.
5. Existing POS active shift, terminal, branch, tenant, and timecard protections around checkout/payment.
6. Existing store credit redemption implementation as a payment-boundary reference pattern, while preserving loyalty-specific non-money behavior.
7. Existing promotion/statutory discount behavior from prior epics.

## 7. Current Codebase Context

Existing payment context:

1. `PaymentController::store()` records one payment by calling `PaymentRecordingService::record()`.
2. `PaymentController::storeSplit()` records multiple payments by calling `PaymentRecordingService::recordSplit()`.
3. `PaymentRecordingService::recordSplit()` runs inside a database transaction.
4. `PaymentRecordingService::recordSplit()` validates sale existence, active shift, sale status, active payment methods, positive amounts, references, and exact payment total.
5. `PaymentRecordingService::recordSplit()` creates `SalePayment` rows.
6. `PaymentRecordingService::recordSplit()` marks the sale as `paid`.
7. `PaymentRecordingService::recordSplit()` creates `sale_paid` accounting evidence for non-training sales.
8. `PaymentRecordingService::recordSplit()` finalizes dining ticket settlement after successful payment.
9. Store credit redemption already uses `StoreCreditPaymentCoordinator` inside the payment transaction.

Existing store credit redemption context:

1. Store credit redemption is a monetary tender.
2. Store credit redemption creates `StoreCreditRedemption` evidence.
3. Store credit redemption posts `redemption_debit` to `store_credit_ledger_entries`.
4. Store credit redemption creates accounting outbox evidence for `store_credit_redeemed`.
5. Store credit redemption requires exact idempotency replay and drift handling.

Existing loyalty ledger context:

1. Loyalty points use a separate loyalty ledger.
2. Loyalty balances are derived from `loyalty_ledger_entries`.
3. Loyalty ledger rows are append-only.
4. Loyalty ledger posting is service-owned through `LoyaltyLedgerService`.
5. Loyalty point movements use integer points, not centavos.
6. Loyalty has no currency code.
7. `redemption_debit` is the approved loyalty entry type for redemption.
8. `LoyaltyBalanceService` owns derived point balance calculation.

Implementation implication:

Story 39.7 should add a loyalty redemption coordinator that integrates with the existing checkout/payment boundary. It should not add direct ledger writes to controllers, UI components, seeders, or tests, and it should not reuse store credit tables or accounting liability behavior.

## 8. Redemption Strategy

Approved first-release strategy:

```text
Customer loyalty points
        |
        v
LoyaltyRedemptionRuleService
        |
        v
Versioned discount-style redemption authorization
        |
        v
Existing sale/checkout pricing boundary
        |
        v
Existing payment finalization boundary
        |
        v
LoyaltyLedgerService posts redemption_debit
```

This story should implement loyalty redemption as a discount-style checkout reduction, not as a payment tender.

Consequences:

1. Loyalty redemption may reduce the amount due only through an approved sale-pricing or checkout-discount boundary.
2. Loyalty redemption must not create `SalePayment` rows.
3. Loyalty redemption must not appear as cash, card, e-wallet, or store credit tender.
4. Loyalty redemption must be visibly distinguishable from store credit in receipt/reporting payloads.
5. Loyalty redemption must preserve statutory discount ordering and must not recalculate completed sale item pricing outside the approved sale authority.
6. Loyalty redemption discount lines must use a stable identity such as `discount_type = LOYALTY_REDEMPTION`.
7. Loyalty redemption never changes promotion eligibility and must not cause promotions to be requalified, disqualified, or recalculated.
8. The loyalty discount must be persisted by the sale-pricing boundary as immutable sale discount or adjustment evidence, not as a payment tender.
9. The persisted discount evidence must be referenceable by receipt, reporting, audit, and future reversal logic.

If the existing code cannot safely apply discount-style redemption before sale finalization, implementation must stop and update this story for architecture review rather than falling back to point tender implicitly.

## 9. Domain Scope

In scope:

1. Loyalty redemption rule contract.
2. `LoyaltyRedemptionService`.
3. `LoyaltyRedemptionRuleService`.
4. `LoyaltyRedemptionResult` value object or DTO.
5. Mandatory immutable loyalty redemption evidence model.
6. `redemption_debit` posting through `LoyaltyLedgerService`.
7. Authorized available point balance snapshot.
8. Redemption rule snapshot.
9. Sale/checkout source snapshot.
10. Idempotency replay and drift detection.
11. Insufficient-point conflict handling.
12. Active account validation.
13. Offline redemption rejection.
14. Training sale redemption rejection.
15. Audit events for successful redemption and rejected conflicts.
16. Receipt/payment response data needed to identify loyalty redemption.
17. Backend feature tests for rule validation, point debit, rollback, idempotency, insufficient balance, tenant isolation, and no store credit/accounting side effects.

Out of scope:

1. Treating points as cash tender.
2. Creating `SalePayment` rows for points.
3. Store credit conversion.
4. Writing store credit ledger rows.
5. Monetary accounting liability for points.
6. Third-party loyalty provider integration.
7. Customer self-service wallet.
8. Marketing campaign engine.
9. Customer segmentation.
10. Automatic expiration execution.
11. Manual loyalty admin adjustments.
12. Offline loyalty redemption.
13. Customer merge.
14. Gift cards.
15. Negative loyalty balances.
16. Recalculating historical promotion eligibility.

## 10. Redemption Invariants

1. Points are redeemed only through active redemption rules.
2. Points cannot be redeemed without a customer financial account.
3. Points cannot be redeemed from suspended or closed accounts.
4. Points cannot be redeemed offline.
5. Points cannot be redeemed for training sales.
6. Points cannot be redeemed if the derived available point balance is insufficient.
7. Point debit entries are append-only.
8. Point debit entries cannot be updated.
9. Point debit entries cannot be deleted.
10. Redemption cannot create a negative derived point balance.
11. Redemption cannot create monetary store credit.
12. Redemption cannot create accounting liability rows.
13. Redemption cannot create sale payments unless point tender is approved later.
14. Redemption cannot mutate historical sale item snapshots after sale finalization.
15. Exact replay does not duplicate point debit, sale discount, audit rows beyond replay evidence, or downstream side effects.
16. Drift rejects before mutation.
17. Redemption snapshots are historical evidence and must not be recalculated.

## 11. Redemption Rule Contract

Story 39.7 must introduce an explicit redemption rule.

Recommended first rule:

```text
rule_id: stable-uuid-or-immutable-slug
rule_code: base_points_discount
rule_version: 1
rule_schema_version: 1
redemption_basis: eligible_checkout_subtotal_after_promotions
points_per_discount_unit: configurable or fixed default
minimum_points: optional
maximum_discount_centavos: optional
maximum_discount_policy: cannot_exceed_eligible_remaining_amount_due
rounding: floor
stacking_policy: after_promotions_before_payment
statutory_discount_policy: preserve_existing_order
```

Rules:

1. `rule_id` must be stable and snapshotted.
2. `rule_id` must be a UUID or immutable slug, never a database auto-increment ID.
3. `rule_code` must be snapshotted.
4. `rule_version` must be snapshotted.
5. `rule_schema_version` must be snapshotted.
6. The rule snapshot must include redemption basis, conversion rate, rounding, stacking policy, statutory discount policy, maximum discount, discount type, and ledger schema version.
7. Changing future redemption rules must not mutate historical redemption rows.
8. Renaming a rule code must not break historical interpretation because the stable rule ID remains snapshotted.
9. Redemption rule evaluation must not recalculate promotion eligibility.
10. Redemption rule evaluation must not recalculate statutory discount eligibility.
11. Calculated `discount_amount_centavos` must be greater than zero and must not exceed eligible checkout amount or remaining amount due.
12. If a redemption would reduce the payable total to zero, it must use an explicitly approved zero-amount checkout path; otherwise the discount is capped or rejected by rule policy.
13. Admin redemption rule management UI is out of scope unless separately approved.

Recommended value object:

```text
LoyaltyRedemptionResult
```

Fields:

```text
eligible
points_to_debit
discount_amount_centavos
authorized_available_points
remaining_points_after_redemption
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

1. `LoyaltyRedemptionRuleService` returns `LoyaltyRedemptionResult`.
2. `LoyaltyRedemptionService` consumes `LoyaltyRedemptionResult`.
3. Ineligible requests return a result with `eligible = false`, `points_to_debit = 0`, and `discount_amount_centavos = 0`.
4. Ineligible requests create no ledger entry and no sale discount effect.
5. `calculation_metadata` is immutable diagnostic JSON and must not include mutable transport metadata.

## 12. Request Contract

Recommended checkout redemption payload:

```json
{
  "checkout_session_uuid": "checkout-session-uuid",
  "sale_id": "sale-uuid-if-already-created",
  "customer_financial_account_id": "customer-account-uuid",
  "points_requested": 500,
  "redemption_rule_code": "base_points_discount",
  "loyalty_authorization": {
    "verification_method": "cashier_confirmed_customer",
    "verification_reference": "masked-phone-or-customer-reference"
  }
}
```

Recommended sale/payment handoff snapshot:

```json
{
  "snapshot_version": 1,
  "ledger_schema_version": 1,
  "authorization_schema_version": 1,
  "rule_id": "base-points-discount-v1",
  "rule_code": "base_points_discount",
  "rule_version": 1,
  "rule_schema_version": 1,
  "discount_type": "LOYALTY_REDEMPTION",
  "customer_financial_account_id": "account-uuid",
  "authorized_available_points": 1250,
  "points_to_debit": 500,
  "points_redeemed": 500,
  "remaining_points_after_redemption": 750,
  "discount_amount_centavos": 5000,
  "checkout_session_uuid": "checkout-session-uuid",
  "sale_id": "sale-uuid",
  "sale_number": "INV-0001",
  "sale_discount_reference": "sale-discount-or-adjustment-id",
  "branch_id": "branch-uuid",
  "business_date": "2026-07-15",
  "authorized_at": "2026-07-15T10:00:00Z",
  "source": "loyalty_redemption_authorized",
  "rounding": "floor",
  "is_training_mode": false,
  "offline_sales_import_id": null
}
```

Required headers for mutation endpoints:

```text
Idempotency-Key: uuid-or-client-generated-key
X-Tenant-ID: uuid
X-Branch-ID: uuid
```

Validation rules:

1. Either `checkout_session_uuid` or `sale_id` is required at preflight.
2. `sale_id` is required before final point debit posting.
3. `customer_financial_account_id` is required.
4. `customer_financial_account_id` must resolve inside the active tenant.
5. Account must be active.
6. `points_requested` must be a positive integer.
7. `redemption_rule_code` must map to an active rule.
8. Offline context is rejected.
9. Training sale context is rejected.
10. Idempotency key is required.
11. Loyalty authorization payload is required if configured by policy.

## 13. Data Model

Story 39.7 must introduce dedicated immutable redemption evidence.

Recommended table:

```text
loyalty_redemptions
```

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
branch_id UUID NOT NULL
customer_financial_account_id UUID NOT NULL
sale_id UUID NOT NULL
checkout_session_uuid UUID NULL
sale_discount_reference STRING NULL
loyalty_ledger_entry_id UUID NOT NULL
points_redeemed UNSIGNED BIGINT NOT NULL
discount_amount_centavos UNSIGNED BIGINT NOT NULL
discount_type STRING NOT NULL
authorized_available_points UNSIGNED BIGINT NOT NULL
remaining_points_after_redemption UNSIGNED BIGINT NOT NULL
rule_id STRING NOT NULL
rule_code STRING NOT NULL
rule_version UNSIGNED INTEGER NOT NULL
rule_schema_version UNSIGNED SMALLINT NOT NULL
source_snapshot JSON NOT NULL
authorization_snapshot JSON NULL
authorized_at TIMESTAMP NOT NULL
idempotency_key STRING NOT NULL
request_fingerprint STRING(64) NOT NULL
fingerprint_version UNSIGNED SMALLINT NOT NULL DEFAULT 1
business_date DATE NOT NULL
redeemed_by UUID NOT NULL
redeemed_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

Required constraints and indexes:

1. Unique `(tenant_id, sale_id, idempotency_key)`.
2. Unique `(tenant_id, sale_id, customer_financial_account_id)` for first-release one-redemption-per-sale behavior.
3. Unique `(loyalty_ledger_entry_id)`.
4. Index `(tenant_id, customer_financial_account_id)`.
5. Index `(tenant_id, sale_id)`.
6. Index `(tenant_id, checkout_session_uuid)`.
7. Index `(tenant_id, business_date)`.
8. No mutable balance column.
9. No `amount_centavos` column in the loyalty ledger.
10. No `currency_code` in loyalty ledger entries.

Immutability:

1. `loyalty_redemptions` records cannot be updated after creation except for framework timestamps during insert.
2. `loyalty_redemptions` records cannot be deleted.
3. Reversal must use append-only loyalty ledger entries and reversal evidence, not mutation.

## 14. Service Design

### 14.1 `LoyaltyRedemptionRuleService`

Responsibilities:

1. Resolve active redemption rule.
2. Validate rule eligibility.
3. Calculate points to debit.
4. Calculate discount amount.
5. Apply rounding policy.
6. Build immutable rule snapshot.
7. Return `LoyaltyRedemptionResult`.
8. Explain ineligible decisions with reason codes.

Forbidden behavior:

1. Posting ledger rows.
2. Creating sale payments.
3. Writing store credit ledger rows.
4. Creating accounting outbox rows.
5. Mutating finalized sale records.

### 14.2 `LoyaltyRedemptionService`

Responsibilities:

1. Validate account state.
2. Validate tenant and branch scope.
3. Validate offline/training restrictions.
4. Read derived available point balance through `LoyaltyBalanceService`.
5. Call `LoyaltyRedemptionRuleService`.
6. Build authorized point balance snapshot.
7. Enforce idempotency replay and drift detection.
8. Throw `DuplicateLoyaltyRedemptionException` for duplicate sale/source redemption attempts.
9. Coordinate `redemption_debit` posting through `LoyaltyLedgerService`.
10. Create immutable redemption evidence.
11. Audit successful redemption and rejected conflicts.
12. Return exact replay result when idempotency matches.

Forbidden behavior:

1. Directly creating `loyalty_ledger_entries` outside `LoyaltyLedgerService`.
2. Creating `store_credit_ledger_entries`.
3. Creating `SalePayment` rows.
4. Marking sales as paid.
5. Recalculating sale item prices after sale finalization.
6. Creating monetary accounting liability.

### 14.3 Checkout Integration Coordinator

Implementation may introduce a coordinator such as:

```text
LoyaltyCheckoutRedemptionCoordinator
```

Responsibilities:

1. Preflight redemption before sale/payment finalization.
2. Preserve authorized point and rule snapshots.
3. Pass discount-style redemption information into the approved sale-pricing boundary for immutable discount evidence persistence.
4. Carry the sale discount reference into payment finalization context.
5. Recheck derived point balance inside the final payment transaction.
6. Post `redemption_debit` only after payment validation succeeds inside the final payment transaction.
7. Ensure point-debit failure rolls back payment finalization rather than leaving a paid sale with unposted loyalty redemption.
8. Participate in transactions owned by `SaleCreationService` and `PaymentRecordingService`.
9. Never own the sale-pricing or final checkout transaction.

Forbidden behavior:

1. Bypassing `SaleCreationService`.
2. Bypassing `PaymentRecordingService` where payment finalization is involved.
3. Treating points as a payment method.
4. Creating a second idempotency implementation when existing middleware/patterns can be extended.

## 15. Transaction Boundary

Transaction ownership:

Story 39.7 uses two existing transaction owners:

1. `SaleCreationService` owns the transaction that persists the discount-style loyalty redemption evidence on the sale.
2. `PaymentRecordingService` owns the final payment transaction that validates payment, rechecks loyalty balance, posts the `redemption_debit`, creates redemption evidence, and marks the sale paid.

`LoyaltyCheckoutRedemptionCoordinator` participates in those owner transactions. It must not open an independent transaction around sale pricing, payment, and ledger posting.

Pricing phase:

```text
validate customer/account/rule
        |
        v
authorize point redemption snapshot
        |
        v
apply discount_type = LOYALTY_REDEMPTION through approved sale-pricing boundary
        |
        v
persist sale discount or adjustment evidence with checkout/sale reference
```

Finalization phase:

```text
load unpaid sale with loyalty redemption discount evidence
        |
        v
payment validation succeeds
        |
        v
recheck account and point balance under lock/service authority
        |
        v
post loyalty redemption_debit
        |
        v
create loyalty redemption evidence
        |
        v
mark sale paid / commit payment transaction
```

Forbidden transaction shape:

```text
LoyaltyCheckoutRedemptionCoordinator opens transaction
        |
        v
pricing + payment + ledger
        |
        v
commit
```

Failure policy:

1. If rule validation fails, no sale discount and no point debit are created.
2. If sale/pricing validation fails, no point debit is created.
3. If payment/finalization fails, no point debit is created.
4. If point debit fails, the final payment transaction rolls back and the sale must not be marked paid.
5. A sale with persisted loyalty discount evidence but no successful payment remains unpaid and retryable or voidable according to existing sale policy.
6. If a recovery strategy is needed beyond unpaid-sale retry/removal, it must be explicit, auditable, idempotent, and approved before implementation.

## 16. Idempotency and Fingerprinting

All loyalty redemption-producing commands must be idempotent.

Material fingerprint fields:

```text
tenant_id
branch_id
customer_financial_account_id
sale_id
checkout_session_uuid
points_requested
points_to_debit
discount_amount_centavos
sale_discount_reference
authorized_available_points
entry_type
direction
source_type
source_id
business_date
discount_type
rule_id
rule_code
rule_version
rule_schema_version
ledger_schema_version
fingerprint_version
```

Do not include:

1. Headers except the idempotency key itself.
2. Request timestamps.
3. Non-material UI metadata.
4. Mutable customer display fields.
5. Mutable rule display names.

Behavior:

1. First request creates the redemption result.
2. Exact replay returns the original redemption result.
3. Drift rejects with `409`.
4. A same-key exact replay must not throw `DuplicateLoyaltyRedemptionException`.
5. A different idempotency key attempting another redemption for the same `(tenant_id, sale_id, customer_financial_account_id)` rejects with `409` and `DuplicateLoyaltyRedemptionException`.
6. Replay must not duplicate points debit, sale discount, audit rows beyond replay evidence, or downstream side effects.

Recommended idempotency key composition for ledger posting:

```text
loyalty-redemption:{sale_id}:{client_idempotency_key}
```

Recommended source identity:

```text
source_type: sale_loyalty_redemption
source_id: sale_id
```

The redemption evidence row must store the generated loyalty redemption ID and the sale discount reference in the source snapshot so support and reporting can navigate from ledger entry to sale evidence.

## 17. Reversal Contract

Void and refund behavior must be append-only.

Void reversal:

1. Source authority remains `VoidService`.
2. Voiding a sale with loyalty redemption should restore redeemed points through an append-only reversal credit if the approved business policy requires restoration.
3. Reversal snapshot must reference sale, original loyalty redemption, original redemption debit ledger entry, and void evidence.
4. Original redemption debit must not be mutated.

Refund reversal:

1. Source authority remains `RefundService`.
2. Refund behavior for loyalty redemption must be explicitly policy-driven.
3. A refund may restore all, none, or a proportional amount of redeemed points according to the approved refund rule.
4. Reversal snapshot must reference sale, refund, original loyalty redemption, original redemption debit ledger entry, and restoration basis.
5. Original redemption debit must not be mutated.

Story 39.7 minimum scope:

1. Define reversal policy constants and service contract.
2. Ensure original redemption evidence is sufficient for future void/refund reversal.
3. Automatic void/refund hooks may be deferred if the implementation records that reversal execution is out of scope.

## 18. Offline and Training Policy

Offline:

1. Offline loyalty redemption is prohibited.
2. Offline clients must not mutate cached point balances.
3. Offline clients must not create pending redemption debits.
4. Offline sale capture may continue, but loyalty redemption must be unavailable while offline.
5. Server reconciliation must reject offline payloads that include loyalty redemption mutations.

Training:

1. Training sales cannot redeem points.
2. Training sales cannot create loyalty redemption evidence.
3. Training sales cannot create `redemption_debit` ledger rows.
4. Training sales may display simulated loyalty UI only if no persistence occurs.

## 19. Error and Response Codes

| Condition | HTTP status |
| --- | ---: |
| Successful redemption | `201` or `200` for replay |
| Successful eligibility/preflight response | `200` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant hidden resource | `404` |
| Idempotency drift | `409` |
| Duplicate redemption source | `409` |
| Insufficient loyalty balance | `409` |
| Account not redeemable | `409` |
| Inactive redemption rule | `409` |
| Offline redemption rejected | `409` |
| Training sale redemption rejected | `409` |

Recommended insufficient balance payload:

```json
{
  "code": "INSUFFICIENT_LOYALTY_BALANCE",
  "message": "The customer does not have enough available loyalty points for this redemption.",
  "available_points": 250,
  "requested_points": 500
}
```

Recommended idempotency drift payload:

```json
{
  "code": "LOYALTY_REDEMPTION_IDEMPOTENCY_DRIFT",
  "message": "The idempotency key was already used with different loyalty redemption details."
}
```

## 20. Authorization and Permissions

Recommended permissions:

```text
loyalty.view
loyalty.redeem
loyalty.manage
```

Initial guidance:

1. `loyalty.view` permits balance visibility.
2. `loyalty.redeem` permits cashier redemption at checkout.
3. `loyalty.manage` remains reserved for future rule/admin operations.
4. Cashiers may receive `loyalty.view` and `loyalty.redeem` only if merchant policy enables loyalty redemption.
5. Owner/Admin may receive all loyalty permissions.
6. Branch Manager may receive `loyalty.view` and may receive `loyalty.redeem` depending on policy.

Customer verification:

1. Redemption must capture a verification method or policy-approved reason when required.
2. Verification evidence must avoid full customer PII where possible.
3. Verification evidence must be snapshotted with the redemption record.

## 21. Receipt, Reporting, and Audit

Receipt behavior:

1. Loyalty redemption must be visible separately from payment tenders.
2. Receipt should show points redeemed and reward value applied if merchant policy permits.
3. Receipt must not label loyalty points as store credit.
4. Receipt must not label loyalty points as cash.

Reporting behavior:

1. Store credit liability reports must not include loyalty point redemption as monetary liability.
2. Loyalty redemption reporting belongs to Story 39.8 unless minimal operational visibility is required for this story.
3. Any projection introduced here must be rebuildable from ledger and redemption evidence.

Audit requirements:

1. Successful loyalty redemptions are audited.
2. Idempotent replays may be audited compactly.
3. Idempotency drift rejections are audited.
4. Insufficient point rejections are audited if useful for support diagnostics.
5. Audit payload should include redemption id, ledger entry id, account id, sale id, points redeemed, authorized available points, remaining points, discount amount, discount type, rule id, rule version, rule schema version, business date, and actor id.
6. Audit payload must not copy full customer PII.

## 22. Test Plan

Backend feature tests:

1. Active customer account with enough points can redeem through approved checkout path.
2. Redemption posts exactly one `redemption_debit` loyalty ledger entry.
3. Redemption creates immutable redemption evidence.
4. Redemption reduces derived point balance.
5. Redemption cannot create negative derived point balance.
6. Redemption cannot run without active redemption rule.
7. Redemption snapshots rule ID, rule code, rule version, and rule schema version.
8. Redemption snapshots authorized available point balance.
9. Redemption snapshots authorization schema version.
10. Redemption uses `discount_type = LOYALTY_REDEMPTION`.
11. Exact idempotency replay returns the original redemption result.
12. Idempotency drift is rejected.
13. Duplicate sale/source redemption is rejected with `DuplicateLoyaltyRedemptionException` or equivalent loyalty-specific domain exception.
14. Suspended account redemption is rejected.
15. Closed account redemption is rejected.
16. Cross-tenant account redemption is hidden or rejected consistently with existing policy.
17. Offline redemption is rejected.
18. Training sale redemption creates no ledger entry.
19. Failed sale/pricing/payment operation creates no point debit.
20. Point debit failure does not leave silent partial settlement.
21. Loyalty redemption does not create store credit ledger entries.
22. Loyalty redemption does not create monetary accounting outbox rows.
23. Loyalty redemption does not create `SalePayment` rows unless point tender is explicitly approved later.
24. Receipt/payment response distinguishes loyalty redemption from payment tenders.
25. Discount amount cannot exceed eligible checkout amount or remaining amount due.
26. `PaymentRecordingService` owns final payment plus point-debit transaction behavior.
27. Void/refund reversal evidence requirements are preserved.

Regression tests:

1. Loyalty ledger foundation tests still pass.
2. Store credit ledger tests still pass.
3. Store credit redemption tests still pass.
4. Payment recording tests still pass.
5. Dining checkout tests still pass if payment finalization path is touched.
6. Promotion/statutory discount tests still pass if sale-pricing path is touched.
7. Offline sync tests still reject loyalty redemption payloads.

## 23. Acceptance Criteria

1. Loyalty redemption uses an explicit active rule.
2. Loyalty redemption uses discount-style checkout reduction, not cash-equivalent tender.
3. Loyalty redemption does not create store credit.
4. Loyalty redemption does not create `SalePayment` rows.
5. Loyalty redemption posts `redemption_debit` only through `LoyaltyLedgerService`.
6. Loyalty redemption cannot create negative derived point balance.
7. Loyalty redemption requires an active customer financial account.
8. Suspended and closed accounts cannot redeem points.
9. Offline loyalty redemption is rejected.
10. Training sale redemption creates no ledger entry.
11. Exact replay does not duplicate point debit or checkout reduction.
12. Idempotency drift is rejected.
13. Rule ID/version/schema version are snapshotted.
14. Authorized available point balance is snapshotted.
15. Authorization schema version is snapshotted.
16. Redemption source snapshot is immutable.
17. Discount line identity is `LOYALTY_REDEMPTION`.
18. Loyalty redemption never changes promotion eligibility.
19. `PaymentRecordingService` owns the final payment transaction; the checkout coordinator only participates.
20. Failed sale/payment finalization does not debit points.
21. Point debit failure cannot silently leave a paid sale with unposted loyalty redemption.
22. Loyalty redemption creates no monetary accounting liability event.
23. Receipt/reporting labels loyalty redemption separately from payment tenders and store credit.
24. Immutable loyalty redemption evidence is created.
25. Discount amount cannot exceed eligible checkout amount or remaining amount due.
26. Tests prove no store credit, payment-tender, or accounting side effects.

## 24. Implementation Checklist

1. Add `loyalty_redemptions` migration.
2. Add `LoyaltyRedemption` model with immutable guards.
3. Add loyalty redemption domain exceptions.
4. Add `LoyaltyRedemptionRuleService`.
5. Add `LoyaltyRedemptionService`.
6. Add `LoyaltyRedemptionResult` value object or DTO.
7. Add `DuplicateLoyaltyRedemptionException`.
8. Add checkout redemption coordinator.
9. Add rule snapshot builder.
10. Add source snapshot builder.
11. Add authorization snapshot schema version.
12. Add idempotency fingerprinting.
13. Add insufficient-point conflict handling.
14. Add duplicate source redemption checks.
15. Add offline/training rejection.
16. Add audit events with `discount_type = LOYALTY_REDEMPTION`.
17. Add `loyalty.redeem` permission if redemption endpoint/UI is added.
18. Add receipt/response payload fields.
19. Add backend feature tests.
20. Run focused loyalty redemption tests.
21. Run loyalty ledger regression tests.
22. Run store credit redemption regression tests.
23. Run payment and checkout regression tests.
24. Update story status and implementation record after implementation.

## 25. Definition of Done

Story 39.7 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Tenant isolation is verified.
4. Authorization is verified.
5. Offline rejection is verified.
6. Training sale rejection is verified.
7. Ledger immutability is preserved.
8. Derived point balance is verified.
9. Idempotency replay and drift are verified.
10. Rule snapshot versioning is verified.
11. No store credit coupling is introduced.
12. No point tender path is introduced.
13. No monetary accounting liability event is introduced for points.
14. Receipt/response behavior is verified.
15. Existing loyalty ledger tests pass.
16. Existing store credit redemption tests pass.
17. Existing payment tests pass.
18. Code review is approved.
19. Documentation is updated.
