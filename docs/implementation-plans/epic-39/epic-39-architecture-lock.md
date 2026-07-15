# Epic 39 Store Credit and Loyalty Ledger Architecture Lock

## 1. Status

Approved Architecture Lock

Date: 2026-07-14

No product code is approved by this document yet. This plan is the architectural contract for Epic 39 implementation and should be used to create and review story implementation slices.

This document is approved as the governing architecture for Stories 39.1 through 39.8. Future stories may refine implementation details but must not violate the architectural constraints in Section 17 unless this document is formally revised.

## 2. Purpose

Epic 39 adds customer retention and credit-accounting capabilities on top of the existing IPOS sales, refund, payment, receipt, settlement, and accounting foundations.

The epic introduces:

1. Customer financial account foundation.
2. Append-only store credit ledger.
3. Store credit issuance from approved refund flows.
4. Store credit redemption through the existing POS payment flow.
5. Admin review and audit visibility.
6. Loyalty point accrual ledger.
7. Loyalty point redemption rules.
8. Liability, redemption, and reconciliation reporting.

## 3. Architecture Principles

1. Store credit is monetary value and must use integer centavos.
2. Loyalty points are non-money value and must never share a ledger with store credit.
3. Balances are derived from append-only ledger entries.
4. Ledger entries are immutable after posting.
5. Store credit issuance must be coordinated by the existing refund authority.
6. Store credit redemption must be coordinated by the existing payment authority.
7. Sales remain immutable.
8. Refunds and voids remain append-only reversals.
9. Offline store credit issuance, redemption, adjustment, and loyalty redemption are prohibited in the first release.
10. Accounting liability treatment must be explicit before store credit is redeemable.
11. Customer identity is tenant-scoped and must preserve audit history.
12. Store credit and loyalty services coordinate with Sales, Refunds, Payments, Settlement, and Accounting but do not replace those authorities.
13. Every financial or point mutation must be idempotent, auditable, and traceable to a source event, request, approval, or system rule.
14. A customer financial account is single-currency for monetary store credit.
15. Customer financial account ownership is immutable after creation unless a future approved merge process posts explicit transfer evidence.

## 4. Current Baseline

Relevant existing foundations:

1. Sales are created through existing POS sale creation and payment recording services.
2. Split payments already exist through `/pos/sales/{sale_id}/payments/split`.
3. Refunds and voids are handled through existing append-only reversal services.
4. Shift, drawer, Z-read, settlement, receipt, and accounting flows already consume sale, payment, void, and refund records.
5. Epic 37 promotions explicitly deferred loyalty and coupon redemption.
6. Epic 38 preserved checkout authority boundaries and prohibited parallel sales engines.

Implication:

Epic 39 must not create a parallel sale, payment, refund, or accounting engine. It must add customer credit and loyalty capabilities through controlled integration points.

## 5. Domain Boundary

Epic 39 owns:

1. Customer financial accounts.
2. Store credit ledger entries.
3. Loyalty point ledger entries.
4. Derived customer balance views.
5. Store credit liability read models.
6. Store credit and loyalty audit/read models.
7. Store credit issuance and redemption snapshots.
8. Loyalty accrual and redemption snapshots.

Epic 39 does not own:

1. Sale creation.
2. Payment recording.
3. Refund posting.
4. Inventory effects.
5. Receipt numbering.
6. Z-read finalization.
7. Settlement locking.
8. Accounting sync transport.

Implication:

Epic 39 may prepare customer-credit and loyalty snapshots, but the existing owning services must remain responsible for their domains:

```text
Refund workflow
        |
        v
Store credit issuance service
        |
        v
Append-only store credit ledger
```

```text
Payment workflow
        |
        v
Store credit redemption service
        |
        v
PaymentRecordingService + append-only debit ledger
```

```text
Finalized sale event
        |
        v
Loyalty accrual service
        |
        v
Append-only loyalty ledger
```

## 6. Architecture Decisions for Review

### 6.1 Customer Financial Account

Each tenant customer that can receive store credit or loyalty points should have a customer financial account record.

The account groups ledgers but does not store authoritative mutable balances.

The account is the aggregate root for customer value movement. It owns:

1. Account identity.
2. Account lifecycle status.
3. Store credit ledger references.
4. Loyalty ledger references.
5. Derived balance projections.
6. Customer identity linkage.

The account does not own sales, refunds, payment rows, receipt records, or external accounting postings.

Recommended account states:

```text
active
suspended
closed
```

Rules:

1. `active` accounts may receive allowed ledger movements.
2. `suspended` accounts may be viewed but may not redeem value.
3. `closed` accounts may not receive new customer-initiated movements.
4. `suspended` accounts may still receive refund credits, admin corrections, accounting reversals, and other recovery postings approved by service policy.
5. Closing or anonymizing a customer must preserve ledger evidence and source references.
6. Account ownership is immutable. A financial account assigned to Customer A must not be reassigned to Customer B by updating the owner field.
7. Customer merge behavior must be explicitly approved before implementation; no story may silently merge ledgers.
8. Future merge behavior must close or transfer through explicit approved ledger evidence rather than rewriting account ownership.
9. One customer financial account operates in exactly one monetary currency for store credit.

### 6.2 Append-Only Ledger

Store credit and loyalty movements must be ledger entries:

1. Credit entries increase derived balance.
2. Debit entries decrease derived balance.
3. Reversal entries offset prior entries.
4. Adjustment entries require authorization and reason.

Ledger rows are never edited or deleted after posting.

Each ledger row must include:

1. Tenant ID.
2. Branch ID where applicable.
3. Customer financial account ID.
4. Entry type.
5. Direction.
6. Amount in centavos for store credit, or integer points for loyalty.
7. Source type and source ID.
8. Source snapshot.
9. Actor ID or system actor.
10. Approval reference where required.
11. Idempotency key or source uniqueness key.
12. Business date.
13. Posted timestamp.
14. Account-scoped monotonic ledger sequence.
15. Ledger category.
16. Ledger schema version.

Ledger categories:

```text
credit
debit
adjustment
reversal
expiration
```

Ledger sequence rules:

1. `ledger_sequence` is monotonic per customer financial account and ledger type.
2. Sequence assignment must occur inside the same transaction as ledger posting.
3. Sequence numbers provide deterministic replay and statement ordering when timestamps collide.
4. Sequence gaps are acceptable only if the implementation uses database sequences or another durable allocator that may reserve numbers during failed transactions; gaps must not imply missing value movement.

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

Loyalty entry types:

```text
sale_accrual
refund_reversal
void_reversal
redemption_debit
admin_points_credit
admin_points_debit
expiration_debit
```

Expiration and forfeiture are defined as ledger concepts but remain out of scope for automated execution unless a later story explicitly approves them.

### 6.3 Derived Balance

Balance is calculated from ledger entries.

Cached balance snapshots may be introduced for performance, but the ledger remains authoritative and snapshots must be rebuildable.

Derived balance rules:

1. Store credit balance equals posted credits minus posted debits.
2. Loyalty point balance equals posted point credits minus posted point debits.
3. A mutation must fail if it would create a negative balance unless this architecture is revised.
4. Cached balance rows are projections only.
5. Projection rebuild must be deterministic from ledger rows.
6. Balance display must indicate whether it is ledger-authoritative or cache-derived if stale reads are ever exposed.

### 6.4 Refund Boundary

Store credit issuance from refunds must be initiated through the existing refund workflow.

Epic 39 must not introduce a standalone "issue credit for sale" path that bypasses refund eligibility, supervisor authorization, reversal audit, or closed-shift rules.

Refund-to-credit handoff:

```text
RefundService
        |
        v
StoreCreditIssuanceRequest
        |
        v
StoreCreditLedgerService
```

The store credit ledger may record the value movement only after refund authority validation succeeds.

Required issuance contract:

1. Source sale ID.
2. Source refund ID or reversal reference.
3. Customer financial account ID.
4. Refund amount in centavos.
5. Refund reason and business date.
6. Actor and approval reference.
7. Idempotency key.
8. Source refund snapshot.

If refund posting fails, no store credit ledger entry may be committed.

If store credit ledger posting fails, the refund-to-credit operation must roll back as a unit unless the story defines an explicit recovery queue approved by this architecture.

### 6.5 Payment Boundary

Store credit redemption must enter the existing payment flow as a controlled tender type.

Epic 39 must not create sale payments directly outside the existing payment recording authority.

Redemption handoff:

```text
PaymentController / payment workflow
        |
        v
StoreCreditRedemptionRequest
        |
        v
PaymentRecordingService
        |
        v
StoreCreditLedgerService
```

Required redemption contract:

1. Sale ID.
2. Customer financial account ID.
3. Redemption amount in centavos.
4. Payment method or tender code.
5. Terminal, cashier, shift, and branch context.
6. Idempotency key.
7. Authorized available balance snapshot at authorization time.
8. Payment recording result reference.

The store credit debit ledger entry may be committed only for a successful payment recording. A failed payment must not debit the account.

If a store credit debit is authorized but payment recording fails, the mutation must roll back or produce an explicit reversal entry in the same recovery contract. Silent partial settlement is prohibited.

### 6.6 Money vs Loyalty

Store credit and loyalty points must remain separate:

1. Store credit uses centavos and creates financial liability.
2. Loyalty points use points and do not equal cash unless redeemed through explicit rules.
3. Loyalty points must not be accepted as store credit without an approved redemption conversion rule.

Loyalty redemption can reduce value only through an explicit rule boundary:

```text
Loyalty points
        |
        v
Approved redemption rule
        |
        v
Discount or tender integration boundary
```

The first release should prefer discount-style redemption unless the payment boundary is explicitly extended to support point tender. Either path must preserve sale totals, statutory discount behavior, tax treatment, and reporting semantics.

### 6.7 Accounting Liability

Store credit issued to customers represents a liability until redeemed, expired, forfeited, or reversed.

Implementation must define accounting outbox behavior before store credit issuance is enabled in production.

Required accounting events:

1. Store credit issued.
2. Store credit redeemed.
3. Store credit reversed.
4. Store credit adjusted.
5. Store credit expired or forfeited if implemented.

Accounting integration must be append-only and replayable:

1. Ledger posting creates a durable accounting event or outbox row.
2. Accounting export reads from the outbox/read model.
3. Export failures do not mutate the ledger.
4. Retries are idempotent.
5. Reconciliation reports can tie accounting rows back to ledger entries.
6. Accounting events include `event_version` from the first implementation.

### 6.8 Offline Policy

Offline store credit issuance and redemption are prohibited in the first release.

Offline terminals may display cached customer credit context only if clearly marked stale/read-only.

Offline restrictions:

1. No offline store credit issuance.
2. No offline store credit redemption.
3. No offline store credit admin adjustment.
4. No offline loyalty redemption.
5. No offline customer merge or account closure.
6. No offline balance cache mutation.

Offline sale capture may still occur through existing approved offline-sale flows, but those sales must not accrue redeemable loyalty points until server reconciliation validates eligibility.

### 6.9 Ledger Snapshots

Ledger entries must preserve enough snapshot data to reconstruct why value moved:

1. Source sale/refund/payment reference.
2. Customer account reference.
3. Amount or points.
4. Actor and approval reference.
5. Reason code.
6. Business date.
7. Expiration metadata where applicable.

Snapshot rules:

1. Snapshots are historical evidence, not recalculation inputs.
2. Posting must not depend on mutable product, customer, payment, promotion, or tax records after the snapshot is captured.
3. Redemptions must preserve the balance and authorization context used at the time of approval.
4. Refund issuance must preserve the refund calculation and approval context used at the time of posting.
5. Loyalty accrual must preserve the earning rule version.
6. Snapshots must include `ledger_schema_version` so future migrations can interpret historical payloads safely.

### 6.10 Customer Identity

Customer financial identity must be tenant-scoped and auditable.

Merging, deleting, or anonymizing customers must not destroy ledger history or financial evidence.

Identity rules:

1. Financial account identity is tenant-scoped.
2. A customer may have at most one active financial account per tenant unless a later architecture revision approves multi-account behavior.
3. Cross-tenant account access must return not found.
4. Branch-scoped operations may only use customer accounts visible to the tenant and allowed branch context.
5. Customer deletion must be soft or referentially safe where financial history exists.
6. Anonymization may remove personal fields but must retain stable non-personal ledger references.

### 6.11 Idempotency

All ledger-producing commands must be idempotent.

Required behavior:

1. First request posts the ledger entry and returns the result.
2. Exact replay returns the original result without creating another ledger entry.
3. Same idempotency key with changed material fields is rejected as drift.
4. Material field fingerprints must exclude transport metadata such as request timestamps and headers.
5. Source uniqueness must also protect known one-time movements, such as one store credit issuance for one refund reference.

Material fields must include tenant, branch, customer financial account, source reference, amount or points, direction, business date, and rule/version identifiers where applicable.

### 6.12 Transaction Boundaries

Financial mutations must commit as atomic units across the owning source action and the Epic 39 ledger action.

Refund issuance transaction:

```text
Validate refund authority
        |
        v
Create refund/reversal evidence
        |
        v
Post store credit credit ledger entry
        |
        v
Create accounting outbox event
        |
        v
Commit
```

Redemption transaction:

```text
Validate payment authority
        |
        v
Validate store credit balance
        |
        v
Record payment through payment authority
        |
        v
Post store credit debit ledger entry
        |
        v
Create accounting outbox event
        |
        v
Commit
```

Loyalty accrual transaction:

```text
Observe finalized eligible sale
        |
        v
Evaluate accrual rule version
        |
        v
Post loyalty credit ledger entry
        |
        v
Commit
```

If any step fails before commit, no partial value movement may remain.

### 6.13 Authorization and Verification

The following operations require explicit permission checks:

1. Store credit issuance through refund.
2. Store credit redemption.
3. Store credit admin adjustment.
4. Loyalty redemption.
5. Loyalty admin adjustment.
6. Account suspension, closure, or reactivation.
7. Customer-account merge if introduced later.

The following operations may require customer verification depending on story-level policy:

1. Store credit redemption.
2. Loyalty redemption.
3. Account lookup by phone, email, or name.

Customer verification evidence must be auditable without storing unnecessary sensitive data.

### 6.14 Read Models and Reporting

Epic 39 may maintain read models for:

1. Customer account summary.
2. Store credit balance.
3. Loyalty point balance.
4. Liability reporting.
5. Redemption reporting.
6. Expiring value views if expiration is approved later.

Read models are projections only. They must not become mutation sources.

Reports must derive from ledger entries or deterministic projections and must not mutate ledgers, sales, payments, refunds, or accounting events.

Statements are read models built from ledger rows. A customer statement must never become a separate financial source of truth.

Report classes:

1. Operational reports answer customer service questions such as current customer balance, ledger history, source transaction links, and account status.
2. Financial reports answer accounting questions such as issued value, redeemed value, reversed value, adjustments, expired or forfeited value, and outstanding liability.
3. Operational and financial reports may share projections but must keep their semantics and approval audiences distinct.

## 7. Out of Scope

1. Gift cards.
2. Third-party loyalty provider integration.
3. Coupon codes.
4. Customer mobile app wallet.
5. QR customer redemption.
6. Offline redemption.
7. Marketing segmentation engine.
8. Automatic cashback campaigns.
9. Expiration/forfeiture automation unless explicitly approved in a later story.
10. Customer merge implementation.
11. Customer self-service portal.
12. Third-party accounting transport changes.
13. Manual balance overwrite tools.
14. Negative store credit or negative loyalty balance.

## 8. Target Data Model

Story specifications may adjust exact table names, but the architectural model is:

### 8.1 `customer_financial_accounts`

Purpose:

Tenant-scoped aggregate root that links a customer identity to store credit and loyalty ledgers.

Core fields:

1. `id`
2. `tenant_id`
3. `customer_id`
4. `status`
5. `currency_code`
6. `opened_at`
7. `suspended_at`
8. `closed_at`
9. `created_by`
10. `updated_by`
11. timestamps

Constraints:

1. Unique active account per tenant/customer.
2. Cross-tenant customer links are prohibited.
3. Account deletion is prohibited when ledger rows exist.
4. Currency is immutable after the first monetary store credit ledger entry.
5. Customer ownership is immutable after creation unless a future approved merge process creates explicit transfer evidence.

### 8.2 `store_credit_ledger_entries`

Purpose:

Immutable monetary value movement ledger.

Core fields:

1. `id`
2. `tenant_id`
3. `branch_id`
4. `customer_financial_account_id`
5. `entry_type`
6. `direction`
7. `amount_centavos`
8. `currency_code`
9. `ledger_category`
10. `ledger_sequence`
11. `ledger_schema_version`
12. `source_type`
13. `source_id`
14. `source_snapshot`
15. `idempotency_key`
16. `request_fingerprint`
17. `business_date`
18. `posted_by`
19. `posted_at`
20. timestamps

Constraints:

1. `amount_centavos` is a positive integer.
2. Direction controls balance sign.
3. Rows are immutable after insert.
4. Idempotency key is unique per tenant/account/command scope.
5. Source uniqueness blocks duplicate one-time postings.
6. Ledger sequence is monotonic per account.
7. Ledger schema version is present on every row or snapshot payload.

### 8.3 `loyalty_ledger_entries`

Purpose:

Immutable point movement ledger.

Core fields mirror store credit ledger entries, except value is stored as `points` instead of centavos.

Constraints:

1. `points` is a positive integer.
2. Points are not currency.
3. Points cannot be posted to the store credit ledger.
4. Rows are immutable after insert.

### 8.4 Balance Projections

Purpose:

Fast customer/account lookup for POS and admin surfaces.

Projection examples:

1. `customer_account_balance_snapshots`
2. `customer_account_summary_view`
3. `store_credit_liability_summary`

Constraints:

1. Rebuildable from ledger rows.
2. Not authoritative for posting.
3. Must include projection generation timestamp/version if persisted.

### 8.5 Accounting Outbox

Purpose:

Durable events for liability and redemption reconciliation.

Constraints:

1. Written in the same transaction as monetary ledger postings.
2. Idempotent export.
3. Replayable without duplicating downstream accounting entries.
4. Does not mutate the source ledger.

## 9. Service Ownership

Recommended service boundaries:

1. `CustomerFinancialAccountService` owns account lifecycle.
2. `StoreCreditLedgerService` owns store credit ledger posting and derived balance calculation.
3. `StoreCreditRefundIssuer` coordinates refund-to-credit issuance after refund authority approval.
4. `StoreCreditPaymentCoordinator` coordinates redemption with payment recording.
5. `LoyaltyLedgerService` owns point ledger posting and derived point balance.
6. `LoyaltyAccrualService` evaluates sale-paid accrual events.
7. `LoyaltyRedemptionService` validates redemption rules and posts point debits.
8. Reporting services own read-only query projections.

Controllers must stay thin and must not write ledger rows directly.

## 10. State Machines

### 10.1 Customer Financial Account Status

```text
active -> suspended
active -> closed
suspended -> active
suspended -> closed
```

Illegal transitions:

```text
closed -> active
closed -> suspended
```

### 10.2 Store Credit Ledger Lifecycle

Ledger entries are posted once:

```text
requested -> posted
requested -> rejected
```

There is no update path from `posted`.

Corrections use reversal entries:

```text
posted original entry
        |
        v
posted reversal entry
```

### 10.3 Loyalty Ledger Lifecycle

Loyalty ledger entries follow the same append-only posting model as store credit.

## 11. Error and Conflict Responses

Story-level APIs should use consistent domain responses:

| Condition | HTTP status |
| --- | ---: |
| Successful create | `201` |
| Successful lookup/update command | `200` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant hidden resource | `404` |
| Idempotency drift | `409` |
| Insufficient store credit balance | `409` |
| Insufficient loyalty balance | `409` |
| Closed/suspended account mutation | `409` |
| Offline mutation rejected | `409` |
| Duplicate source posting | `409` |

Recommended idempotency conflict payload:

```json
{
  "code": "IDEMPOTENCY_DRIFT",
  "message": "The idempotency key was already used with different request details."
}
```

Recommended balance conflict payload:

```json
{
  "code": "INSUFFICIENT_STORE_CREDIT_BALANCE",
  "message": "The customer does not have enough available store credit for this redemption.",
  "available_centavos": 12500
}
```

## 12. Audit, Timeline, and Evidence

Epic 39 must preserve formal audit evidence for:

1. Account creation, suspension, reactivation, and closure.
2. Store credit issuance.
3. Store credit redemption.
4. Store credit reversal and adjustment.
5. Loyalty accrual.
6. Loyalty redemption.
7. Loyalty reversal and adjustment.
8. Customer verification events where required.

Audit does not replace ledger rows.

Ledger rows do not replace audit events.

Accounting outbox events do not replace either audit or ledger rows.

## 13. Security and Privacy

1. Customer lookup must avoid broad cross-tenant or cross-branch leakage.
2. Store credit balance visibility requires permission appropriate to POS or admin context.
3. Loyalty balance visibility requires permission appropriate to POS or admin context.
4. Customer verification evidence must avoid storing full sensitive identifiers when a masked value or verification reference is sufficient.
5. CSV exports must prevent formula injection.
6. Audit trails must identify actor, terminal, branch, and business date where applicable.

## 14. Recovery and Reconciliation

Recovery rules:

1. Ledger rows are never repaired by editing existing rows.
2. Incorrect postings are corrected by authorized reversal entries.
3. Balance projections can be rebuilt from ledger rows.
4. Accounting outbox rows can be replayed idempotently.
5. Reports must expose unreconciled accounting events if export fails.
6. Duplicate source postings must be detectable by unique source keys.

Operational reconciliation must answer:

1. Which refund issued this store credit?
2. Which payment redeemed this store credit?
3. Which sale earned these points?
4. Which refund or void reversed these points?
5. Which ledger rows are included in the current liability balance?
6. Which accounting events remain pending or failed?

## 15. Implementation Story Order

Implementation should follow:

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Store Credit Ledger.
3. Story 39.3 Store Credit Refund Issuance.
4. Story 39.4 Store Credit Redemption.
5. Story 39.5 Store Credit Admin Review.
6. Story 39.6 Loyalty Ledger.
7. Story 39.7 Loyalty Redemption.
8. Story 39.8 Reporting and Reconciliation.

This order keeps customer identity and ledger rules stable before refund, payment, loyalty redemption, and reporting integrations are implemented.

## 16. Approval Gates

Before implementation begins:

1. Architecture Lock is approved.
2. Implementation Guide is reviewed against this lock.
3. Story 39.1 is written and approved.

Before store credit issuance is enabled:

1. Ledger immutability is tested.
2. Derived balance rebuild is tested.
3. Refund boundary is tested.
4. Accounting outbox behavior is defined and tested.

Before store credit redemption is enabled:

1. Payment boundary is tested.
2. Insufficient-balance conflicts are tested.
3. Idempotency replay and drift are tested.
4. Receipt/reporting behavior is reviewed.

Before loyalty redemption is enabled:

1. Loyalty rule versioning is tested.
2. Point balance derivation is tested.
3. Redemption reversal behavior is tested.

## 17. Architecture Constraints

The following constraints may not be violated by future stories unless this document is formally revised:

1. Store credit balances are derived, not manually mutated.
2. Store credit and loyalty points use separate ledgers.
3. Ledger entries are append-only.
4. Refund issuance must use the existing refund boundary.
5. Redemption must use the existing payment boundary.
6. Sales remain immutable.
7. Offline store credit and loyalty mutations remain prohibited in the first release.
8. Accounting liability is not optional for monetary store credit.
9. Customer identity changes must preserve ledger history.
10. Admin adjustments require authorization, reason, and audit evidence.
11. Controllers, UI components, seeders, and tests must not write ledger rows directly outside approved services.
12. No story may introduce mutable wallet balance columns as authoritative state.
13. Idempotency replay must never create duplicate ledger movement.
14. Idempotency drift must be rejected.
15. Store credit redemption must not debit an account unless payment recording succeeds.
16. Refund-to-credit issuance must not post ledger credit unless refund authority validation succeeds.
17. Loyalty points must not alter sale totals unless an approved redemption rule applies.
18. Loyalty points must not become store credit without a formal architecture revision.
19. Ledger snapshots are historical evidence and must not be recalculated from mutable source records.
20. Balance projections and reports are read models only and must not become mutation authorities.
21. Customer merge, gift cards, automatic expiration, third-party loyalty integration, and customer wallet self-service remain out of scope until separately approved.
22. Store credit account currency is immutable after monetary ledger activity begins.
23. Customer financial account ownership must not be reassigned by direct update.
24. Ledger entries must carry deterministic account-scoped ordering through sequence numbers or an approved durable allocator.
25. Ledger snapshots and accounting events must be versioned from the first implementation.
26. Customer statements are read models only and must not become a financial source of truth.
