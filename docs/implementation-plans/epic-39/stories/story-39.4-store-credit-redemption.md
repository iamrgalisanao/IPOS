# Story 39.4 Store Credit Redemption

## 1. Status

Done

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
5. `docs/implementation-plans/epic-39/stories/story-39.3-store-credit-refund.md`
6. `app/Services/POS/PaymentRecordingService.php`
7. `app/Http/Controllers/POS/PaymentController.php`
8. `app/Http/Requests/RecordPaymentRequest.php`
9. `app/Http/Requests/RecordSplitPaymentRequest.php`
10. `app/Models/Sale.php`
11. `app/Models/SalePayment.php`
12. `app/Models/PaymentMethod.php`
13. `app/Models/CustomerFinancialAccount.php`
14. `app/Models/StoreCreditLedgerEntry.php`
15. `app/Services/StoreCredit/StoreCreditLedgerService.php`
16. `app/Services/StoreCredit/StoreCreditBalanceService.php`
17. `app/Services/Accounting/AccountingOutboxService.php`
18. `app/Http/Middleware/VerifyIdempotency.php`
19. `tests/Feature/POS/SplitPaymentRecordingTest.php`
20. `tests/Feature/POS/PaymentRecordingTest.php`
21. `tests/Feature/StoreCredit/StoreCreditLedgerFoundationTest.php`

## 3. Objective

Allow store credit to be used as a controlled POS payment tender through the existing payment recording authority.

This story introduces store credit redemption only. It must not create a parallel payment engine, modify sale totals, issue refunds, implement loyalty redemption, allow offline redemption, or expose admin balance editing.

## 4. User Story

As a cashier,
I want to accept a customer's available store credit as part or all of a sale payment,
so that the customer can redeem previously issued credit while IPOS preserves payment, ledger, receipt, accounting, and audit evidence.

## 5. Locked Decisions

1. `PaymentRecordingService` remains the only payment recording authority.
2. Store credit redemption cannot create `SalePayment` rows directly outside the existing payment authority.
3. Store credit redemption must enter the existing single-payment and split-payment POS flows as a tender type.
4. Store credit redemption uses `StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT`.
5. Store credit debit ledger entries are posted only after payment authority validation succeeds.
6. A failed payment recording attempt must not create a store credit debit ledger entry.
7. Payment recording and store credit debit posting must commit atomically.
8. Store credit redemption requires an active customer financial account.
9. Suspended and closed accounts cannot redeem store credit.
10. Store credit redemption must not create a negative derived balance.
11. Store credit redemption must persist an authorized available balance snapshot.
12. Exact idempotency replay must return the original payment/redemption result without creating duplicate payment rows or ledger debit rows.
13. Idempotency drift must be rejected before payment or ledger mutation.
14. Offline store credit redemption is prohibited.
15. Store credit redemption creates accounting outbox evidence for `store_credit_redeemed`.
16. Existing `sale_paid` accounting outbox behavior remains intact.
17. Sales remain immutable; redemption records payment tender only and must not recalculate sale items, taxes, discounts, statutory discounts, or promotions.
18. Story 39.4 must not define refund treatment for sales paid with store credit beyond preserving future reversal-source snapshot references.
19. Store credit balance display in payment response may show the authorized balance snapshot and redeemed amount, but must not introduce a mutable account balance field.
20. Store credit redemption is monetary store credit only; loyalty points remain out of scope.
21. Store credit redemption uses two-phase validation: preflight authorization before payment staging, then account lock and balance recheck inside the payment transaction.
22. Only one active Store Credit payment method may exist per tenant.
23. Store credit payment route idempotency must reuse and extend `VerifyIdempotency`; a second payment-specific idempotency implementation is not approved in this story.

## 6. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Append-Only Store Credit Ledger.
3. Story 39.3 Store Credit Refund Issuance.
4. Existing `PaymentRecordingService`.
5. Existing POS payment endpoints:
   - `pos.sales.payments`
   - `pos.sales.payments.split`
6. Existing active shift, terminal, branch, tenant, and timecard middleware around POS checkout routes.
7. Existing accounting outbox infrastructure.
8. Existing store credit ledger idempotency and insufficient-balance protections.

## 7. Current Codebase Context

Existing payment flow:

1. `PaymentController::store()` records one payment by calling `PaymentRecordingService::record()`.
2. `PaymentController::storeSplit()` records multiple payments by calling `PaymentRecordingService::recordSplit()`.
3. `PaymentRecordingService::recordSplit()` runs inside a database transaction.
4. `PaymentRecordingService::recordSplit()` validates sale existence, active shift, sale status, active payment methods, positive amounts, references, and exact payment total.
5. `PaymentRecordingService::recordSplit()` creates `SalePayment` rows.
6. `PaymentRecordingService::recordSplit()` marks the sale as `paid`.
7. `PaymentRecordingService::recordSplit()` logs payment audit evidence.
8. `PaymentRecordingService::recordSplit()` dispatches inventory deduction after commit for non-training sales.
9. `PaymentRecordingService::recordSplit()` creates `sale_paid` accounting outbox evidence for non-training sales.
10. `PaymentRecordingService::recordSplit()` finalizes dining ticket settlement after successful payment.

Existing idempotency context:

1. POS void/refund routes use `VerifyIdempotency`.
2. POS payment routes do not currently use `VerifyIdempotency`.
3. `VerifyIdempotency` currently records `action_type` as `void` or `refund`.
4. Store credit redemption requires exact replay behavior, so Story 39.4 must extend idempotency coverage for payment routes or provide an equivalent payment-specific idempotency contract.

Existing store credit ledger context:

1. `StoreCreditLedgerService::post()` validates account state, currency, source uniqueness, idempotency, drift, and non-negative balance.
2. `StoreCreditLedgerService::post()` rejects suspended-account debit movements.
3. `StoreCreditLedgerService::post()` rejects closed-account movements.
4. `StoreCreditLedgerService::post()` assigns account-scoped ledger sequence numbers.
5. `StoreCreditLedgerService::post()` creates immutable `store_credit_ledger_entries`.
6. `StoreCreditLedgerService::buildAccountingLiabilityPayload()` creates the shared accounting payload shape.

Implementation implication:

Story 39.4 should extend the existing payment pipeline with a store credit redemption coordinator. It should not create a separate checkout controller, direct `SalePayment` writer, or direct ledger writer.

## 8. Domain Scope

In scope:

1. Store credit tender support in POS payment requests.
2. Store credit redemption coordinator service.
3. Immutable redemption evidence linking sale payment, customer account, and ledger debit.
4. Store credit debit ledger posting after payment recording succeeds.
5. Authorized available balance snapshot preservation.
6. Payment route idempotency for store-credit redemption.
7. Payment replay and drift tests.
8. Insufficient-balance conflict handling.
9. Account-state conflict handling.
10. Offline redemption rejection.
11. Accounting outbox event for `store_credit_redeemed`.
12. Audit events for successful and rejected redemption.
13. Receipt/payment response data needed to identify store credit tender.
14. Backend feature tests for payment boundary, rollback, idempotency, insufficient balance, and reporting visibility.

Out of scope:

1. Loyalty point redemption.
2. Gift cards.
3. Customer wallet UI.
4. Admin balance adjustment execution.
5. Offline store credit redemption.
6. Refund handling for sales previously paid with store credit.
7. Store credit reversal automation.
8. Store credit expiration or forfeiture.
9. Third-party accounting provider delivery.
10. Customer merge.
11. Negative store credit balances.
12. Sale total, tax, discount, statutory discount, promotion, or item recalculation.

## 9. Financial Invariants

1. Payment owns redemption coordination.
2. Payment amount equals store credit debit amount for the store credit tender line.
3. A store credit payment line creates at most one redemption debit ledger entry.
4. A failed payment does not create store credit debit evidence.
5. A successful store credit debit must have a corresponding `SalePayment` reference.
6. Redemption cannot create store credit credit entries.
7. Redemption cannot create refund rows.
8. Redemption cannot create loyalty entries.
9. Redemption cannot reduce derived store credit below zero.
10. Redemption source snapshots are historical evidence and must never be recalculated from mutable sale, payment, customer, or account data.
11. Exact payment replay never duplicates value movement.
12. `sale_paid` and `store_credit_redeemed` accounting evidence must be reconcilable to the same sale/payment transaction.

## 10. Request Contract

Store credit must be represented as a payment tender inside the existing payment request shape.

Recommended split payment request:

```json
{
  "payments": [
    {
      "payment_method_id": "cash-payment-method-uuid",
      "amount": "250.00"
    },
    {
      "payment_method_id": "store-credit-payment-method-uuid",
      "amount": "500.00",
      "customer_financial_account_id": "customer-account-uuid",
      "store_credit_authorization": {
        "verification_method": "cashier_confirmed_customer",
        "verification_reference": "masked-phone-or-customer-reference"
      }
    }
  ]
}
```

Recommended single payment request:

```json
{
  "payment_method_id": "store-credit-payment-method-uuid",
  "amount": "750.00",
  "customer_financial_account_id": "customer-account-uuid",
  "store_credit_authorization": {
    "verification_method": "cashier_confirmed_customer",
    "verification_reference": "masked-phone-or-customer-reference"
  }
}
```

Required headers:

```text
Idempotency-Key: uuid-or-client-generated-key
X-Tenant-ID: uuid
X-Branch-ID: uuid
```

Validation rules:

1. Store credit tender must use an active payment method whose code or type is explicitly recognized as store credit.
2. `customer_financial_account_id` is required for store credit tender lines.
3. `customer_financial_account_id` must resolve inside the active tenant.
4. The customer financial account currency must match the tenant/sale currency.
5. The account must be `active`.
6. Store credit amount must be positive.
7. The derived available balance must be greater than or equal to the requested store credit amount.
8. Store credit tender may be used alone or as part of split payment.
9. Multiple store credit tender lines for the same sale should be rejected unless a future story explicitly approves multiple customer accounts on one sale.
10. Store credit redemption is rejected for offline requests and offline-sync paths.
11. The existing payment total rule remains authoritative: all tender lines together must equal the sale total.
12. Existing reference validation for non-store-credit payment methods remains unchanged.
13. Preflight balance authorization is advisory only; the implementation must re-lock the account and re-check the balance inside the payment transaction before posting the debit.

## 11. Data Model

### 11.1 `store_credit_redemptions`

Purpose:

Immutable evidence linking a `SalePayment` row to the store credit ledger debit entry created for redemption.

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
branch_id UUID NOT NULL
sale_id UUID NOT NULL
sale_payment_id UUID NOT NULL
customer_financial_account_id UUID NOT NULL
store_credit_ledger_entry_id UUID NOT NULL
amount_centavos UNSIGNED BIGINT NOT NULL
currency_code CHAR(3) NOT NULL
idempotency_key STRING NOT NULL
authorized_balance_centavos UNSIGNED BIGINT NOT NULL
source_snapshot JSON NOT NULL
redeemed_by UUID NULL
redeemed_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

Recommended indexes and constraints:

```text
UNIQUE sale_payment_id
UNIQUE store_credit_ledger_entry_id
UNIQUE tenant_id, customer_financial_account_id, idempotency_key
INDEX tenant_id, customer_financial_account_id
INDEX tenant_id, sale_id
INDEX tenant_id, redeemed_at
FOREIGN KEY tenant_id -> tenants.id
FOREIGN KEY branch_id -> branches.id RESTRICT
FOREIGN KEY sale_id -> sales.id RESTRICT
FOREIGN KEY sale_payment_id -> sale_payments.id RESTRICT
FOREIGN KEY customer_financial_account_id -> customer_financial_accounts.id RESTRICT
FOREIGN KEY store_credit_ledger_entry_id -> store_credit_ledger_entries.id RESTRICT
```

Rules:

1. Rows are append-only.
2. `amount_centavos` must equal the corresponding store credit `SalePayment` amount.
3. `amount_centavos` must equal the corresponding ledger debit amount.
4. `authorized_balance_centavos` captures the derived available balance before debit posting.
5. `source_snapshot` must include sale, payment, customer account, authorization, idempotency, payment, and ledger schema versions.
6. `sale_payment_id` is immutable.
7. `store_credit_ledger_entry_id` is immutable.
8. `updated_at` exists only for framework convention and must remain equal to `created_at`.

### 11.2 Existing Tables

`sale_payments`:

Story 39.4 must continue to create payment rows through `PaymentRecordingService`. If extra store-credit metadata is needed, prefer `store_credit_redemptions` over adding mutable payment fields.

`payment_methods`:

Story 39.4 may seed or define a canonical active payment method for store credit. Recommended values:

```text
code: STORE_CREDIT
name: Store Credit
type: store_credit
reference_required: false
settlement_tracking_enabled: true
status: active
```

Rules:

1. Only one active payment method with code `STORE_CREDIT` or type `store_credit` may exist per tenant.
2. Store credit payment methods must not be enabled for offline capture.
3. Store credit payment methods must not require a free-form external reference number.
4. Customer verification evidence belongs in the redemption snapshot, not in `sale_payments.reference_number`.

`accounting_outbox`:

Story 39.4 persists one `store_credit_redeemed` event using the store credit ledger entry as the source. Existing `sale_paid` outbox behavior remains unchanged.

## 12. Service Design

### 12.1 `StoreCreditPaymentCoordinator`

Recommended class:

```text
app/Services/StoreCredit/StoreCreditPaymentCoordinator.php
```

Responsibilities:

1. Detect store credit tender lines in the validated payment payload.
2. Perform preflight customer account, currency, authorization, and available-balance validation.
3. Capture the preflight authorized available balance before payment mutation.
4. Prepare a redemption source snapshot.
5. Lock the customer financial account and re-check available balance inside the payment transaction.
6. Coordinate ledger debit posting after `SalePayment` creation inside the payment transaction.
7. Ensure sequence allocation and debit posting use the existing account-scoped ledger lock path.
8. Persist immutable `store_credit_redemptions` evidence.
9. Persist `store_credit_redeemed` accounting outbox evidence.
10. Log redemption audit events.
11. Map domain exceptions to cashier-safe conflict responses.

Non-responsibilities:

1. It must not create sale payments directly.
2. It must not mark sales paid.
3. It must not dispatch inventory jobs.
4. It must not create receipt records.
5. It must not create refunds or reversals.
6. It must not recalculate sale totals.
7. It must not mutate account balance fields.

### 12.2 Payment Recording Integration

Recommended integration:

1. Extend `PaymentRecordingService::recordSplit()` to identify store credit payment lines before `SalePayment` creation.
2. Perform preflight store credit account, currency, authorization, and available-balance validation before staging payment rows.
3. Create all `SalePayment` rows through the existing payment flow.
4. For each store credit `SalePayment`, call `StoreCreditPaymentCoordinator` inside the same transaction.
5. Inside the coordinator, lock the customer financial account, re-read derived balance, and reject if the balance is no longer sufficient.
6. Post the `redemption_debit` through `StoreCreditLedgerService`, allowing its existing account lock, sequence allocation, idempotency, and non-negative balance protections to remain authoritative.
7. Keep all work inside the existing database transaction.
8. Preserve existing audit, inventory, accounting, and dining finalization behavior.

Recommended sequence:

```text
Validate payment authority
        |
        v
Detect store credit tender line
        |
        v
Validate account and available balance
        |
        v
Create SalePayment through PaymentRecordingService
        |
        v
Lock customer financial account and re-check balance
        |
        v
Post redemption_debit through StoreCreditLedgerService
        |
        v
Create store_credit_redemptions evidence
        |
        v
Create store_credit_redeemed accounting outbox
        |
        v
Mark sale paid, audit, inventory afterCommit, sale_paid outbox, dining settlement
        |
        v
Commit
```

Rollback rule:

If any step fails before commit, the transaction must leave no `SalePayment`, no sale status change, no store credit ledger debit, no redemption evidence, and no accounting outbox row from the failed attempt.

Concurrency rule:

Two terminals may preflight the same account balance concurrently, but only the transaction that successfully locks the account and posts a debit against sufficient remaining balance may commit. Later concurrent attempts must fail with an insufficient-balance conflict or idempotent replay, never overspend the account.

### 12.3 Payment Route Idempotency

Story 39.4 must make store-credit payment requests idempotent by reusing and extending the existing `VerifyIdempotency` middleware.

Required implementation decision:

1. Add `VerifyIdempotency` to POS payment routes that can carry store credit redemption.
2. Update `VerifyIdempotency` action classification so payment requests are recorded as payment actions, not refunds.
3. Keep replay, drift, processing, completed, and failed-state semantics consistent with existing void/refund behavior.

Required behavior:

1. Missing `Idempotency-Key` on a store-credit redemption request returns `400`.
2. Exact replay returns the original payment response.
3. Exact replay does not create duplicate `SalePayment`, `StoreCreditLedgerEntry`, `StoreCreditRedemption`, `sale_paid`, or `store_credit_redeemed` rows.
4. Same idempotency key with changed material payment fields returns `409`.
5. Material fingerprint must include tenant, branch, sale, cashier, customer account, store credit amount, payment method, payment mix, and sale total.
6. Material fingerprint must exclude volatile transport metadata.
7. Existing non-store-credit payment behavior must not regress.

## 13. Source Snapshot Contract

`store_credit_redemptions.source_snapshot` and the corresponding ledger `source_snapshot` must preserve:

```text
snapshot_version
payment_schema_version
ledger_schema_version
customer_account_schema_version
sale_id
sale_number
sale_total
sale_status_before_payment
sale_payment_id
payment_method_id
payment_method_code
customer_financial_account_id
amount_centavos
currency_code
authorized_balance_centavos
business_date
cashier_id
terminal_id when available
shift_id
branch_id
idempotency_key_fingerprint
verification_method
verification_reference_masked
authorization_schema_version
future_reversal_source_reference
redeemed_at
```

Snapshot rules:

1. The snapshot is evidence, not a recalculation source.
2. The snapshot must not store sensitive customer verification secrets.
3. The snapshot must include enough source identifiers for future refund/reversal handling.
4. The snapshot must include schema versions from the first implementation.
5. The ledger and redemption evidence snapshots may share structure but must not depend on mutable relationships after posting.
6. `authorization_schema_version` starts at `1` and identifies the verification/authorization evidence format used for redemption.

## 14. Accounting Contract

Story 39.4 must create `store_credit_redeemed` accounting outbox evidence.

Required payload additions beyond the shared ledger payload:

```text
event_version
sale_id
sale_number
sale_payment_id
payment_method_code
store_credit_redemption_id
customer_financial_account_id
ledger_entry_id
amount_centavos
currency_code
authorized_balance_centavos
redeemed_at
source_snapshot_version
```

Rules:

1. The outbox source should be the `StoreCreditLedgerEntry`.
2. External accounting provider delivery remains out of scope.
3. Export retry behavior remains owned by existing accounting sync infrastructure.
4. `store_credit_redeemed` must be reconcilable with `sale_paid`.
5. Training-mode behavior must be explicit in implementation. Recommended default: skip accounting outbox for training sales, matching existing payment behavior, unless the Architecture Lock is revised.

## 15. Error Responses

Recommended domain responses:

| Condition | HTTP status | Code |
| --- | ---: | --- |
| Successful redemption payment | `200` | `PAYMENT_RECORDED` |
| Missing idempotency key for store credit redemption | `400` | `MISSING_IDEMPOTENCY_KEY` |
| Validation failure | `422` | `INVALID_PAYMENT_REQUEST` |
| Unauthorized | `403` | `UNAUTHORIZED` |
| Cross-tenant/customer-account hidden resource | `404` | `CUSTOMER_ACCOUNT_NOT_FOUND` |
| Idempotency drift | `409` | `IDEMPOTENCY_DRIFT` |
| Insufficient balance | `409` | `INSUFFICIENT_STORE_CREDIT_BALANCE` |
| Suspended or closed account redemption | `409` | `STORE_CREDIT_ACCOUNT_NOT_REDEEMABLE` |
| Offline redemption rejected | `409` | `STORE_CREDIT_REDEMPTION_OFFLINE_NOT_ALLOWED` |
| Duplicate redemption source | `409` | `STORE_CREDIT_REDEMPTION_ALREADY_POSTED` |

Recommended domain exception mapping:

```text
StoreCreditAlreadyRedeemedException -> STORE_CREDIT_REDEMPTION_ALREADY_POSTED
```

Recommended insufficient-balance payload:

```json
{
  "code": "INSUFFICIENT_STORE_CREDIT_BALANCE",
  "message": "The customer does not have enough available store credit for this redemption.",
  "available_centavos": 12500
}
```

## 16. API Response Contract

Existing payment responses should remain compatible.

For store credit redemption, include store-credit details without exposing a mutable balance source:

```json
{
  "status": "recorded",
  "sale_id": "sale-uuid",
  "sale_status": "paid",
  "payment_count": 2,
  "amount_paid": "750.0000",
  "remaining_balance": "0.0000",
  "payments": [
    {
      "payment_id": "payment-uuid",
      "payment_method_id": "store-credit-method-uuid",
      "amount": "500.0000",
      "reference_number": null,
      "store_credit": {
        "customer_financial_account_id": "account-uuid",
        "redemption_id": "redemption-uuid",
        "ledger_entry_id": "ledger-entry-uuid",
        "amount_centavos": 50000,
        "currency_code": "PHP",
        "authorized_balance_centavos": 75000
      }
    }
  ]
}
```

## 17. Authorization and Verification

Required authorization:

1. Existing POS payment route middleware remains authoritative for cashier access.
2. Store credit redemption must require active terminal, branch, tenant, active shift, and clocked-in context through existing route middleware.
3. Customer account lookup must be tenant-scoped.
4. Branch context must match the sale branch.

Customer verification:

1. Story 39.4 must define the verification data accepted at redemption time.
2. Verification evidence should be auditable but non-sensitive.
3. Do not store raw secrets, full IDs, or unnecessary personal data in snapshots.
4. If the first implementation only supports cashier-confirmed verification, that limitation must be explicit in UI/API copy and tests.

## 18. Offline Policy

Store credit redemption is online-only.

Rules:

1. Offline checkout must not accept store credit tender.
2. Offline sync must reject any payload containing store credit payment method code/type.
3. Offline cache may show read-only store credit context only if clearly stale/read-only.
4. No offline mutation may reserve, debit, or adjust store credit.
5. Store credit payment methods must not be enabled for offline branch payment settings.

## 19. Receipt and Reporting Notes

Receipt:

1. Receipt payment breakdown should show `Store Credit` as the tender name.
2. Receipt must not show sensitive customer verification details.
3. Receipts must never display the customer's remaining store credit balance.
4. Only the redeemed amount should appear on the receipt.
5. Receipt may show last masked customer reference only if already approved by privacy policy.
6. Receipt totals must remain based on sale/payment records, not ledger balance projections.

Operational reporting:

1. Existing payment history should include store credit tender through `sale_payments`.
2. Store credit redemption detail should be available through `store_credit_redemptions`.
3. Admin ledger review remains Story 39.5.

Financial reporting:

1. `store_credit_redeemed` outbox and ledger debit provide financial-liability reduction evidence.
2. Full liability reconciliation remains Story 39.8.

## 20. Implementation Tasks

Recommended implementation sequence:

1. Payment method and request foundation
   - Define canonical store credit payment method code/type.
   - Enforce one active `STORE_CREDIT` payment method per tenant.
   - Extend payment request validation for optional `customer_financial_account_id` and `store_credit_authorization`.
   - Add idempotency coverage for store-credit payment requests.

2. Redemption evidence model
   - Add `store_credit_redemptions` migration.
   - Add model, factory, immutability guard, and relationships.
   - Add relationships from `SalePayment` and `StoreCreditLedgerEntry`.

3. Store credit payment coordinator
   - Implement account lookup, two-phase balance authorization, account locking/recheck, snapshot building, ledger debit posting, redemption evidence, accounting outbox, and audit.
   - Map domain exceptions to conflict responses.

4. Payment recording integration
   - Integrate coordinator into `PaymentRecordingService::recordSplit()`.
   - Preserve existing payment, audit, inventory, sale-paid outbox, and dining behavior.
   - Ensure rollback removes all payment and ledger artifacts on failure.

5. Offline and payment settings guard
   - Prevent store credit payment method from being allowed offline.
   - Reject offline sync payloads containing store credit tender.

6. Tests and documentation
   - Add feature tests for successful full and partial store credit redemption.
   - Add replay, drift, insufficient balance, account state, rollback, and offline rejection tests.
   - Update story notes and implementation guide status after PR approval/merge.

## 21. Acceptance Criteria

1. Store credit redemption cannot bypass `PaymentRecordingService`.
2. A successful store credit payment creates a `SalePayment` row through the existing payment authority.
3. A successful store credit payment creates exactly one `redemption_debit` ledger entry.
4. A successful store credit payment creates exactly one immutable `store_credit_redemptions` row.
5. The redemption ledger debit is created only after payment validation succeeds.
6. Failed payment validation creates no store credit debit entry.
7. Failed store credit validation creates no `SalePayment` row and does not mark the sale paid.
8. Insufficient balance returns a domain conflict and preserves existing balance.
9. Suspended and closed accounts cannot redeem.
10. Cross-tenant customer accounts are hidden or rejected without leakage.
11. Concurrent redemptions cannot overspend one account.
12. Exact idempotent replay returns the original payment response.
13. Exact idempotent replay does not duplicate `SalePayment`, ledger, redemption, or accounting outbox rows.
14. Idempotency drift returns a conflict before mutation.
15. Store credit tender can be combined with cash or digital tender as a split payment.
16. Store credit tender alone can pay a sale when the balance is sufficient.
17. Store credit tender appears in payment history/receipt data as a payment method.
18. Only one active Store Credit payment method may exist per tenant.
19. `store_credit_redeemed` accounting outbox evidence is persisted for non-training sales.
20. Existing `sale_paid` outbox behavior remains intact.
21. Offline store credit redemption is rejected.
22. Existing cash, digital, and split payment tests continue to pass.
23. Dining checkout finalization remains triggered only after successful payment recording.
24. Inventory deduction remains unchanged and still dispatches only after successful payment commit.

## 22. Test Plan

Backend feature tests:

1. Full store credit payment succeeds and debits the ledger.
2. Split payment with store credit plus cash succeeds.
3. Split payment with store credit plus digital tender succeeds when references are valid.
4. Insufficient store credit balance returns `409` and creates no payment.
5. Suspended account redemption returns `409` and creates no payment.
6. Closed account redemption returns `409` and creates no payment.
7. Cross-tenant account redemption returns not found/conflict without leakage.
8. Payment amount mismatch creates no ledger debit.
9. Digital tender reference failure creates no store credit debit.
10. Ledger posting failure rolls back sale payment and sale status.
11. Concurrent redemptions against the same account cannot overspend the available balance.
12. Exact replay returns cached payment response.
13. Replay does not duplicate payment, ledger, redemption, or outbox rows.
14. Drift with same idempotency key returns `409`.
15. Store credit redemption creates `store_credit_redeemed` outbox evidence.
16. Training-mode sale skips accounting outbox if implementation follows existing training behavior.
17. Receipt/payment response includes store credit redemption details.
18. Offline sync payload with store credit tender is rejected.
19. Store credit payment method cannot be enabled for offline use.
20. Duplicate active Store Credit payment methods are rejected.
21. Existing `SplitPaymentRecordingTest` remains green.
22. Existing Store Credit Ledger foundation tests remain green.

Suggested focused test files:

```text
tests/Feature/StoreCredit/StoreCreditRedemptionTest.php
tests/Feature/POS/SplitPaymentRecordingTest.php
tests/Feature/POS/PaymentRecordingTest.php
tests/Feature/StoreCredit/StoreCreditLedgerFoundationTest.php
tests/Feature/POS/OfflineSyncTest.php
```

## 23. Out-of-Scope Decisions to Carry Forward

The following decisions must be resolved in future stories before implementation:

1. How to refund a sale previously paid partly or fully with store credit.
2. Whether original-tender refund is mandatory for store credit redemptions.
3. Whether manager override may refund store-credit-paid sales to another method.
4. Whether customers may self-serve wallet lookup or redemption.
5. Whether store credit can expire, forfeit, or be reversed automatically.
6. Whether multiple customer financial accounts may redeem against one sale.

## 24. Definition of Done

Story 39.4 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Payment regression tests pass.
4. Store credit ledger regression tests pass.
5. Offline mutation rejection tests pass.
6. Idempotency replay and drift tests pass.
7. Accounting outbox evidence is verified.
8. Audit events are verified.
9. Tenant and branch isolation are verified.
10. No direct ledger writes exist outside `StoreCreditLedgerService`.
11. No direct payment writes exist outside `PaymentRecordingService`.
12. Documentation/story notes are updated after implementation.
13. Code review is approved.
14. CI is green.
