# Story 41.6 Inventory, Loyalty, and Cross-Domain Consequence Validation

## Status

Implemented - Local Verification Complete

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Ensure an accepted offline sale synchronization produces every required server-side downstream consequence exactly once, especially inventory deduction, recipe deduction, negative-stock evidence, loyalty accrual, store-credit exclusion, reporting evidence, and cashier/customer messaging.

Story 41.6 is not a new inventory or loyalty feature. It is the cross-domain validation story that proves the offline sync path honors the server-authoritative consequences already established by Epic 39, Epic 40, and Stories 41.3 through 41.5.

## Dependencies

Requires:

1. Story 41.1 offline architecture and policy lock.
2. Story 41.2 durable offline transaction queue and immutable local envelope.
3. Story 41.3 server synchronization, idempotency, and transaction atomicity.
4. Story 41.4 conflict, drift, ordering, and review-required handling.
5. Story 41.5 offline payment, shift, discount, and receipt restrictions.
6. Epic 39 loyalty runtime:
   - customer financial account foundation,
   - loyalty ledger,
   - loyalty accrual,
   - loyalty redemption online-only boundary,
   - loyalty reversal behavior.
7. Epic 40 inventory evidence:
   - movement ledger hardening,
   - unit conversion snapshots,
   - negative-stock exception lifecycle,
   - recipe deduction snapshot integrity,
   - inventory reporting evidence.

## Complexity

Very Large

## Benchmark Direction

Primary architectural benchmark:

```text
Mosaic-style API-first lifecycle and consequence orchestration
```

Secondary operational benchmark:

```text
StoreHub-style BackOffice visibility for offline sales, inventory automation,
loyalty, store credit, and centralized reporting expectations
```

Secondary cashier-simplicity benchmark:

```text
UTAK-style Filipino SMB cashier simplicity and plain sales/inventory/reporting messaging
```

Recommended IPOS implementation style:

```text
Mosaic-style API-first consequence lifecycle
+
IPOS-owned transactional inventory consequence service
+
IPOS-owned durable loyalty consequence outbox
+
dedicated consequence-attempt records
+
Epic 39 and Epic 40 services as sole domain authorities
+
Story 41.4 review classification
```

Provider benchmarks are architectural and operational references only. Do not add a runtime dependency on Mosaic, StoreHub, or UTAK.

## Architecture Constraints

Story 41.6 must preserve these locked decisions:

1. Browser-local offline capture never posts inventory, loyalty, store credit, official receipts, accounting, or fiscal records.
2. `SaleCreationService` remains the sole authority for creating committed sales and sale items.
3. Inventory effects are produced only by server inventory services.
4. Loyalty effects are produced only by the Epic 39 loyalty runtime.
5. Store credit mutation remains online-only and ledger-authoritative.
6. Top-level sync status remains generic: `accepted`, `replayed`, `retryable_failed`, `review_required`, or `rejected`.
7. Consequence-specific pending or failed states belong in `consequence_status`, not new top-level sync statuses.
8. Exact replay must not duplicate sale, payment, inventory movement, negative-stock exception, loyalty ledger, store-credit, accounting, or receipt consequences.
9. Drift must fail closed before mutation.
10. Accepted sync must not silently skip required server consequences.
11. Cash-collected records that cannot safely complete required consequences enter review or retry according to policy.
12. Cached stock is never authoritative while offline and is never locally deducted as committed stock.
13. No UI may promise inventory posting, loyalty accrual, official receipt completion, or central reporting until server acceptance.
14. Inventory deduction for first-release offline accepted sales is synchronous and transaction-bound.
15. Loyalty accrual for first-release offline accepted sales is asynchronous only through a transactionally durable outbox or consequence-attempt record.
16. `acceptance_consequence_snapshot` is immutable evidence captured at acceptance.
17. `current_consequence_status` is a mutable projection rebuilt from domain records and consequence-attempt records.
18. Strict-stock or inventory-configuration failure rolls back the posting transaction before review or rejection state is recorded.
19. Loyalty effective business date uses the server-resolved sale business date, not worker execution time.
20. Store credit remains `not_applicable` only after prohibited-payload validation passes.

## Scope

In scope:

1. Offline sync inventory consequence validation.
2. Synchronous, transaction-bound inventory consequence handling for offline accepted sales.
3. Recipe deduction validation through existing recipe services.
4. Negative-stock strict and soft policy behavior during sync.
5. Inventory movement idempotency on replay.
6. Loyalty accrual eligibility and dispatch/recording behavior for offline accepted sales.
7. Customer identity validation for loyalty accrual.
8. Store credit redemption and mutation prohibition in offline envelopes.
9. Consequence status snapshot normalization.
10. Business-date and delayed-sync evidence for reporting.
11. Cached-stock UI labeling and no-local-deduction guardrails.
12. Tests proving no partial or duplicate cross-domain effects.

Out of scope:

1. New inventory rules.
2. New loyalty earning or redemption rules.
3. Local inventory mutation.
4. Local loyalty ledger mutation.
5. Store credit offline redemption.
6. Offline customer account creation.
7. Offline void or refund.
8. Full support-resolution execution for review-required records.
9. New reporting dashboards beyond evidence needed to validate offline consequences.

## Current Implementation Context

Relevant existing code:

1. `app/Services/POS/OfflineSync/OfflineEnvelopeSynchronizationService.php`
   - Creates the sale through `SaleCreationService`.
   - Records cash payment evidence.
   - Records accounting outbox evidence.
   - Dispatches `ProcessSaleInventoryDeductionJob` after commit.
   - Currently marks inventory as `queued`.
   - Currently marks loyalty as `queued` only when `sale.customer_financial_account_id` is present.
   - Does not currently dispatch `AccrueLoyaltyForSaleJob` in the offline sync path.

2. `app/Jobs/Inventory/ProcessSaleInventoryDeductionJob.php`
   - Loads sale without global scopes.
   - Restores tenant and branch context.
   - Calls `InventoryService::deductFromSale($sale)`.

3. `app/Services/InventoryService.php`
   - Deducts inventory from paid sale items.
   - Handles direct tracked-product deduction.
   - Delegates recipe deduction to `RecipeDeductionService`.
   - Uses `source_effect_key` for sale-deduction idempotency.
   - Blocks strict negative stock.
   - Creates negative-stock exception evidence under soft-negative policy.

4. `app/Jobs/Loyalty/AccrueLoyaltyForSaleJob.php`
   - Calls `LoyaltyAccrualService::accrueFromSalePaid(...)`.

5. `app/Services/Loyalty/LoyaltyAccrualService.php`
   - Skips training sales and sales without customer financial account.
   - Validates account tenant and active state.
   - Uses active earning rule.
   - Posts append-only loyalty ledger entries with idempotency key:

```text
sale-accrual:{sale_id}:{account_id}:{rule_id}:{rule_version}
```

6. `app/Services/POS/PaymentRecordingService.php`
   - Normal online paid-sale path dispatches both inventory deduction and loyalty accrual after commit.
   - Story 41.6 must align offline accepted-sale consequences with this online path where policy permits.

7. `app/Models/OfflineSalesImport.php`
   - Already stores:
     - `consequence_status_snapshot`,
     - `acceptance_consequence_snapshot`,
     - `current_consequence_status`,
     - `resolved_business_date`,
     - `business_date_status`,
     - review and rejection fields.

8. `resources/js/POS/offline/offlineSalesQueue.ts`
   - Canonical offline sale envelope store.
   - Must remain the only offline sale capture path.

9. `resources/js/Pages/POS/Index.jsx`
   - Builds offline cash-sale envelopes.
   - Must not display cached stock as authoritative or promise loyalty accrual before sync acceptance.

## Architectural Gap Being Closed

Stories 41.3 through 41.5 made offline sync deterministic and policy-safe, but the current implementation still leaves downstream consequences at a high-level `queued` state.

Story 41.6 must make consequence behavior explicit:

```text
offline envelope accepted
        |
        |-- sale committed
        |-- payment committed
        |-- inventory committed
        |-- negative-stock exception committed when policy requires it
        |-- accounting outbox queued or committed with durable evidence
        |-- loyalty committed, queued through durable attempt, skipped, or review-required under policy
        |-- store credit not_applicable unless prohibited payload detected
        |-- business date committed
```

No accepted record may imply that inventory, loyalty, or store credit succeeded when the required consequence was skipped, failed silently, or duplicated.

## Consequence Status Contract

Story 41.6 must normalize consequence status values.

Allowed consequence statuses:

```text
committed
queued
pending
not_applicable
skipped_by_policy
review_required
retryable_failed
failed
```

Status semantics:

```text
pending
Durable intent exists but is not yet available for worker claim.

queued
Durable intent is available for worker claim.

retryable_failed
Execution failed because of a transient condition and may be retried.

failed
Automated recovery has been exhausted or is no longer allowed.

review_required
Support or manager review is required before the consequence can be resolved.
```

Recommended consequence status keys:

```text
sale
payment
inventory
variance
loyalty
store_credit
receipt
accounting_outbox
business_date
```

Every consequence status entry must carry enough metadata to distinguish required from optional work:

```text
status
required
execution_mode
updated_at
reason_code
attempt_count
last_error_code
result_reference
```

Allowed `required` values:

```text
true
false
conditional
```

Allowed `execution_mode` values:

```text
synchronous
durable_async
policy_only
```

Rules:

1. `sync_status = accepted` is allowed only when required synchronous consequences are committed or allowed asynchronous consequences have durable evidence.
2. Inventory may not be `queued` in the first release. Tracked-product and recipe inventory consequences are mandatory synchronous consequences.
3. Strict-stock failures after cash collection must not become `accepted`.
4. Loyalty may be `queued` only when a durable job or outbox/event evidence exists.
5. Loyalty may be `skipped_by_policy` when no eligible customer financial account exists and the cashier/customer UI did not promise points.
6. Loyalty must not be represented by a new top-level status such as `accepted_with_pending_loyalty`.
7. Store credit must be `not_applicable` for valid first-release offline cash sales.
8. If an offline envelope includes store-credit redemption or mutation evidence, the record must be rejected or moved to review before sale creation according to cash exposure.
9. Receipt status must be `pending` or `queued` for an accepted ordinary sale when official document completion is still outstanding. Do not use `not_applicable` for an accepted sale that requires an official document.
10. `business_date` must be `committed` on accepted sync.

Consequence classification:

| Consequence | Required? | Mode | Accepted status allowed |
| --- | --- | --- | --- |
| Sale | Yes | Synchronous | `committed` |
| Payment | Yes | Synchronous | `committed` |
| Inventory for tracked/recipe items | Conditional | Synchronous | `committed` |
| Inventory for non-tracked items | No mutation | Policy | `not_applicable` |
| Soft-negative variance evidence | Conditional | Synchronous | `committed` |
| Strict-negative failure | Blocking | Synchronous | Never accepted |
| Loyalty for eligible account | Conditional | Durable async | `queued` or `committed` |
| Loyalty without account | No | Policy | `not_applicable` |
| Loyalty invalid, not promised | No | Policy | `skipped_by_policy` |
| Loyalty invalid, expected | Blocking/review | Policy | Never silently accepted |
| Store credit | Prohibited offline | Policy | `not_applicable` only after validation |
| Receipt | Yes for ordinary sale | Durable async/server | `pending` or `queued` |
| Accounting outbox | Yes | Durable async | `queued` or `committed` |
| Business date | Yes | Synchronous resolution | `committed` |

## Inventory Consequence Policy

Inventory remains server-authoritative.

Story 41.6 must validate these paths:

1. Direct tracked product deduction.
2. Recipe ingredient deduction.
3. Unit conversion snapshot preservation in recipe deduction.
4. Strict negative-stock block.
5. Soft negative-stock movement plus exception evidence.
6. Idempotent replay with no duplicate movements or exceptions.

Recommended implementation behavior:

```text
BEGIN TRANSACTION

validate envelope
create sale through SaleCreationService
record cash payment
deduct direct inventory
deduct recipe ingredients
create soft-negative exception when required
verify inventory evidence
record accounting outbox
record loyalty consequence attempt when applicable
record acceptance consequence snapshot

COMMIT
```

For Story 41.6 first release, inventory deduction is a mandatory synchronous consequence inside the accepted-sale transaction. A future architecture revision may introduce durable inventory orchestration only with reservation or compensation semantics that preserve strict-stock policy. A plain queued deduction is not acceptable.

Strict-stock policy:

```text
If inventory deduction fails because strict stock would go negative,
and cash_status indicates cash was collected or exposure is unknown,
the offline import must become review_required.
```

Soft-negative policy:

```text
If branch policy allows negative stock with warning,
the accepted sync must create:
1. inventory movement,
2. negative-stock exception evidence,
3. consequence_status.inventory = committed,
4. consequence_status.variance = committed.
```

Missing inventory setup:

```text
Missing branch inventory or missing recipe ingredient setup is configuration failure.
For collected cash records, route to review_required.
For pre-cash records, reject safely.
```

Posting rollback and review persistence:

```text
1. Start posting transaction.
2. Attempt sale, payment, and inventory.
3. Inventory raises typed blocking exception.
4. Roll back the entire posting transaction.
5. In a separate import-state transaction:
   - preserve attempted consequence diagnostics;
   - mark import review_required or rejected;
   - record reason;
   - confirm no sale/payment/inventory consequence exists.
```

Inventory evidence verification:

```text
tracked item
    -> expected movement/effect key exists

recipe item
    -> expected ingredient movements or explicit no-deduction policy exists

soft-negative result
    -> movement and exception evidence both exist
```

`InventoryService::deductFromSale()` returning without exception is not sufficient by itself. The offline adapter must verify expected evidence after deduction.

Expected effect plan:

Before calling the inventory services, the offline adapter must construct a lightweight expected-effect plan without recalculating inventory quantities outside the existing domain services:

```text
sale_item_id
expected_source_effect_key
inventory_item_id
expected_quantity
expected_unit_snapshot
expected_recipe_component_reference
expected_variance_requirement
```

After deduction, committed movements, recipe ingredient movements, and soft-negative exception records must be matched against this plan. Checking only that some movement exists is not enough for multi-item or recipe sales.

Deadlock and lock-timeout handling:

```text
deadlock or lock timeout
    -> retry the entire posting transaction with the same import and idempotency identity
    -> do not classify as strict-stock failure
    -> do not create review state unless retry policy is exhausted
```

## Loyalty Consequence Policy

Loyalty remains server-authoritative and append-only.

Offline terminal behavior:

1. No local points balance mutation.
2. No offline loyalty redemption.
3. No loyalty points promise before server acceptance.
4. Customer-facing text may say points will be evaluated after sync, not awarded.

Sync behavior:

1. If the sale has no customer financial account, `loyalty = not_applicable` or `skipped_by_policy`.
2. If the sale has an active same-tenant customer financial account, the approved loyalty runtime must create or queue accrual exactly once.
3. If the account is inactive, merged, missing, or cross-tenant:
   - accept sale without loyalty only if no points were promised and policy permits;
   - otherwise route to `review_required`.
4. Replayed sync must not duplicate loyalty ledger entries.
5. Loyalty accrual must use server-resolved sale identity and business-date evidence.

Recommended implementation:

```text
Sale accepted
    |
    |-- if customer account eligible:
        |-- create durable LOYALTY_ACCRUAL_REQUESTED attempt/outbox record
        |-- worker calls AccrueLoyaltyForSaleJob or LoyaltyAccrualService
    |
    |-- update consequence_status.loyalty
```

Loyalty accrual remains asynchronous in the first release, but the accrual intent must be committed transactionally with the accepted sale.

Durable dispatch requirement:

```text
accepted-sale transaction commits
        |
        |-- durable consequence attempt is present
        |
        |-- scheduled or queue-triggered dispatcher claims queued attempts
        |
        |-- worker executes loyalty accrual
        |
        |-- attempt row and current consequence projection advance atomically
```

The HTTP sync request must not rely on an in-memory post-commit callback as the only publication mechanism.

`loyalty = queued` is valid only when all of the following exist:

1. Durable consequence-attempt row or outbox event.
2. Stable idempotency key.
3. Server-owned sale ID.
4. Validated customer financial account.
5. Earning rule reference or sufficient server-owned recomputation inputs.
6. Resolved business date.
7. Retry count and visibility metadata.

Without durable evidence:

```text
loyalty must not be queued
```

Loyalty outbox payload:

```text
offline_sales_import_id
sale_id
customer_financial_account_id
resolved_business_date
offline_capture_timestamp
accepted_at
earning_context_version
idempotency_key
```

Recommended preliminary idempotency key:

```text
offline-loyalty-request:{sale_id}:{account_id}
```

Final ledger idempotency remains the Epic 39 key:

```text
sale-accrual:{sale_id}:{account_id}:{rule_id}:{rule_version}
```

If loyalty remains asynchronous:

1. job dispatch must be durable;
2. idempotency key must be stable;
3. support must be able to inspect pending or failed loyalty consequence;
4. cashier/customer messaging must not imply points are final before the job succeeds.
5. loyalty effective business date must use the server-resolved sale business date.
6. permanent failure must remain support-visible and must not silently become skipped.

First-release loyalty failure classification:

```text
infrastructure or transient database failure
    -> retryable_failed

invalid earning configuration discovered after acceptance
    -> review_required

confirmed policy-based ineligibility
    -> skipped_by_policy

retry exhaustion where automated recovery is no longer allowed
    -> failed
```

## Customer Identity Policy

Story 41.6 may introduce optional offline customer evidence fields only as captured evidence, not authority.

Suggested envelope fields:

```text
customer_financial_account_id
customer_snapshot_version
customer_snapshot_hash
customer_lookup_captured_at
loyalty_expected
```

Validation rules:

1. Cross-tenant customer account is hidden and treated as invalid evidence.
2. Missing account with `loyalty_expected = false` means `loyalty = skipped_by_policy`.
3. Inactive, merged, missing, or cross-tenant account with `loyalty_expected = false` means `loyalty = skipped_by_policy`.
4. If `loyalty_expected = true` but account cannot be validated, use review-required for cash-exposed records and rejected for pre-cash records.
5. Do not create or merge customer accounts during offline sync.
6. Do not automatically redirect an offline customer reference to a merged successor account unless Epic 39 already exposes a deterministic, audited canonical-account resolver.

Merged-account fallback:

```text
loyalty_expected = false -> skipped_by_policy
loyalty_expected = true  -> review_required for cash exposure
```

If Epic 39 already provides an audited canonical-account resolver, Story 41.6 may resolve a merged account only when:

1. the mapping is same-tenant;
2. the mapping is deterministic;
3. audit evidence is preserved;
4. no duplicate accrual can occur across old and new accounts;
5. the final idempotency key uses the canonical account.

## Store Credit Policy

Store credit remains online-only for first-release offline capture.

Reject or review before sale creation when an offline payload contains:

```text
store_credit_redemption
store_credit_account_id
store_credit_ledger_entry_id
store_credit_amount
payment_method = store_credit
payments.*.payment_method_type_snapshot = store_credit
```

Expected outcome:

1. pre-cash record: rejected;
2. collected or uncertain cash record: review_required;
3. valid cash-only record: `consequence_status.store_credit = not_applicable`.

## Business Date and Delayed Sync

Device time is evidence. Server policy resolves committed business date.

Story 41.6 must preserve:

1. device `terminal_timestamp`,
2. local `business_date`,
3. server `accepted_at`,
4. `resolved_business_date`,
5. `business_date_status`,
6. sync delay duration.
7. terminal timestamp trust status.

Delayed sync rules:

1. Inventory movement source reference must point to the committed sale and offline import evidence.
2. Loyalty accrual source snapshot must preserve sale and sync evidence.
3. Reports must be able to distinguish:
   - local capture date,
   - server acceptance date,
   - resolved business date.
4. Delayed sync must not rewrite the local capture envelope.
5. Loyalty earning eligibility, rule-effective evaluation, and ledger business date use `resolved_business_date`.
6. `processed_at` remains the technical execution timestamp.

Persist:

```text
reported_sync_delay_seconds = accepted_at - terminal_timestamp when timestamp is trusted
normalized_sync_delay_seconds = bounded operational delay or null when timestamp is invalid
terminal_timestamp_trust_status
loyalty_effective_business_date
offline_capture_timestamp
server_accepted_at
loyalty_processed_at
```

Recommended `terminal_timestamp_trust_status` values:

```text
trusted
within_tolerance
suspicious
invalid
```

When terminal timestamp trust is `suspicious` or `invalid`, raw timestamp evidence must still be retained, but direct subtraction must not produce misleading negative or extreme delay metrics.

## Cached Stock Visibility Policy

While offline, stock is never authoritative.

Required UI behavior:

1. Cached stock, if displayed, must be labelled provisional/stale.
2. Last stock sync timestamp must be visible wherever cached stock influences cashier decision-making.
3. Offline capture must not decrement local displayed stock as committed inventory.
4. Cart and payment flow must not promise item availability based on cached stock.
5. If cached stock is absent, checkout may continue only under the existing offline sale policy, with server sync resolving inventory later.

Recommended wording:

```text
Stock shown while offline may be stale. Final inventory posting happens after server sync.
```

## Backend Implementation Plan

Recommended implementation slices:

### Slice 1 - Consequence contract foundation

1. Introduce or formalize `OfflineConsequenceStatusBuilder`.
2. Define required and conditional consequence definitions.
3. Normalize allowed status values.
4. Add metadata for inventory, variance, loyalty, store credit, receipt, accounting, and business date.
5. Preserve immutable `acceptance_consequence_snapshot`.
6. Build mutable `current_consequence_status` projection separately.
7. Ensure `OfflineSyncStatusProjectionService` returns consequence state consistently for cashier, manager, support, and audit roles.

### Slice 2 - Durable consequence attempt infrastructure

Introduce:

```text
offline_sync_consequence_attempts
```

Minimum fields:

```text
id
tenant_id
branch_id
offline_sales_import_id
sale_id
consequence_type
status
idempotency_key
attempt_no
available_at
claimed_at
claim_owner
started_at
completed_at
failed_at
next_retry_at
last_error_code
last_error_summary
result_reference_type
result_reference_id
metadata_json
created_at
updated_at
```

`attempt_no` is server-controlled and increments atomically when a worker starts processing or retries an attempt. Creating the durable intent does not count as an execution attempt.

Recommended unique constraint:

```text
unique(offline_sales_import_id, consequence_type, idempotency_key)
```

Allowed attempt statuses:

```text
pending
queued
processing
committed
skipped_by_policy
retryable_failed
failed
review_required
```

Attempt status rules:

1. `pending` means the durable intent exists but is not yet claimable.
2. `queued` means the durable intent is available for worker claim.
3. Implementations may use only `pending` plus `available_at` if that simplifies the projection, but must not allow ambiguous pending/queued semantics.
4. Workers must claim rows atomically and preserve the original idempotency key across retries.

This table is operational evidence. Domain ledgers remain the final business authority.

### Slice 3 - Consequence coordinator

Introduce:

```text
OfflineAcceptedSaleConsequenceCoordinator
```

Responsibilities:

1. Invoke existing domain services in required order.
2. Build acceptance consequence state.
3. Register durable asynchronous attempts.
4. Convert typed domain failures into Story 41.4 outcomes.
5. Prevent accepted results with missing required consequences.

It must not:

1. Calculate inventory.
2. Deduct ingredients itself.
3. Calculate loyalty points.
4. Post loyalty ledgers directly.
5. Mutate store credit.
6. Issue receipts.

Suggested result:

```php
final class OfflineConsequenceResult
{
    public array $statuses;
    public array $attemptIds;
    public bool $acceptanceAllowed;
    public ?string $failureCode;
    public ?string $recommendedSyncStatus;
}
```

### Slice 4 - Transactional inventory consequence

1. Introduce `OfflineInventoryConsequenceService` or equivalent method.
2. Execute `InventoryService::deductFromSale($sale)` inside the accepted-sale transaction.
3. Preserve existing source-effect idempotency.
4. Convert strict-stock exceptions into review-required decisions for cash-exposed records.
5. Preserve soft-negative movement and exception evidence.
6. Test recipe deduction and conversion snapshot paths.
7. Verify expected direct, recipe, and soft-negative evidence after deduction.
8. Lock affected inventory rows in deterministic order where implementation touches row ordering.

Suggested idempotency source for the adapter:

```text
offline-sale-inventory:{offline_import_uuid}:{sale_id}
```

Existing domain-specific movement keys may remain unchanged if already stable and unique.

### Slice 5 - Loyalty durable outbox

1. Register loyalty accrual intent transactionally with the accepted sale.
2. Use `SalePaid::fromSale($sale)` or an equivalent server-owned payload.
3. Preserve loyalty ledger idempotency.
4. Handle missing/inactive/cross-tenant customer accounts according to policy.
5. Update `consequence_status.loyalty` without adding new top-level statuses.
6. Add worker success/failure projection.
7. Ensure retry preserves the original idempotency key.
8. Add a dispatcher or claim loop that processes committed attempts; do not depend only on request-memory callbacks.

### Slice 6 - Store credit and customer validation

1. Extend `OfflineEnvelopePolicyValidator` to detect store-credit redemption evidence.
2. Ensure store-credit payloads are rejected or reviewed before sale creation.
3. Add tests proving no store-credit ledger entry can be created from offline sync.
4. Add customer snapshot validation.
5. Add `loyalty_expected` decision logic.
6. Add merged-account handling.

### Slice 7 - Business date, UI, and reporting evidence

1. Add cached-stock provisional labels where offline stock is visible.
2. Ensure offline checkout messaging says loyalty is evaluated after sync.
3. Preserve delayed-sync evidence in offline import, sale metadata, inventory metadata, and loyalty metadata.
4. Add reporting assertions for local capture date, server accepted date, and resolved business date.
5. Persist sync delay and timestamp trust status.
6. Ensure receipt consequence status is explicit.
7. Identify the existing service, outbox, or job that advances receipt status to `committed` after official document completion. If no existing owner exists, introduce a narrow receipt finalization owner rather than leaving receipt status permanently pending.

## Files Expected to Change

Likely backend files:

1. `app/Services/POS/OfflineSync/OfflineEnvelopeSynchronizationService.php`
2. `app/Services/POS/OfflineSync/OfflineEnvelopePolicyValidator.php`
3. `app/Services/POS/OfflineSync/OfflineSyncStatusProjectionService.php`
4. new `app/Services/POS/OfflineSync/OfflineConsequenceStatusBuilder.php`
5. new `app/Services/POS/OfflineSync/OfflineAcceptedSaleConsequenceCoordinator.php`
6. new `app/Services/POS/OfflineSync/OfflineInventoryConsequenceService.php`
7. new `app/Services/POS/OfflineSync/OfflineLoyaltyConsequenceService.php`
8. new `app/Models/OfflineSyncConsequenceAttempt.php`
9. migration for `offline_sync_consequence_attempts`
10. `app/Http/Requests/POS/SyncBatchRequest.php`
11. existing receipt/fiscal finalization service, outbox, or job that owns receipt consequence completion.

Likely frontend files:

1. `resources/js/Pages/POS/Index.jsx`
2. `resources/js/Pages/POS/Components/ProductGrid.jsx`
3. `resources/js/Pages/POS/Components/Cart.jsx`
4. `resources/js/POS/offline/catalogCache.ts`
5. `resources/js/POS/offline/offlineSalesQueue.ts`

Likely tests:

1. `tests/Feature/POS/OfflineSyncEpic41ContractTest.php`
2. new or existing inventory offline sync tests under `tests/Feature/POS`
3. new or existing loyalty offline sync tests under `tests/Feature/POS`
4. `tests/Frontend/offlineQueueSync.test.js`
5. frontend tests for stock and loyalty messaging where existing harness supports it.

## Acceptance Criteria

### AC1 - Inventory posts exactly once

Given an offline cash-sale envelope with inventory-tracked products,
when the server accepts the sync,
then inventory movements are produced through server inventory services,
and exact replay does not create duplicate movements.

### AC2 - Recipe deductions post exactly once

Given an offline cash-sale envelope containing a product with recipe ingredients,
when the server accepts the sync,
then ingredient deductions and conversion snapshots are produced through the existing recipe path,
and replay does not duplicate ingredient movements.

### AC3 - Strict negative stock fails closed

Given strict stock policy and insufficient inventory,
when a cash-collected offline envelope syncs,
then the envelope does not become accepted,
and the result is review-required with no silent sale/inventory mismatch.

### AC4 - Soft negative stock records exception evidence

Given soft negative-stock policy,
when an offline sale deduction takes stock below zero,
then the sale may be accepted only if movement and negative-stock exception evidence are both committed or durably represented.

### AC5 - Loyalty accrual is server-authoritative and idempotent

Given an accepted offline sale with an eligible customer financial account,
when loyalty accrual runs,
then exactly one loyalty accrual ledger entry is created or durably queued,
and exact replay does not duplicate points.

### AC6 - Invalid customer identity does not corrupt loyalty

Given an offline envelope with missing, inactive, merged, or cross-tenant customer evidence,
when the sync is processed,
then loyalty is skipped or the record enters review according to policy,
and no incorrect loyalty ledger entry is created.

### AC7 - Store credit remains online-only

Given an offline envelope containing store-credit redemption evidence,
when the server validates the payload,
then the record is rejected or review-required before sale creation,
and no store-credit ledger entry is created.

### AC8 - No partial consequence success is hidden

Given any required consequence fails,
when the sync response is returned,
then consequence status exposes the failure/review/retry state,
and top-level `accepted` is not returned unless the acceptance policy permits durable pending consequence evidence.

### AC9 - Delayed sync preserves reporting evidence

Given an offline sale captured on one business date and synchronized later,
when accepted,
then reports can distinguish local capture date, server acceptance date, and server-resolved business date.

### AC10 - Offline UI does not overpromise

Given the terminal is offline,
when the cashier views stock or customer/loyalty messaging,
then stock is labelled provisional/stale and loyalty is described as evaluated after server sync.

### AC11 - Inventory is transaction-bound

Given a tracked or recipe sale,
when inventory posting fails,
then sale, payment, movement, variance, and accepted status do not partially commit.

### AC12 - Loyalty intent is durable

Given an eligible loyalty sale,
when synchronization returns accepted with `loyalty = queued`,
then a unique durable consequence attempt exists in the committed database.

### AC13 - Acceptance snapshot is immutable

Given a consequence later moves from queued to committed or failed,
then `acceptance_consequence_snapshot` remains unchanged and only the current projection advances.

### AC14 - Review persists after posting rollback

Given strict-stock or configuration failure with collected or uncertain cash,
when the posting transaction rolls back,
then a separate import-state transaction records `review_required` and confirms no partial sale consequence exists.

### AC15 - Effective dates remain distinct

Given delayed synchronization,
then loyalty and reporting preserve terminal capture time, resolved business date, server acceptance time, and consequence processing time.

### AC16 - Concurrent processing is safe

Given two workers or requests process the same consequence,
then only one inventory effect, loyalty ledger entry, variance record, and attempt result is committed.

## Test Plan

Backend feature tests:

1. Accepted offline sale with tracked product creates one sale deduction movement.
2. Exact replay creates no additional inventory movement.
3. Accepted offline sale with recipe product creates ingredient movements.
4. Exact replay creates no duplicate recipe movements.
5. Strict negative-stock cash-collected sync enters review-required.
6. Soft negative-stock sync creates movement and exception evidence.
7. Missing inventory setup cash-collected sync enters review-required.
8. Eligible customer account creates or queues exactly one loyalty accrual.
9. Loyalty replay does not duplicate ledger entries.
10. Inactive/cross-tenant customer does not create loyalty entry.
11. Store-credit redemption payload is rejected or reviewed before sale creation.
12. Consequence status response contains sale, payment, inventory, variance, loyalty, store_credit, receipt, accounting_outbox, and business_date keys.
13. Delayed sync preserves local capture timestamp and server accepted timestamp.
14. Accepted sync with `loyalty = queued` has one durable `offline_sync_consequence_attempts` row.
15. Current consequence status advances after a worker completes without rewriting acceptance snapshot.
16. Concurrent loyalty workers cannot double-post the ledger.
17. Concurrent offline sync requests cannot double-post inventory or consequence attempts.
18. Deadlock retry preserves the same import identity and does not create review state unless retry policy is exhausted.
19. Invalid or suspicious terminal timestamps do not produce misleading sync-delay metrics.
20. Receipt consequence status advances through an identified owner and does not remain indefinitely pending without diagnostics.

Frontend tests:

1. Offline stock label includes provisional/stale wording.
2. Offline capture does not locally decrement cached stock.
3. Offline checkout does not promise loyalty accrual.
4. Sync result with pending loyalty is displayed as consequence-specific pending, not a new top-level accepted-with-warning state.

Regression tests:

1. Existing Story 41.3 sync contract tests continue to pass.
2. Existing Story 41.4 review-required tests continue to pass.
3. Existing Story 41.5 offline restriction tests continue to pass.
4. Existing Epic 40 inventory movement, negative-stock, and recipe tests continue to pass.
5. Existing Epic 39 loyalty runtime tests continue to pass.

## Definition of Done

Story 41.6 is done when:

1. Acceptance criteria pass.
2. Feature tests pass for inventory, recipe, strict negative stock, soft negative stock, loyalty, store credit, replay, and delayed sync.
3. Frontend tests pass for provisional stock and loyalty messaging.
4. `php artisan test tests/Feature/POS` passes.
5. Relevant Epic 39 loyalty tests pass.
6. Relevant Epic 40 inventory tests pass.
7. `npm run build` passes.
8. `git diff --check` passes.
9. No browser-local inventory, loyalty, store-credit, or fiscal authority is introduced.
10. Code review confirms no top-level sync status expansion for pending loyalty or inventory consequences.
11. Code review confirms inventory is synchronous and transaction-bound for first release.
12. Code review confirms loyalty queued status is backed by durable consequence-attempt or outbox evidence.
13. Code review confirms acceptance snapshot and current status projection are separate.
14. Code review confirms attempt numbers are server-controlled and increment only on processing starts.
15. Code review confirms durable attempts have an explicit dispatcher or worker claim path.
16. Code review confirms expected inventory evidence is matched against an expected-effect plan.
17. Code review confirms receipt finalization ownership is explicit.

## Implementation Notes

Important cautions:

1. Do not dispatch loyalty from offline sync without durable status evidence.
2. Do not return `accepted` for strict-stock failures just because sale/payment committed.
3. Do not create a second sale-posting engine outside `SaleCreationService`.
4. Do not add local inventory decrement behavior to make the UI look responsive.
5. Do not recalculate historical recipe or conversion rules from current configuration.
6. Do not create customer accounts during offline sync.
7. Do not create store-credit ledger entries from offline sync.
8. Preserve exact replay behavior from Story 41.3.
9. Preserve review-required behavior from Story 41.4.
10. Preserve cash-only and online-only restrictions from Story 41.5.
11. Do not use `not_applicable` for receipt on an accepted ordinary sale that still requires official document completion.
12. Do not rewrite `acceptance_consequence_snapshot` after worker completion.

## Locked Implementation Decisions

These decisions are incorporated from architectural review and are no longer open questions:

1. Inventory deduction is synchronous and transaction-bound for the first release.
2. Loyalty accrual is asynchronous through a transactionally durable outbox or consequence-attempt record.
3. A dedicated consequence-attempt table is introduced unless an existing generic outbox already satisfies the full attempt, claim, retry, error, result-reference, and support-query contract.
4. Invalid optional customer identity results in `loyalty = skipped_by_policy`.
5. Invalid expected-loyalty identity routes cash-exposed records to review and pre-cash records to rejection.
6. Loyalty uses the server-resolved business date.
7. `acceptance_consequence_snapshot` remains immutable.
8. `current_consequence_status` remains a separate projection.
9. Strict-stock failure rolls back sale and payment before review is recorded.
10. Store credit remains `not_applicable` only after prohibited-payload validation passes.
11. Receipt consequence status is explicit: ordinary accepted sales use `pending` or `queued` until official document completion is durable.
12. Expected inventory evidence is verified, not inferred from the absence of an exception.
13. Sync delay and timestamp trust status are persisted.
14. `pending` and `queued` attempt states have distinct semantics, or the implementation uses one state plus `available_at`.
15. `attempt_no` is server-controlled and is not accepted from client payloads.
16. Loyalty permanent failure remains visible as `failed` or `review_required`; it is never silently converted to skipped.
17. Inventory evidence verification uses an expected-effect plan.
18. Durable asynchronous attempts require an explicit dispatcher or worker claim path.
19. Deadlock and lock-timeout failures retry posting before review classification.
20. Invalid terminal timestamps preserve raw evidence without producing misleading sync-delay metrics.
21. Receipt status has an identified completion owner.
