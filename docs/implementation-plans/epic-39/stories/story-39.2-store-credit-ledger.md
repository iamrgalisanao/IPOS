# Story 39.2 Append-Only Store Credit Ledger

## 1. Status

Approved for Implementation

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `app/Models/CustomerFinancialAccount.php`
5. `app/Services/Customers/CustomerFinancialAccountService.php`
6. `app/Models/AccountingOutbox.php`
7. `database/migrations/2026_05_11_105826_create_accounting_outbox_table.php`
8. `app/Models/SalePayment.php`
9. `app/Models/PaymentReversal.php`
10. `app/Traits/BelongsToTenant.php`
11. `app/Services/AuditLogger.php`

## 3. Objective

Create the append-only monetary store credit ledger and deterministic balance reconstruction foundation.

This story establishes the ledger infrastructure only. It must not issue store credit from refunds, redeem store credit as payment, expose wallet balance editing, integrate external accounting providers, or create loyalty points.

## 4. User Story

As an accounting-aware tenant administrator,
I want store credit movements recorded as immutable ledger entries under a customer financial account,
so that future refund issuance, redemption, liability reporting, and reconciliation can rely on deterministic evidence instead of mutable wallet balances.

## 5. Locked Decisions

1. Store credit is monetary value and uses integer centavos.
2. Store credit ledger rows are append-only after posting.
3. `CustomerFinancialAccount` is the sole aggregate owner for store credit ledger rows.
4. Balances are derived from ledger rows, not stored as authoritative account fields.
5. Story 39.2 may create ledger entries only through internal services and test fixtures for foundation validation.
6. Story 39.2 must not implement refund issuance, payment redemption, or loyalty points.
7. Every ledger-producing command is idempotent.
8. Idempotency replay returns the original ledger entry/result.
9. Idempotency drift is rejected.
10. Store credit ledger entries carry account-scoped monotonic sequence numbers.
11. Ledger snapshots include `ledger_schema_version`.
12. Accounting liability event/outbox contract shape is defined in this story.
13. External accounting provider delivery is out of scope.
14. Negative store credit balance is prohibited unless the Architecture Lock is formally revised.
15. `store_credit_ledger_sequences` is the durable sequence allocation strategy for Story 39.2.
16. Story 39.2 implements an accounting payload builder but does not persist accounting outbox rows for foundation-only test postings.
17. Story 39.2 does not expose ledger read or write endpoints; admin inspection begins in Story 39.5.

## 6. Financial Invariants

1. Ledger rows are immutable after posting.
2. Store credit balance is derived from ledger history.
3. Store credit balance cannot become negative.
4. Ledger sequence is monotonic per customer financial account.
5. Account currency is immutable.
6. Ledger amounts are positive integer centavos.
7. Entry direction determines financial sign; negative amounts are prohibited.
8. Only `CustomerFinancialAccount` owns store credit ledger rows.
9. Idempotent replays never duplicate value.
10. Idempotency drift never mutates value.
11. Source snapshots are immutable evidence and must never be rewritten even if the source transaction later changes.

## 7. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Accounting liability decision in the Epic 39 Architecture Lock.
3. Existing `AccountingOutbox` append-only/idempotent event pattern.
4. Existing tenant context and audit logging.

## 8. Current Codebase Context

Existing Story 39.1 foundation:

1. `customers` table exists.
2. `customer_financial_accounts` table exists.
3. `CustomerFinancialAccount` has immutable `customer_id` and `currency_code`.
4. Account states are `active`, `suspended`, and `closed`.
5. Accounts have no balance columns.
6. Account routes are API-first.

Existing accounting context:

1. `AccountingOutbox` exists and is append-only for identity fields and payload.
2. `accounting_outbox` has unique `event_type`, `source_type`, and `source_id`.
3. `AccountingOutbox` currently requires `branch_id`.
4. Existing accounting transport/provider delivery is already its own concern and must not be expanded in this story.

Implementation implication:

Story 39.2 should extend the customer-account aggregate with ledger infrastructure and a liability-event contract, while keeping refund/payment/accounting transport integrations deferred.

## 9. Domain Scope

In scope:

1. Store credit ledger schema.
2. Store credit ledger model.
3. Store credit ledger service.
4. Account-scoped ledger sequence allocation.
5. Idempotency key and request fingerprinting.
6. Derived balance service.
7. Ledger immutability guards.
8. Ledger snapshot schema versioning.
9. Accounting liability event/outbox contract shape.
10. Audit events for ledger posting in this foundation slice.
11. Feature tests for ledger posting, rebuild, immutability, idempotency, and outbox contract.

Out of scope:

1. Refund-to-store-credit issuance.
2. Store credit redemption.
3. Loyalty points.
4. Customer-facing wallet UI.
5. Admin manual adjustment execution workflow.
6. External accounting provider delivery.
7. Provider credentials.
8. Provider-specific chart-of-account mapping.
9. Expiration or forfeiture automation.
10. Negative balances.
11. Balance cache/projection table unless needed only as a non-authoritative internal optimization.

## 10. Data Model

### 10.1 `store_credit_ledger_entries`

Purpose:

Immutable monetary ledger for store credit value movement.

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
amount_centavos UNSIGNED BIGINT NOT NULL
currency_code CHAR(3) NOT NULL
source_type STRING NOT NULL
source_id UUID NULL
source_reference STRING NULL
source_snapshot JSON NOT NULL
idempotency_key STRING NOT NULL
request_fingerprint STRING NOT NULL
fingerprint_version UNSIGNED SMALLINT NOT NULL DEFAULT 1
business_date DATE NOT NULL
posted_by UUID NULL
posted_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

Recommended indexes and constraints:

```text
INDEX tenant_id, customer_financial_account_id
INDEX tenant_id, customer_financial_account_id, ledger_sequence
INDEX tenant_id, customer_financial_account_id, posted_at
INDEX tenant_id, business_date
INDEX tenant_id, source_type, source_id
UNIQUE customer_financial_account_id, ledger_sequence
UNIQUE tenant_id, customer_financial_account_id, idempotency_key
FOREIGN KEY tenant_id -> tenants.id
FOREIGN KEY branch_id -> branches.id NULLABLE RESTRICT
FOREIGN KEY customer_financial_account_id -> customer_financial_accounts.id RESTRICT
```

Column rules:

1. `amount_centavos` must be a positive integer.
2. `direction` is one of `credit` or `debit`.
3. `ledger_category` is one of `credit`, `debit`, `adjustment`, `reversal`, or `expiration`.
4. `ledger_schema_version` starts at `1`.
5. `currency_code` must equal the owning account currency.
6. `source_snapshot` must include `ledger_schema_version`.
7. `idempotency_key` must be caller-provided for ledger-producing commands.
8. `request_fingerprint` hashes only material business fields.
9. `branch_id` is nullable because account-level adjustments may not be branch-originated in future stories.
10. `fingerprint_version` starts at `1` and allows future fingerprint algorithm changes without invalidating old rows.
11. `updated_at` exists only to satisfy framework conventions and must always equal `created_at`.
12. `source_snapshot` is immutable evidence and must never be rewritten after posting.
13. Negative `amount_centavos` values are prohibited; sign is determined only by `direction`.

### 10.2 Sequence State

Required table:

```text
store_credit_ledger_sequences
```

Purpose:

Durable account-scoped monotonic sequence allocation without deriving the next sequence from `MAX(ledger_sequence)`.

Recommended columns:

```text
id UUID PRIMARY KEY
tenant_id UUID NOT NULL
customer_financial_account_id UUID NOT NULL
next_sequence UNSIGNED BIGINT NOT NULL DEFAULT 1
created_at TIMESTAMP
updated_at TIMESTAMP
```

Recommended constraints:

```text
UNIQUE tenant_id, customer_financial_account_id
FOREIGN KEY customer_financial_account_id -> customer_financial_accounts.id RESTRICT
```

Rules:

1. Sequence allocation must happen inside the same transaction as ledger posting.
2. Sequence rows must be locked before incrementing.
3. Gaps are acceptable only if the allocator reserves numbers during rolled-back or failed operations; gaps must never imply value movement.
4. The posting transaction must lock the customer financial account, allocate the sequence, insert the ledger row, build any required accounting payload, and commit atomically.

## 11. Entry Types and Categories

Story 39.2 defines the supported values but may only exercise controlled foundation entries through service-level tests and internal fixtures.

Store credit entry types:

```text
refund_credit
redemption_debit
admin_credit_adjustment
admin_debit_adjustment
reversal_credit
reversal_debit
expiration_debit
forfeiture_debit
```

Entry type mapping:

| Entry Type | Direction | Category | Story That Uses It |
| --- | --- | --- | --- |
| `refund_credit` | credit | credit | 39.3 |
| `redemption_debit` | debit | debit | 39.4 |
| `admin_credit_adjustment` | credit | adjustment | later approval story |
| `admin_debit_adjustment` | debit | adjustment | later approval story |
| `reversal_credit` | credit | reversal | later reversal handling |
| `reversal_debit` | debit | reversal | later reversal handling |
| `expiration_debit` | debit | expiration | future expiration story |
| `forfeiture_debit` | debit | expiration | future forfeiture story |

Story 39.2 should not expose public admin adjustment execution. Test fixtures may post controlled entries through the service to verify infrastructure.

## 12. Service Layer

Create:

```text
app/Services/StoreCredit/StoreCreditLedgerService.php
app/Services/StoreCredit/StoreCreditBalanceService.php
app/Services/StoreCredit/StoreCreditLedgerSequenceService.php
```

Responsibilities:

1. Validate account state.
2. Validate account currency.
3. Validate amount and direction.
4. Validate idempotency.
5. Allocate account-scoped sequence.
6. Post ledger entries.
7. Derive balances from ledger rows.
8. Build ledger source snapshots.
9. Create liability outbox event rows for monetary ledger movement when required.

Service rules:

1. All posting occurs inside a database transaction.
2. Controllers, seeders, tests, and future services must not write ledger rows directly unless explicitly using factories for isolated setup.
3. `active` accounts may post any allowed foundation movement.
4. `suspended` accounts may receive recovery credits/reversals but cannot post debit/redemption-style entries.
5. `closed` accounts cannot receive new value movement in Story 39.2.
6. Debits must fail if derived balance would become negative.
7. Currency mismatch returns a domain conflict.
8. Idempotent replay returns the original ledger entry.
9. Idempotency drift returns a domain conflict.
10. Source uniqueness should protect one-time source movements when `source_type` and `source_id` are present.

Recommended domain errors:

```text
StoreCreditLedgerIdempotencyDriftException
StoreCreditLedgerInsufficientBalanceException
StoreCreditLedgerAccountStateException
StoreCreditLedgerCurrencyMismatchException
StoreCreditLedgerImmutableException
StoreCreditLedgerSequenceException
StoreCreditLedgerSourceConflictException
```

## 13. Idempotency Contract

Required input:

```text
idempotency_key
```

Material fingerprint fields:

```text
tenant_id
branch_id
customer_financial_account_id
entry_type
direction
amount_centavos
currency_code
source_type
source_id
source_reference
business_date
ledger_schema_version
```

Rules:

1. First request posts the ledger entry.
2. Exact replay returns the existing entry and does not create another row.
3. Same key with changed material fields is rejected.
4. Transport metadata, request timestamps, headers, IP address, and user agent are excluded from the fingerprint.
5. The fingerprint should be stable across JSON key ordering.
6. `fingerprint_version` must be persisted with each ledger row.

Recommended conflict payload:

```json
{
  "code": "STORE_CREDIT_IDEMPOTENCY_DRIFT",
  "message": "The idempotency key was already used with different store credit ledger details."
}
```

## 14. Derived Balance

Create:

```text
StoreCreditBalanceService::availableBalanceCentavos(CustomerFinancialAccount $account): int
```

Balance formula:

```text
sum(credit amount_centavos) - sum(debit amount_centavos)
```

Rules:

1. Balance is derived from `store_credit_ledger_entries`.
2. No authoritative balance column is added to `customer_financial_accounts`.
3. Repeated rebuilds must produce identical balances.
4. Balance rebuild must order by `ledger_sequence` for deterministic statement/replay behavior.
5. Balance service must return integer centavos.
6. Decimal money strings are not accepted as ledger amounts in this story.
7. For Epic 39, available balance equals ledger balance; reserved and pending balances are out of scope.

## 15. Accounting Liability Event Contract

Story 39.2 defines the liability event shape and payload builder. It does not persist accounting outbox rows for foundation-only test postings and does not deliver to external accounting providers.

Recommended event types:

```text
store_credit_issued
store_credit_redeemed
store_credit_reversed
store_credit_adjusted
store_credit_expired
```

Recommended outbox source:

```text
source_type = store_credit_ledger_entry
source_id = store_credit_ledger_entries.id
```

Required payload fields:

```json
{
  "event_version": 1,
  "ledger_entry_id": "uuid",
  "tenant_id": "uuid",
  "branch_id": "uuid-or-null",
  "customer_financial_account_id": "uuid",
  "account_currency": "PHP",
  "entry_type": "refund_credit",
  "ledger_category": "credit",
  "direction": "credit",
  "amount_centavos": 10000,
  "currency_code": "PHP",
  "business_date": "2026-07-15",
  "ledger_sequence": 1,
  "posted_at": "iso-8601",
  "source_type": "manual_foundation_test",
  "source_id": "uuid-or-null",
  "ledger_schema_version": 1
}
```

Payload and outbox rules:

1. Build payloads only through the ledger service or a dedicated liability payload builder.
2. Story 39.2 verifies payload shape without persisting foundation-only outbox rows.
3. Future outbox persistence must occur in the same transaction as real ledger posting.
4. Future outbox identity is idempotent by `event_type`, `source_type`, and `source_id`.
5. Export provider, credentials, external IDs, and chart-of-account mapping are out of scope.
6. `store_credit_ledger_entries.branch_id` remains nullable; non-null outbox branch requirements are handled by future outbox-producing stories and must not force the ledger to invent a branch.

## 16. API Surface

Story 39.2 does not expose production-facing ledger endpoints.

Deferred read-only API-first surface:

```text
GET /admin/customer-accounts/{customerFinancialAccount}/store-credit-ledger
```

Future purpose:

Read-only ledger history and derived balance inspection for authorized admin/accounting users. This belongs to Story 39.5 unless separately approved.

Permission:

```text
customer-accounts.view
```

Posting endpoints:

Story 39.2 should not expose a ledger posting endpoint. Real ledger-producing commands begin in later stories, such as refund issuance, redemption, approved adjustment, or reversal flows. This story may still implement the internal service methods required for those future commands and verify them through service-level tests.

## 17. Audit Events

Use `AuditLogger`.

Required audit actions:

```text
STORE_CREDIT_LEDGER_ENTRY_POSTED
STORE_CREDIT_LEDGER_IDEMPOTENCY_REPLAYED
STORE_CREDIT_LEDGER_IDEMPOTENCY_DRIFT_REJECTED
STORE_CREDIT_LEDGER_INSUFFICIENT_BALANCE_REJECTED
```

Audit payload requirements:

1. Include ledger entry ID where present.
2. Include customer financial account ID.
3. Include entry type, category, direction, amount, currency, sequence, and business date.
4. Include source type and source ID.
5. Include idempotency key fingerprint only, not secrets.
6. Include `event_version = 1`.

## 18. Testing Requirements

Create feature/unit tests under:

```text
tests/Feature/StoreCredit/StoreCreditLedgerFoundationTest.php
```

Required tests:

1. Credit and debit entries derive expected balance.
2. Ledger rows cannot be edited or deleted after posting.
3. Negative balance is blocked.
4. Balance can be rebuilt from ledger history.
5. Repeated rebuilds produce identical balances.
6. Ledger sequence ordering is deterministic per account.
7. Sequence numbers are isolated per account.
8. Ledger snapshots include schema version metadata.
9. Ledger rows persist `fingerprint_version`.
10. Idempotent replay returns original entry and does not duplicate rows.
11. Idempotency drift is rejected.
12. Currency mismatch is rejected.
13. Closed account movement is rejected.
14. Suspended account debit is rejected.
15. Source uniqueness blocks duplicate one-time postings when both source type and source ID are present.
16. Null source ID does not provide a source uniqueness guarantee.
17. Accounting liability payload includes `event_version`, ledger reference, source reference, amount, account currency, ledger currency, sequence, and schema version.
18. Accounting outbox rows are not persisted for foundation-only test postings.
19. External provider fields are not required or populated by Story 39.2.
20. No sale, payment, refund, redemption, loyalty, or inventory rows are created by ledger posting.
21. Tenant isolation is enforced for ledger entry lookup and posting.

Recommended test setup:

1. Use Story 39.1 factories for `Customer` and `CustomerFinancialAccount`.
2. Use integer centavos in all ledger service tests.
3. Use explicit tenant context in setup and clear it in teardown.
4. Test rollback behavior by forcing a failure after sequence allocation if practical.

## 19. Acceptance Criteria

1. `store_credit_ledger_entries` exists with tenant scoping, account ownership, integer centavos, direction, category, entry type, source snapshot, idempotency key, request fingerprint, fingerprint version, business date, posted timestamp, schema version, and sequence.
2. Ledger entries are append-only after posting.
3. Store credit balance is derived from ledger history and returns integer centavos.
4. Repeated balance rebuilds produce identical balances.
5. Debit posting cannot create a negative balance.
6. Ledger sequence is monotonic per customer financial account.
7. Idempotency replay does not create duplicate ledger entries.
8. Idempotency drift is rejected with a `409`-style domain conflict if exposed through HTTP.
9. Ledger source snapshots include `ledger_schema_version`.
10. Source snapshots are immutable evidence and are never rewritten after posting.
11. Dedicated `store_credit_ledger_sequences` allocation is used instead of `MAX(ledger_sequence)`.
12. Accounting liability payload contract is defined with `event_version`.
13. Accounting outbox rows are not persisted for foundation-only test postings.
14. External accounting provider delivery remains out of scope.
15. Story 39.2 does not implement refund issuance, redemption, loyalty, ledger endpoints, or mutable wallet balances.

## 20. Implementation Checklist

1. Create ledger migration.
2. Create sequence-state migration.
3. Create `StoreCreditLedgerEntry` model.
4. Add relationships from `CustomerFinancialAccount`.
5. Add ledger factory.
6. Create ledger sequence service.
7. Create ledger posting service.
8. Create derived balance service.
9. Create accounting liability payload builder.
10. Add domain exceptions.
11. Verify no ledger endpoint is exposed.
12. Add audit events.
13. Add tests.
14. Run focused tests.
15. Update story status after implementation review.

## 21. Developer Guardrails

1. Do not add balance columns to `customer_financial_accounts`.
2. Do not update or delete posted ledger rows.
3. Do not derive next sequence with `MAX(ledger_sequence)` under concurrency if a durable sequence row can be used.
4. Do not use decimal strings for ledger amounts.
5. Do not post debit entries that make the balance negative.
6. Do not create sale payments, refunds, reversals, inventory movements, loyalty points, or external accounting deliveries.
7. Do not bypass `CustomerFinancialAccount` as aggregate owner.
8. Do not use `Customer` directly as ledger owner.
9. Do not expose admin balance adjustment execution unless separately approved.
10. Do not make accounting outbox export success part of ledger posting success.
11. Do not persist accounting outbox rows for foundation-only postings in Story 39.2.
12. Do not expose read-only ledger inspection until Story 39.5 unless separately approved.

## 22. Resolved Review Decisions

1. Story 39.2 remains service/model/test-only and does not expose read-only ledger inspection.
2. Story 39.2 creates the accounting liability payload builder but does not persist accounting outbox rows for foundation-only test postings.
3. Account-scoped sequence allocation uses the dedicated `store_credit_ledger_sequences` table.
4. `store_credit_ledger_entries.branch_id` remains nullable; branch requirements for future accounting outbox rows are handled by the outbox-producing story.
