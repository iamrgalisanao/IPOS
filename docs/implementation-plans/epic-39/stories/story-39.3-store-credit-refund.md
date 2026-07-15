# Story 39.3 Store Credit Refund Issuance

## 1. Status

Approved for Implementation

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
5. `app/Services/POS/RefundService.php`
6. `app/Http/Controllers/POS/VoidRefundController.php`
7. `app/Http/Middleware/VerifyIdempotency.php`
8. `app/Models/SaleRefund.php`
9. `app/Models/PaymentReversal.php`
10. `app/Models/CustomerFinancialAccount.php`
11. `app/Models/StoreCreditLedgerEntry.php`
12. `app/Services/StoreCredit/StoreCreditLedgerService.php`
13. `app/Services/Accounting/AccountingOutboxService.php`
14. `tests/Feature/POS/RefundServiceTest.php`
15. `tests/Feature/POS/VoidRefundControllerTest.php`
16. `tests/Feature/StoreCredit/StoreCreditLedgerFoundationTest.php`

## 3. Objective

Allow eligible refunds to issue store credit through the existing refund authority.

This story introduces refund-to-store-credit issuance only. It must not create a standalone credit issuance feature, store credit redemption, admin adjustments, customer wallet UI, loyalty points, or offline refund credit issuance.

## 4. User Story

As a cashier or authorized refund operator,
I want an approved refund to be issued to a customer's store credit account,
so that the customer receives deterministic credit while the POS preserves refund, accounting, audit, and liability evidence.

## 5. Locked Decisions

1. `RefundService` remains the only refund authority.
2. Store credit issuance cannot bypass refund eligibility, supervisor authorization, shift validation, payment reversal evidence, inventory handling, statutory discount reversal, promotion reversal, audit, or sale status updates.
3. Store credit credit ledger entries are posted only after refund validation succeeds.
4. The refund and the store credit ledger entry commit atomically.
5. If refund posting fails, no store credit ledger entry is committed.
6. If store credit ledger posting fails, the refund-to-credit operation rolls back as a unit.
7. `StoreCreditLedgerService` remains the only store credit ledger posting authority.
8. One `SaleRefund` may issue at most one store credit ledger credit entry.
9. Store credit issuance uses `StoreCreditLedgerEntry::TYPE_REFUND_CREDIT`.
10. Store credit issuance persists accounting outbox evidence for `store_credit_issued`.
11. Existing `sale_refunded` accounting outbox behavior remains intact.
12. Store credit issuance requires an existing customer financial account.
13. The target customer financial account must belong to the same tenant and currency as the sale/refund.
14. Closed customer financial accounts cannot receive refund credit.
15. Suspended customer financial accounts may receive refund credit.
16. Offline store credit issuance is prohibited.
17. No mutable balance column may be introduced.
18. Refund-to-store-credit replay must not create duplicate refund rows, ledger rows, payment reversals, accounting outbox rows, or cash drawer events.
19. Store credit refund payout uses a payout command/strategy abstraction rather than a store-credit-only command shape.
20. Store credit refund responses must not expose derived account balance; balance inspection starts in Story 39.5.

## 6. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Append-Only Store Credit Ledger.
3. Existing `RefundService`.
4. Existing POS refund endpoint with `VerifyIdempotency` middleware.
5. Existing accounting outbox infrastructure.
6. Existing tenant, branch, terminal, and subscription middleware on POS routes.

## 7. Current Codebase Context

Existing refund flow:

1. `VoidRefundController::refund()` handles POS refund requests.
2. The route `pos.sales.refund` is protected by the `idempotent` middleware.
3. `RefundService::refund()` validates sale status and tenant/branch isolation.
4. `RefundService::refund()` creates `SaleRefund`.
5. `RefundService::refund()` creates `SaleRefundItem` rows.
6. `RefundService::refund()` restocks inventory when requested.
7. `RefundService::refund()` creates `PaymentReversal` rows.
8. `RefundService::refund()` updates sale status.
9. `RefundService::refund()` reverses statutory discounts and commercial promotion allocations.
10. `RefundService::refund()` logs audit and creates `sale_refunded` accounting outbox evidence.

Existing store credit ledger flow:

1. `StoreCreditLedgerService::post()` validates account state, currency, source uniqueness, idempotency, and non-negative balance.
2. `StoreCreditLedgerService::post()` uses account-scoped monotonic sequence allocation.
3. `StoreCreditLedgerService::post()` returns replayed entries for exact idempotency replay.
4. `StoreCreditLedgerService::buildAccountingLiabilityPayload()` returns the liability payload shape but Story 39.2 does not persist outbox rows.

Implementation implication:

Story 39.3 should extend the existing refund flow with a store-credit payout path. It should not create a second refund controller, second refund service, or direct ledger writes.

## 8. Domain Scope

In scope:

1. Store credit refund payout option.
2. Existing customer financial account lookup/validation.
3. Refund-to-credit issuance service or value object coordinated by `RefundService`.
4. Store credit ledger credit entry with source refund evidence.
5. Immutable refund issuance evidence linking refund, account, and ledger entry.
6. `store_credit_issued` accounting outbox persistence.
7. Idempotency replay and drift behavior across refund and ledger.
8. Audit events for refund-to-credit issuance and rejection paths.
9. POS refund request validation for store credit payout.
10. Backend feature tests for transaction safety and boundary enforcement.

Out of scope:

1. Store credit redemption.
2. Customer wallet UI.
3. Admin adjustment issuance.
4. Standalone "issue credit" endpoint.
5. Loyalty points.
6. Offline refund credit issuance.
7. External accounting provider delivery.
8. Provider-specific chart-of-account mapping.
9. Refunds of prior store-credit redemptions.
10. Store credit expiration, forfeiture, or reversal automation.

## 9. Financial Invariants

1. The refund remains the source business action.
2. The store credit ledger entry is evidence of refund payout as customer credit.
3. Refund amount and ledger credit amount must match in integer centavos.
4. A refund-to-credit operation cannot create cash drawer payout events.
5. A refund-to-credit operation cannot create store credit debit entries.
6. A refund-to-credit operation cannot create loyalty ledger entries.
7. Payment reversal evidence remains part of the refund authority unless the Architecture Lock is revised.
8. Accounting liability evidence for store credit issuance is mandatory.
9. Ledger source snapshots are immutable and must not be recalculated from mutable refund/sale records.
10. One refund source maps to at most one refund credit ledger entry.

## 10. Refund Financial Invariants

1. Refund owns issuance.
2. Refund amount equals ledger credit amount.
3. One refund creates at most one store credit credit.
4. Refund-to-credit never creates a store credit debit.
5. Refund-to-credit never bypasses `PaymentReversal` evidence.
6. Refund-to-credit never bypasses audit.
7. Refund-to-credit never bypasses accounting liability evidence.
8. Refund replay never duplicates value.
9. Issuance evidence is immutable.
10. Store credit payout never creates cash payout.

## 11. Request Contract

Extend the existing POS refund request when the operator chooses store credit payout.

Recommended request fields:

```json
{
  "items": [
    {
      "sale_item_id": "uuid",
      "quantity": 1
    }
  ],
  "payout_method": "store_credit",
  "customer_financial_account_id": "uuid",
  "reason_code": "RETURN",
  "reason_notes": "Customer requested store credit",
  "supervisor_email": "manager@example.test",
  "supervisor_password": "secret"
}
```

Required headers:

```text
Idempotency-Key: uuid-or-client-generated-key
X-Tenant-ID: uuid
X-Branch-ID: uuid
```

Validation rules:

1. `payout_method` may include existing values and adds `store_credit`.
2. `customer_financial_account_id` is required when `payout_method = store_credit`.
3. `customer_financial_account_id` must resolve inside the active tenant.
4. The account currency must match the sale/refund currency.
5. Closed accounts return a domain conflict.
6. Suspended accounts may receive refund credit.
7. Store credit payout is rejected when the request is offline or arrives through offline sync.
8. The existing POS refund permission/supervisor path remains authoritative.

## 12. Data Model

### 12.1 `store_credit_refund_issuances`

Purpose:

Immutable evidence linking a `SaleRefund` to the store credit ledger entry created from that refund.

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
branch_id UUID NOT NULL
sale_id UUID NOT NULL
sale_refund_id UUID NOT NULL
customer_financial_account_id UUID NOT NULL
store_credit_ledger_entry_id UUID NOT NULL
amount_centavos UNSIGNED BIGINT NOT NULL
currency_code CHAR(3) NOT NULL
idempotency_key STRING NOT NULL
source_snapshot JSON NOT NULL
issued_by UUID NULL
issued_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

Recommended indexes and constraints:

```text
UNIQUE sale_refund_id
UNIQUE store_credit_ledger_entry_id
UNIQUE tenant_id, customer_financial_account_id, idempotency_key
INDEX tenant_id, customer_financial_account_id
INDEX tenant_id, sale_id
INDEX tenant_id, issued_at
FOREIGN KEY sale_refund_id -> sale_refunds.id RESTRICT
FOREIGN KEY store_credit_ledger_entry_id -> store_credit_ledger_entries.id RESTRICT
FOREIGN KEY customer_financial_account_id -> customer_financial_accounts.id RESTRICT
```

Rules:

1. Rows are append-only.
2. `amount_centavos` must equal the corresponding ledger entry amount.
3. `source_snapshot` must include refund, sale, customer account, idempotency, and ledger schema versions.
4. `updated_at` exists only for framework convention and must remain equal to `created_at`.
5. `sale_refund_id` is immutable.
6. `store_credit_ledger_entry_id` is immutable.

### 12.2 Existing Tables

`sale_refunds`:

Do not mutate existing `SaleRefund` rows after creation. If additional refund payout metadata is needed, prefer the immutable issuance evidence table over updating `sale_refunds`.

`payment_reversals`:

Existing refund payment reversal behavior remains under `RefundService`. Story 39.3 must not modify `PaymentReversal` schema for payout metadata. Store credit payout evidence belongs in `store_credit_refund_issuances`.

`accounting_outbox`:

Story 39.3 persists one `store_credit_issued` event using the store credit ledger entry as the source.

## 13. Service Design

### 13.1 `RefundService`

Extend `RefundService::refund()` through a typed options object or command array.

Recommended command:

```text
RefundPayoutCommand
```

Fields:

```text
payout_method
customer_financial_account_id
idempotency_key
requested_by
approval_reference
source_channel
```

Recommended strategy/collaborator options:

```text
CashRefundPayout
ElectronicRefundPayout
StoreCreditRefundPayout
```

Rules:

1. Default refund behavior remains unchanged when no store credit command is provided.
2. Store credit issuance is executed inside the existing refund transaction.
3. Store credit issuance is performed after refund amount calculation and refund authority validation.
4. Store credit issuance is performed before transaction commit.
5. `RefundService` never writes `store_credit_ledger_entries` directly.
6. `RefundService` calls a dedicated issuer/coordinator that uses `StoreCreditLedgerService`.
7. Store credit payout evidence lives in `store_credit_refund_issuances`, not `PaymentReversal`.

### 13.2 `StoreCreditRefundIssuer`

Create:

```text
app/Services/StoreCredit/StoreCreditRefundIssuer.php
```

Responsibilities:

1. Resolve and validate the customer financial account.
2. Convert refund total to integer centavos.
3. Build the immutable refund source snapshot.
4. Call `StoreCreditLedgerService::post()` with `TYPE_REFUND_CREDIT`.
5. Create `store_credit_refund_issuances`.
6. Persist the `store_credit_issued` accounting outbox row.
7. Log audit events.

Ownership rule:

`StoreCreditRefundIssuer` is an internal collaborator owned by `RefundService`. Controllers, jobs, seeders, tests, and unrelated services must not call it as a public issuance API.

Posting payload to `StoreCreditLedgerService`:

```text
entry_type = refund_credit
direction = credit
amount_centavos = refund_total * 100
currency_code = customer account currency
source_type = sale_refund
source_id = sale_refund.id
source_reference = sale_refund.refund_number or sale_refund.id
source_snapshot = immutable refund-to-credit snapshot
idempotency_key = refund request idempotency key or derived refund-credit key
business_date = refund business date
branch_id = sale_refund.branch_id
```

Required source snapshot fields:

```text
snapshot_version = 1
refund_schema_version = 1
ledger_schema_version = 1
customer_account_schema_version = 1
sale_id
sale_refund_id
refund_number
refund_reason_code
refund_total
amount_centavos
currency_code
customer_financial_account_id
idempotency_key_fingerprint
issued_by
issued_at
```

### 13.3 Accounting Outbox

Persist:

```text
event_type = store_credit_issued
source_type = store_credit_ledger_entry
source_id = store_credit_ledger_entries.id
payload = StoreCreditLedgerService::buildAccountingLiabilityPayload(entry)
```

Rules:

1. Outbox persistence occurs in the same transaction as refund and ledger posting.
2. Outbox payload includes `event_version = 1`.
3. Outbox payload includes refund ID, sale ID, customer account ID, ledger entry ID, amount, currency, branch ID, ledger sequence, refund reason code, and source snapshot version.
4. External provider delivery remains out of scope.
5. Existing `sale_refunded` outbox event remains separate from `store_credit_issued`.

## 14. Domain Exceptions

Recommended domain errors:

```text
StoreCreditRefundAlreadyIssuedException
StoreCreditRefundAccountConflictException
StoreCreditRefundCurrencyMismatchException
StoreCreditRefundOfflineNotAllowedException
```

`StoreCreditRefundAlreadyIssuedException` should be used when a refund already has store credit issuance evidence, instead of surfacing a generic ledger source conflict.

## 15. Idempotency and Source Uniqueness

HTTP idempotency:

1. The existing `VerifyIdempotency` middleware remains the request-level replay authority for the POS refund endpoint.
2. Exact HTTP replay must return the original refund response and must not create duplicate refund, ledger, issuance, payment reversal, accounting outbox, or cash drawer rows.
3. HTTP idempotency drift remains rejected by the middleware.

Ledger idempotency:

1. The ledger idempotency key must be stable for the refund-to-credit movement.
2. Exact ledger replay must return the original ledger entry.
3. Ledger drift must be rejected by `StoreCreditLedgerService`.
4. `source_type = sale_refund` and `source_id = sale_refund.id` must prevent duplicate credit issuance for one refund.

Recommended derived key:

```text
refund-credit:{sale_refund_id}:{request_idempotency_key}
```

If the route-level idempotency key is unavailable to service tests, service-level commands must provide an explicit key.

## 16. Transaction Boundary

Required transaction:

```text
Validate refund authority
        |
        v
Calculate refund total
        |
        v
Create SaleRefund and SaleRefundItem rows
        |
        v
Create PaymentReversal rows
        |
        v
Apply inventory/restock, statutory, and promotion reversal effects
        |
        v
Post StoreCreditLedgerEntry refund_credit
        |
        v
Create StoreCreditRefundIssuance evidence
        |
        v
Create store_credit_issued accounting outbox row
        |
        v
Commit
```

Failure behavior:

1. Failure before `SaleRefund` creation commits nothing.
2. Failure after `SaleRefund` creation but before ledger posting rolls back the refund.
3. Failure after ledger posting but before outbox persistence rolls back refund, ledger, issuance, and outbox.
4. No recovery queue is introduced in Story 39.3.

## 17. Audit Events

Required audit actions:

```text
STORE_CREDIT_REFUND_ISSUED
STORE_CREDIT_REFUND_REPLAYED
STORE_CREDIT_REFUND_REJECTED
STORE_CREDIT_REFUND_ROLLED_BACK
```

Audit payload requirements:

1. Include sale ID.
2. Include sale refund ID where present.
3. Include customer financial account ID.
4. Include store credit ledger entry ID where present.
5. Include amount centavos and currency.
6. Include reason code.
7. Include idempotency key fingerprint only, not secrets.
8. Include approval/supervisor reference when available.
9. Include `event_version = 1`.

`STORE_CREDIT_REFUND_ROLLED_BACK` should be emitted only when an unexpected transaction failure is caught at a boundary where diagnostic audit logging is safe and does not imply committed value movement.

## 18. Response Contract

Successful store credit refund response should preserve the existing refund shape and add store credit details.

Recommended JSON addition:

```json
{
  "success": true,
  "message": "Refund processed successfully.",
  "data": {
    "refund_id": "uuid",
    "sale_id": "uuid",
    "refund_total": "100.0000",
    "status": "refunded",
    "payout_method": "store_credit",
    "store_credit": {
      "customer_financial_account_id": "uuid",
      "ledger_entry_id": "uuid",
      "amount_centavos": 10000,
      "currency_code": "PHP"
    }
  }
}
```

Response rules:

1. Include ledger entry ID, account ID, amount, and currency.
2. Do not include derived account balance in Story 39.3.
3. Derived balance display belongs to Story 39.5.

Conflict responses:

| Condition | HTTP status |
| --- | ---: |
| Missing customer financial account for store credit payout | `422` |
| Cross-tenant account | `404` |
| Currency mismatch | `409` |
| Closed customer financial account | `409` |
| Duplicate refund source credit | `409` |
| Idempotency drift | `409` |
| Offline store credit issuance | `409` |

## 19. Testing Requirements

Create or extend tests under:

```text
tests/Feature/StoreCredit/StoreCreditRefundIssuanceTest.php
tests/Feature/POS/VoidRefundControllerTest.php
tests/Feature/POS/RefundServiceTest.php
```

Required tests:

1. Store credit refund creates `SaleRefund`.
2. Store credit refund creates expected `SaleRefundItem` rows.
3. Store credit refund creates existing `PaymentReversal` evidence.
4. Store credit refund posts one `refund_credit` ledger entry.
5. Store credit refund creates one `store_credit_refund_issuance` evidence row.
6. Store credit refund creates one `store_credit_issued` accounting outbox row.
7. Existing `sale_refunded` outbox event still exists.
8. Ledger amount equals refund total converted to centavos.
9. Ledger source type and source ID point to `sale_refund`.
10. Exact request replay returns original response and does not duplicate refund, ledger, issuance, reversal, or outbox rows.
11. Idempotency drift is rejected.
12. Duplicate credit issuance for the same `SaleRefund` is rejected.
13. Closed customer financial account is rejected.
14. Suspended customer financial account can receive refund credit.
15. Currency mismatch is rejected.
16. Cross-tenant customer financial account is hidden or rejected without disclosure.
17. Failure in ledger posting rolls back the refund.
18. Failure in accounting outbox persistence rolls back refund and ledger.
19. Store credit refund does not create cash drawer payout events.
20. Store credit refund does not create store credit debit, loyalty, or wallet balance rows.
21. Existing cash refund behavior remains unchanged.
22. Existing electronic closed-shift manual refund request behavior remains unchanged.
23. Offline store credit issuance is rejected.

## 20. Acceptance Criteria

1. Store credit refund issuance is available only through the existing refund flow.
2. `RefundService` remains the refund authority.
3. `StoreCreditLedgerService` remains the ledger authority.
4. Store credit refund requires an existing customer financial account.
5. Refund and ledger entry commit atomically.
6. Duplicate issuance for the same refund is blocked.
7. Exact replay does not duplicate financial movement.
8. Drift is rejected.
9. Closed accounts cannot receive refund credit.
10. Suspended accounts may receive refund credit.
11. Store credit refund persists accounting liability evidence.
12. Existing `sale_refunded` evidence remains intact.
13. No standalone credit issuance route is introduced.
14. No redemption behavior is introduced.
15. No offline mutation path is introduced.
16. Store credit refund response does not include derived account balance.
17. Store credit payout evidence is stored in `store_credit_refund_issuances`, not `PaymentReversal`.

## 21. Implementation Checklist

1. Create immutable `store_credit_refund_issuances` migration.
2. Create `StoreCreditRefundIssuance` model and factory.
3. Create `StoreCreditRefundIssuer`.
4. Extend `RefundService` with a `RefundPayoutCommand` or payout strategy option.
5. Extend `VoidRefundController::refund()` request handling for `payout_method = store_credit`.
6. Add request validation for `customer_financial_account_id`.
7. Ensure existing cash/electronic refund behavior remains unchanged.
8. Persist `store_credit_issued` accounting outbox row.
9. Add audit events.
10. Add rollback tests.
11. Add idempotency replay/drift tests.
12. Run focused POS refund and store credit tests.
13. Update story/guide status after implementation review.

## 22. Developer Guardrails

1. Do not create standalone credit issuance endpoints.
2. Do not write ledger rows outside `StoreCreditLedgerService`.
3. Do not create mutable store credit balance columns.
4. Do not skip `PaymentReversal` evidence without a formal architecture revision.
5. Do not merge `sale_refunded` and `store_credit_issued` accounting events into one event.
6. Do not persist accounting provider-specific fields in this story.
7. Do not create cash drawer payout events for store credit payout.
8. Do not allow offline refund-to-credit issuance.
9. Do not add store credit redemption behavior.
10. Do not auto-create customer financial accounts silently during refund issuance.
11. Do not expose derived account balance in Story 39.3 responses.
12. Do not call `StoreCreditRefundIssuer` outside `RefundService`.

## 23. Resolved Review Decisions

1. Use `RefundPayoutCommand` or a payout strategy abstraction rather than a store-credit-specific command.
2. Do not modify `PaymentReversal` schema in Story 39.3; payout evidence lives in `store_credit_refund_issuances`.
3. Suspended accounts may receive refund credit without approval beyond the existing refund authorization path.
4. Do not return derived account balance in Story 39.3; balance inspection begins in Story 39.5.
