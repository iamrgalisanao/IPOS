# Story 39.10 Loyalty Reversal on Void and Refund

## 1. Status

Done

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/README.md`
2. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
3. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
4. `docs/implementation-plans/epic-39/stories/story-39.6-loyalty-ledger.md`
5. `docs/implementation-plans/epic-39/stories/story-39.7-loyalty-redemption.md`
6. `docs/implementation-plans/epic-39/stories/story-39.9-loyalty-runtime-implementation.md`
7. `app/Models/LoyaltyLedgerEntry.php`
8. `app/Models/LoyaltyRedemption.php`
9. `app/Services/Loyalty/LoyaltyLedgerService.php`
10. `app/Services/Loyalty/LoyaltyBalanceService.php`
11. `app/Services/POS/VoidService.php`
12. `app/Services/POS/RefundService.php`
13. `app/Models/SaleVoid.php`
14. `app/Models/SaleRefund.php`
15. Research note: public POS/loyalty behavior review for StoreHub, Mosaic, UTAK, Square, Yotpo, and Odoo.

## 3. Objective

Implement automatic loyalty ledger reversal behavior when paid sales are voided or refunded.

This story closes the remaining first-release loyalty correctness gap left after Story 39.9:

1. Earned loyalty points must be reversed when the economic value of a sale is voided or refunded.
2. Redeemed loyalty points must be restored when the sale that consumed them is voided or refunded according to tenant policy.
3. Reversal and restoration must be explicit append-only loyalty ledger entries.
4. Original sale, refund, void, accrual, and redemption records must remain immutable.

The goal is to prevent customers from keeping rewards earned from returned purchases while also preventing unfair loss of redeemed points when a sale is reversed.

## 4. User Story

As an owner,
I want voids and refunds to automatically create auditable loyalty reversal entries,
so that customer point balances remain accurate without weakening sale, payment, refund, void, tax, store-credit, or accounting boundaries.

## 5. Research Summary

Public competitor documentation confirms that StoreHub, Mosaic, and UTAK support void/refund workflows and that StoreHub and Mosaic discuss loyalty/rewards publicly. Public documentation did not confirm exact automatic loyalty reversal rules for those vendors.

Industry best-practice evidence is clearer:

1. Square documents full-refund and partial-refund point removal behavior for loyalty programs.
2. Yotpo documents point reversal for returned purchases regardless of refund method.
3. Odoo fixed a POS loyalty exploit where refunded orders did not deduct earned points.

Planning conclusion:

Do not infer competitor behavior from sales/refund records alone. IPOS should implement explicit, append-only loyalty reversal ledger entries linked to void/refund source evidence.

## 6. Current Code Context

Current runtime facts:

1. `LoyaltyLedgerEntry` already exists and is append-only.
2. `LoyaltyLedgerService` owns loyalty ledger posting.
3. `LoyaltyBalanceService` derives point balances from ledger rows.
4. `LoyaltyAccrualService` posts `sale_accrual` credit entries after paid sales.
5. `LoyaltyCheckoutRedemptionCoordinator` posts `redemption_debit` rows during payment finalization.
6. `VoidService::void()` creates immutable `sale_voids`, reverses payments and inventory, updates sale status to `voided`, and records accounting outbox evidence.
7. `RefundService::refund()` creates immutable `sale_refunds` and `sale_refund_items`, reverses payments, updates sale status, handles inventory return, reverses statutory/commercial aggregates, and records accounting outbox evidence.
8. `SaleVoid` and `SaleRefund` records are immutable.
9. `LoyaltyLedgerService` currently blocks debit entries that would create negative balances.

Planning implication:

This story should introduce a loyalty reversal orchestration service and hook it into `VoidService` and `RefundService`. Controllers must not write loyalty ledger rows directly.

## 7. Locked Decisions

1. Loyalty reversals are ledger entries, not mutations of original loyalty rows.
2. Original `sale_accrual` and `redemption_debit` rows remain immutable.
3. Original `LoyaltyRedemption` rows remain immutable except for behavior already approved in Story 39.9.
4. Void/refund source records remain the source evidence for reversal.
5. Loyalty reversal is based on economic reversal of the sale, not payout method.
6. Cash, card, e-wallet, and store-credit refund payout methods must not bypass earned-point reversal.
7. Loyalty points remain non-monetary and must not create accounting liability outbox rows.
8. Store-credit refund issuance remains separate from loyalty reversal.
9. Store-credit ledger rows and loyalty ledger rows must never be mixed.
10. Offline loyalty reversal mutation is prohibited in the first release.
11. Training sales must not create real loyalty reversal rows because they must not create real loyalty accrual/redemption rows.
12. Full void reverses all earned points and restores all redeemed points by default.
13. Full refund reverses all earned points by default.
14. Full refund restores redeemed points by default, subject to tenant policy.
15. Partial refund reverses earned points using item-linked logic where available, otherwise proportional eligible amount logic.
16. Partial refund restores redeemed points using tenant policy.
17. Negative point balances may be allowed for refund/void reversal only when tenant policy permits it.
18. Negative point balance must never be created silently; it must be auditable and policy-driven.
19. Idempotent replay must not duplicate reversal entries.
20. Reversal drift must be rejected before mutation.

### Loyalty Reversal Invariants

1. Original loyalty ledger rows remain immutable.
2. Every reversal is represented by append-only loyalty ledger entries.
3. Mandatory loyalty reversals are atomic with their parent void/refund transaction unless an approved pending reversal workflow exists.
4. Loyalty reversal never creates accounting liability.
5. Loyalty reversal never writes store-credit ledger entries.
6. Exact replay never duplicates reversal entries.
7. Drift is rejected before mutation.

## 8. Dependencies

1. Story 39.9 loyalty runtime implementation.
2. `LoyaltyLedgerService`.
3. `LoyaltyBalanceService`.
4. `VoidService`.
5. `RefundService`.
6. `SaleVoid`.
7. `SaleRefund`.
8. `SaleRefundItem`.
9. Existing payment reversal behavior.
10. Existing audit logger.
11. Existing tenant and branch context guards.

## 9. Scope

In scope:

1. Loyalty reversal configuration.
2. New loyalty ledger entry types for void/refund reversal and restoration.
3. Loyalty reversal service.
4. Full-sale void loyalty reversal.
5. Full-refund loyalty reversal.
6. Partial-refund earned-point reversal.
7. Redemption restoration for void/refund according to policy.
8. Negative-balance policy for reversal debit rows.
9. Idempotency and drift detection for reversal commands.
10. Audit payloads linking sale, void/refund, original loyalty rows, actor, branch, reason, and timestamp.
11. Reporting/customer statement visibility using existing loyalty report surfaces.
12. Feature tests for void, refund, partial refund, payout-method independence, negative balance policy, idempotency, and no accounting liability outbox.

Out of scope:

1. Loyalty tiers.
2. Tier progress reversal.
3. Promo voucher reuse/restoration.
4. Birthday reward restoration.
5. Third-party loyalty providers.
6. Customer-facing wallet UI.
7. Manual loyalty admin adjustments.
8. Automatic abuse scoring beyond basic auditable flags.
9. Offline void/refund loyalty mutation.
10. Retroactive migration of historical voids/refunds.
11. Recalculation of original loyalty accrual rules from current configuration.

## 10. Ledger Entry Types

Add explicit loyalty ledger entry types:

1. `void_earn_reversal`
2. `refund_earn_reversal`
3. `void_redemption_restore`
4. `refund_redemption_restore`

Direction mapping:

| Entry Type | Direction | Category |
| --- | --- | --- |
| `void_earn_reversal` | debit | reversal |
| `refund_earn_reversal` | debit | reversal |
| `void_redemption_restore` | credit | reversal |
| `refund_redemption_restore` | credit | reversal |

These should be added to `LoyaltyLedgerEntry::categoryForEntryType()` and `LoyaltyLedgerService::directionForEntryType()`.

Existing generic `reversal_credit` and `reversal_debit` may remain for future generic adjustments, but this story should use explicit source-specific types for audit clarity.

## 11. Configuration

Introduce tenant-scoped loyalty reversal settings.

Recommended model:

```text
loyalty_reversal_settings
```

Required fields:

1. `id`
2. `tenant_id`
3. `reverse_earned_on_void`
4. `reverse_earned_on_refund`
5. `restore_redeemed_on_void`
6. `restore_redeemed_on_refund`
7. `allow_negative_balance`
8. `require_approval_for_negative_balance`
9. `negative_balance_approval_threshold_points`
10. `restore_redeemed_on_partial_refund_policy`
11. `refund_earn_reversal_policy`
12. `settings_schema_version`
13. timestamps

Recommended defaults:

| Setting | Default |
| --- | --- |
| `reverse_earned_on_void` | true |
| `reverse_earned_on_refund` | true |
| `restore_redeemed_on_void` | true |
| `restore_redeemed_on_refund` | true |
| `allow_negative_balance` | true |
| `require_approval_for_negative_balance` | true |
| `negative_balance_approval_threshold_points` | 0 |
| `restore_redeemed_on_partial_refund_policy` | `proportional` |
| `refund_earn_reversal_policy` | `item_linked_then_proportional` |

Allowed `restore_redeemed_on_partial_refund_policy` values:

1. `none`
2. `proportional`
3. `full_when_fully_refunded`

Allowed `refund_earn_reversal_policy` values:

1. `item_linked`
2. `proportional`
3. `item_linked_then_proportional`

First implementation may create settings lazily with defaults when no row exists, but the created defaults must be deterministic and auditable.

## 12. Service Design

Add:

```text
App\Services\Loyalty\LoyaltyReversalService
```

Responsibilities:

1. Reverse earned points on void.
2. Restore redeemed points on void.
3. Reverse earned points on refund.
4. Restore redeemed points on refund.
5. Build reversal source snapshots.
6. Enforce tenant reversal settings.
7. Enforce idempotency.
8. Coordinate negative-balance policy.
9. Write audit events.

Suggested public methods:

```php
reverseForVoid(Sale $sale, SaleVoid $void, User $actor): array
reverseForRefund(Sale $sale, SaleRefund $refund, array $refundItems, User $actor): array
```

Return payload:

```php
[
    'earned_reversal_entry_ids' => [],
    'redemption_restore_entry_ids' => [],
    'negative_balance_created' => false,
    'approval_required' => false,
    'skipped_reasons' => [],
]
```

Controllers must not call `LoyaltyLedgerService` directly for reversal behavior.

## 13. Negative Balance Policy

Current `LoyaltyLedgerService` blocks debit rows that would create a negative balance.

This story must extend behavior safely.

Recommended approach:

1. Keep normal debit behavior unchanged for `redemption_debit`.
2. Allow negative balances only for reversal debit types:
   - `void_earn_reversal`
   - `refund_earn_reversal`
3. Require an explicit service payload flag such as:

```php
'allow_negative_balance' => true
```

4. Require the source snapshot to include:
   - balance before reversal
   - balance after reversal
   - setting version
   - policy reason
   - approval reference if threshold requires approval
5. If policy disallows negative balance, reversal must not silently skip. It must either:
   - throw a domain conflict, or
   - create a pending approval record if approval workflow is available for this story.

First-release default:

Allow negative balance for loyalty earn reversal only when tenant policy explicitly permits it. If the reversal would require approval and no formal pending reversal workflow exists, the parent void/refund transaction must roll back instead of committing with an audit-only flag.

### Failure Atomicity Rule

If loyalty reversal is mandatory under tenant policy and the required loyalty ledger entries cannot be posted successfully, the parent void/refund transaction must roll back.

The only exception is a formally approved pending loyalty reversal workflow. In that workflow:

1. The financial transaction records an explicit pending loyalty state.
2. The pending reversal is tracked as its own immutable workflow record.
3. Completion requires the approved workflow before the loyalty ledger reaches its final state.

The system must never complete a successful financial/economic reversal while silently skipping or merely flagging a mandatory loyalty reversal.

## 14. Idempotency

Every reversal row must be idempotent.

Recommended idempotency keys:

```text
void-earn-reversal:{sale_void_id}:{original_accrual_entry_id}
void-redemption-restore:{sale_void_id}:{loyalty_redemption_id}
refund-earn-reversal:{sale_refund_id}:{original_accrual_entry_id}:{scope_hash}
refund-redemption-restore:{sale_refund_id}:{loyalty_redemption_id}:{scope_hash}
```

`scope_hash` should include material refund scope fields:

1. `sale_refund_id`
2. refunded sale item IDs
3. refunded quantities
4. refund total centavos
5. restoration policy
6. reversal policy

Replay behavior:

1. Exact replay returns the original ledger entry.
2. Same idempotency key with different material fields throws `LoyaltyLedgerIdempotencyDriftException`.
3. Duplicate void/refund source attempts must not create duplicate reversal entries.

## 15. Void Behavior

When `VoidService::void()` succeeds inside its existing transaction:

1. Create `SaleVoid`.
2. Reverse payments.
3. Reverse inventory.
4. Update sale status to `voided`.
5. Reverse statutory/commercial aggregates.
6. Call `LoyaltyReversalService::reverseForVoid($sale, $void, $actor)`.
7. Record audit and accounting outbox as today.

Loyalty behavior:

1. Find all `loyalty_ledger_entries` for the sale where:
   - `source_type = sale`
   - `source_id = sale.id`
   - `entry_type = sale_accrual`
2. For each accrual row, post `void_earn_reversal` debit for the same point count.
3. Find any finalized `loyalty_redemptions` for the sale.
4. For each redeemed row, post `void_redemption_restore` credit for the redeemed point count.
5. Do not modify original accrual, redemption, sale, or void rows.

Expected net loyalty result after full void:

```text
sale_accrual            +X
redemption_debit        -Y
void_earn_reversal      -X
void_redemption_restore +Y
```

Net loyalty effect for the voided sale: zero.

## 16. Refund Behavior

When `RefundService::refund()` succeeds inside its existing transaction:

1. Create `SaleRefund`.
2. Create `SaleRefundItem` rows.
3. Reverse payments.
4. Update sale status to `refunded` or `partially_refunded`.
5. Reverse statutory/commercial aggregates.
6. Issue store credit if requested by payout command.
7. Call `LoyaltyReversalService::reverseForRefund($sale, $refund, $refund->items, $actor)`.
8. Record audit and accounting outbox as today.

Earned-point reversal:

1. Full refund reverses all remaining earned points from original `sale_accrual` rows.
2. Partial refund uses item-linked policy when possible.
3. If item-linked allocation does not exist, use proportional policy:

```text
refund_total_centavos / original_sale_total_centavos
```

4. Repeated partial refunds must converge to the full original accrual amount and never over-reverse.
5. Reversal must use cumulative refund history, not just the current refund, to avoid rounding drift.

Redeemed-point restoration:

1. Full refund restores all redeemed points by default.
2. Partial refund follows tenant policy:
   - `none`: do not restore redeemed points.
   - `proportional`: restore points based on current cumulative refunded amount vs original sale total.
   - `full_when_fully_refunded`: restore only after cumulative refund reaches full sale total.
3. Repeated partial refunds must converge to the full redeemed point count and never over-restore.

Refund payout method:

The payout method must not affect earned-point reversal:

1. Cash refund: reverse earned points.
2. Card/e-wallet refund: reverse earned points.
3. Store-credit refund: reverse earned points.
4. Exchange/goodwill credit: reverse only when linked to returned sale value.

## 17. Rounding

Use integer points only.

Partial reversal rounding policy:

1. Calculate expected cumulative reversal points from cumulative refunded amount or refunded item allocation.
2. Subtract already-posted reversal points for the same original source.
3. Post only the delta.
4. Final full refund must reverse/restore all remaining points exactly.

Example:

```text
Original earned points: 101
Refund 1 cumulative ratio: 33.33% -> expected cumulative reversal 34
Refund 2 cumulative ratio: 66.66% -> expected cumulative reversal 67, post delta 33
Final refund cumulative ratio: 100% -> expected cumulative reversal 101, post delta 34
```

## 18. Source Snapshot Requirements

Every reversal/restoration ledger entry must include:

1. `snapshot_version`
2. `reversal_type`
3. `sale_id`
4. `sale_number`
5. `sale_void_id` or `sale_refund_id`
6. original loyalty ledger entry ID
7. original loyalty entry type
8. original loyalty points
9. current reversal/restoration points
10. cumulative reversed/restored points
11. sale total centavos
12. refund total centavos when applicable
13. cumulative refund total centavos when applicable
14. refund item IDs and quantities when applicable
15. refund payout method when applicable
16. balance before
17. balance after
18. negative balance policy
19. tenant reversal settings snapshot
20. actor ID
21. reason code
22. reason notes
23. terminal ID if available
24. business date

## 19. Audit Events

Add audit events:

1. `LOYALTY_VOID_EARN_REVERSAL_POSTED`
2. `LOYALTY_VOID_REDEMPTION_RESTORE_POSTED`
3. `LOYALTY_REFUND_EARN_REVERSAL_POSTED`
4. `LOYALTY_REFUND_REDEMPTION_RESTORE_POSTED`
5. `LOYALTY_REVERSAL_SKIPPED_BY_POLICY`
6. `LOYALTY_REVERSAL_NEGATIVE_BALANCE_CREATED`
7. `LOYALTY_REVERSAL_IDEMPOTENCY_REPLAYED`
8. `LOYALTY_REVERSAL_IDEMPOTENCY_DRIFT_REJECTED`

Audit payloads must include the reversal entry ID, source void/refund ID, original loyalty row ID, account ID, points, and policy snapshot.

## 20. Reporting Requirements

Existing loyalty reports and customer statements should automatically include new reversal rows because they read `loyalty_ledger_entries`.

Update reporting totals so:

1. `points_earned` includes only `sale_accrual`.
2. `points_redeemed` includes only `redemption_debit`.
3. `points_reversed` includes:
   - `void_earn_reversal`
   - `refund_earn_reversal`
   - generic reversal debit/credit rows if retained
4. Add `points_restored` for:
   - `void_redemption_restore`
   - `refund_redemption_restore`
5. `points_balance` remains derived from signed ledger rows.

Customer statement rows must show:

1. source type
2. source reference
3. void/refund evidence ID
4. original loyalty row reference where available
5. balance impact direction

## 21. API and UI Scope

No new cashier-facing UI is required in the first implementation if existing void/refund flows already call `VoidService` and `RefundService`.

Required API behavior:

1. Existing void endpoint returns loyalty reversal summary if loyalty rows were affected.
2. Existing refund endpoint returns loyalty reversal summary if loyalty rows were affected.
3. Response must not expose internal source snapshots in cashier flow.

Suggested response fragment:

```json
{
  "loyalty_reversal": {
    "earned_points_reversed": 50,
    "redeemed_points_restored": 100,
    "negative_balance_created": false,
    "ledger_entry_ids": []
  }
}
```

Admin/reporting UI may be deferred unless existing customer account history already renders loyalty ledger rows generically.

## 22. Authorization

This story should not create new public mutation endpoints.

Authorization uses existing void/refund permissions and approval flows.

If negative balance approval is enforced:

1. Use the shared approval framework if available for loyalty adjustments.
2. Do not introduce a second approval system.
3. If approval integration is too large, first release may record approval-needed flags and block reversal above threshold, but must not silently skip reversal.

## 23. Offline Policy

Offline loyalty reversal is prohibited.

Rules:

1. Offline void/refund payloads must not create loyalty reversal rows.
2. If offline void/refund is ever introduced, loyalty reversal must be queued only as a server-side reconciliation action after online validation.
3. Current story should reject or defer any offline-origin payload that attempts loyalty mutation.

## 24. Accounting and Store Credit Boundaries

Loyalty reversal entries must not create monetary accounting liability events.

Rules:

1. No loyalty reversal may write `accounting_outbox` as monetary liability.
2. Store-credit refund issuance remains independent.
3. Refunding to store credit still reverses earned loyalty points.
4. Loyalty point restoration must not create store credit.
5. Store credit financial liability reports must not include loyalty rows.

## 25. Test Plan

Required tests:

1. `tests/Feature/Loyalty/LoyaltyReversalVoidTest.php`
2. `tests/Feature/Loyalty/LoyaltyReversalRefundTest.php`
3. `tests/Feature/Loyalty/LoyaltyReversalNegativeBalanceTest.php`
4. `tests/Feature/Loyalty/LoyaltyReversalReportingTest.php`
5. Existing `tests/Feature/Loyalty/LoyaltyRuntimeTest.php`
6. Existing `tests/Feature/POS/VoidServiceTest.php`
7. Existing `tests/Feature/StoreCredit/StoreCreditRefundIssuanceTest.php`
8. Existing `tests/Feature/Reports/Epic39ReportingReconciliationTest.php`

Required assertions:

1. Full void reverses all earned loyalty points.
2. Full void restores all redeemed loyalty points.
3. Full void leaves original loyalty rows immutable.
4. Full void links reversal rows to `sale_voids.id`.
5. Full refund reverses all earned loyalty points.
6. Full refund restores redeemed loyalty points according to default policy.
7. Partial refund reverses earned points proportionally or item-linked.
8. Repeated partial refunds converge to exact full reversal.
9. Refund to store credit still reverses earned loyalty points.
10. Refund payout method does not alter earned-point reversal behavior.
11. Negative balance is allowed only for reversal debit types and only by policy.
12. Negative balance is audited.
13. Policy disallowing negative balance blocks or flags reversal explicitly.
14. Replay does not duplicate reversal rows.
15. Drift is rejected before mutation.
16. Loyalty reversal failure rolls back the void/refund transaction unless policy explicitly queues approval.
17. No loyalty reversal creates `SalePayment`.
18. No loyalty reversal writes store-credit ledger rows.
19. No loyalty reversal creates monetary accounting outbox liability.
20. Customer statement shows reversal/restoration ledger rows.
21. Loyalty activity report includes reversed/restored totals.
22. Cross-tenant and cross-branch sale reversal is blocked by existing void/refund isolation.

Recommended commands:

```bash
php artisan test tests/Feature/Loyalty/LoyaltyReversalVoidTest.php
php artisan test tests/Feature/Loyalty/LoyaltyReversalRefundTest.php
php artisan test tests/Feature/Loyalty
php artisan test tests/Feature/POS/VoidServiceTest.php tests/Feature/StoreCredit/StoreCreditRefundIssuanceTest.php
php artisan test tests/Feature/Reports/Epic39ReportingReconciliationTest.php
php artisan test
npm run build
```

## 26. Acceptance Criteria

1. Loyalty reversal settings exist with deterministic defaults.
2. Explicit void/refund loyalty reversal entry types exist.
3. Full void posts earned-point reversal rows.
4. Full void posts redeemed-point restoration rows.
5. Full refund posts earned-point reversal rows.
6. Full refund posts redeemed-point restoration rows according to policy.
7. Partial refund reverses earned points by item-linked or proportional policy.
8. Partial refund restoration follows tenant policy.
9. Repeated partial refunds do not over-reverse or over-restore.
10. Refund payout method does not bypass earned-point reversal.
11. Reversal rows reference original loyalty rows.
12. Reversal rows reference `sale_voids` or `sale_refunds`.
13. Original loyalty rows remain immutable.
14. Original sale void/refund rows remain immutable.
15. Negative balances follow explicit policy.
16. Negative balances are audited.
17. Exact replay does not duplicate reversal entries.
18. Drift is rejected.
19. Void/refund transaction rolls back if required loyalty reversal fails.
20. Loyalty reversal does not create payments.
21. Loyalty reversal does not create store-credit rows.
22. Loyalty reversal does not create accounting liability outbox rows.
23. Reporting and customer statement surfaces show reversal/restoration rows.
24. Existing void/refund/store-credit/payment tests remain green.
25. Full backend suite passes.

## 27. Definition of Done

Story 39.10 is done when:

1. Acceptance criteria pass.
2. Feature tests pass.
3. Existing void tests pass.
4. Existing refund tests pass.
5. Existing loyalty runtime tests pass.
6. Existing store-credit tests pass.
7. Existing reporting tests pass.
8. Full backend test suite passes.
9. Frontend build passes if any user-facing output changes affect Inertia surfaces.
10. No architecture constraints are violated.
11. Local PR review is complete.
12. CI is green before merge.
13. Story status is updated to `Done`.

## 28. Implementation Slices

Recommended PR sequence:

1. **Domain foundation**
   - Add explicit loyalty reversal entry types.
   - Add reversal settings migration/model/service.
   - Add ledger negative-balance policy support for reversal debit only.

2. **Void integration**
   - Add `LoyaltyReversalService::reverseForVoid`.
   - Hook into `VoidService`.
   - Add void reversal tests.

3. **Refund integration**
   - Add `LoyaltyReversalService::reverseForRefund`.
   - Hook into `RefundService`.
   - Add full/partial refund tests.

4. **Reporting and audit**
   - Update loyalty report totals for reversed/restored points.
   - Add customer statement assertions.
   - Add audit event assertions.

5. **Regression hardening**
   - Store-credit payout refund tests.
   - Negative-balance policy tests.
   - Full suite and build.

## 29. Review Focus

Review this story against seven contracts:

1. Original loyalty rows remain immutable.
2. Void/refund source records remain the reversal evidence.
3. Earned points are reversed when sale value is reversed.
4. Redeemed points are restored only by explicit policy.
5. Negative balances are policy-driven and auditable.
6. Store credit and loyalty remain separate.
7. Loyalty remains non-monetary and never creates accounting liability.
