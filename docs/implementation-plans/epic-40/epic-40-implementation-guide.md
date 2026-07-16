# Epic 40 Inventory Operational Control and Reconciliation Implementation Guide

## 1. Status

Complete

Date: 2026-07-16

This guide defines the intended execution order for Epic 40. It does not replace:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
```

If this guide conflicts with the Architecture Lock, the Architecture Lock wins.

## 2. Implementation Order

Recommended order:

1. Story 40.1
2. Story 40.2
3. Story 40.3
4. Story 40.4
5. Story 40.5
6. Story 40.6
7. Story 40.7
8. Story 40.8

Reason:

1. Movement evidence, branch sequencing, and before/delta/after snapshots must be hardened before deeper reconciliation work.
2. Unit conversion governance and unit roles must be stable before recipe deduction is tightened.
3. Negative stock exception and resolution lifecycle depends on movement, conversion, and reconciliation evidence.
4. Recipe deduction snapshots depend on conversion versions and variance rules.
5. Stocktake reconciliation should consume stable movement sequencing and variance behavior.
6. Manual adjustment authorization should come after stocktake and opening-balance boundaries are clear.
7. Reporting should consume stable canonical evidence and separate stock cards from summaries.
8. Pilot UAT and recovery should validate the full operational chain.

## 3. Story Status

| Story | Status | Owner | Sprint |
| --- | --- | --- | --- |
| 40.1 | Done | - | - |
| 40.2 | Done | - | - |
| 40.3 | Done | - | - |
| 40.4 | Done | - | - |
| 40.5 | Done | - | - |
| 40.6 | Done | - | - |
| 40.7 | Done | - | - |
| 40.8 | Done | - | - |

## 4. Story Dependencies and Complexity

| Story | Depends On | Complexity |
| --- | --- | --- |
| 40.1 | Existing `InventoryMovement`, `BranchInventory`, `InventoryService` | Large |
| 40.2 | 40.1, existing `UnitConversionResolver` | Medium |
| 40.3 | 40.1, 40.2, existing `InventoryVarianceLog` | Large |
| 40.4 | 40.2, 40.3, existing `ProductRecipe` | Very Large |
| 40.5 | 40.1, 40.3, existing stocktake workflow | Very Large |
| 40.6 | 40.1, 40.5, shared approval framework | Large |
| 40.7 | 40.1, 40.3, 40.4, 40.5 | Medium |
| 40.8 | 40.1 through 40.7 | Medium |

## 5. Common Definition of Done

Every story is done when:

1. Acceptance criteria pass.
2. Required backend feature tests pass.
3. Required frontend tests pass where UI is touched.
4. Tenant and branch isolation are verified.
5. Inventory movements or variance records are append-only where required.
6. Current stock can be reconciled to source evidence.
7. Relevant audit events are verified.
8. No architecture constraints are violated.
9. Code review is approved.
10. Documentation or story notes are updated.
11. Database migrations include indexes, foreign-key behavior, and rollback verification.
12. Mutation endpoints enforce authorization and idempotency where applicable.
13. No offline mutation path is introduced.
14. Reporting surfaces preserve permission and branch boundaries.

## 6. Story 40.1 Inventory Evidence and Movement Ledger Hardening

Objective:

Harden inventory movement evidence and current-stock reconciliation rules.

Deliverables:

1. Movement type inventory and approved vocabulary review.
2. Movement source-reference requirements.
3. Branch-scoped `movement_sequence` contract.
4. `quantity_before`, signed `quantity_delta`, and `quantity_after` snapshot requirements.
5. Base/source unit fields and conversion snapshot requirements.
6. Current-stock reconciliation query.
7. Source-event idempotency review for sale deduction, void reversal, refund return, and opening balance.
8. Opening-balance source type.
9. System reconciliation variance detection.
10. Feature tests for movement evidence.

Out of scope:

1. Unit conversion changes.
2. Stocktake posting changes.
3. Procurement automation.

Acceptance checks:

1. Current stock changes have movement evidence.
2. Sale deduction replay is idempotent.
3. Void and refund restoration do not over-restore.
4. Movement records remain tenant and branch scoped.
5. Movement sequence is deterministic and unique by tenant and branch.
6. Before, delta, and after quantities are preserved and not recomputed.
7. Movement-derived stock reconciles to operational current stock.

## 7. Story 40.2 Unit Conversion Governance

Objective:

Make unit conversion behavior deterministic, visible, and auditable.

Deliverables:

1. Conversion lookup contract.
2. Inventory base unit, recipe unit, and purchase unit role model.
3. Product-specific override validation.
4. Tenant-wide fallback validation.
5. Dimension compatibility checks.
6. Immutable conversion versioning.
7. Conversion rule resolution source.
8. Strict failure behavior for unknown conversions.
9. Conversion snapshot shape for deductions.

Out of scope:

1. New recipe editor UX.
2. Procurement workflows.
3. Offline conversion sync.

Acceptance checks:

1. Product-specific conversion wins over tenant-wide conversion.
2. Missing conversion fails closed in strict deduction paths.
3. Metric fallback remains deterministic.
4. Deduction snapshots capture conversion evidence.
5. Historical movement snapshots keep the original conversion version.
6. Ratio changes create new conversion versions instead of rewriting used rules.

## 8. Story 40.3 Negative Stock Exception and Resolution Lifecycle

Objective:

Formalize negative-stock exception evidence and follow-up lifecycle.

Deliverables:

1. Variance source snapshot contract.
2. Negative stock variance report hardening.
3. Physical count variance distinction.
4. System reconciliation variance distinction.
5. Configuration variance distinction.
6. Variance status model if needed.
7. Audit events for soft-negative deduction.
8. Tests for strict and soft deduction policies.

Out of scope:

1. Automatic stock correction.
2. Accounting liability.
3. Procurement purchase order creation.

Acceptance checks:

1. Strict policy blocks insufficient stock.
2. Soft policy creates variance evidence.
3. Variance records are immutable.
4. Variance reports preserve branch permissions.
5. No variance row directly changes stock.
6. System reconciliation exceptions are not treated as ordinary stock shortages.

## 9. Story 40.4 Recipe Deduction Snapshot Integrity

Objective:

Ensure recipe-based ingredient deductions are explainable and replay-safe.

Deliverables:

1. Recipe deduction snapshot shape.
2. Parent product and ingredient source linkage.
3. `RecipeDeductionResult` contract.
4. Recipe and conversion version snapshots.
5. Complete ingredient explosion evidence.
6. Conversion evidence in recipe deductions.
7. Recipe deduction idempotency checks.
8. Tests for mixed direct and recipe deductions.

Out of scope:

1. Recursive recipe deduction.
2. Recipe editor redesign.
3. Cost accounting.

Acceptance checks:

1. Ingredient deductions reference parent product.
2. Conversion evidence is preserved.
3. Missing ingredients fail closed unless a policy explicitly permits variance.
4. Replayed sale deduction does not duplicate movement rows.
5. Recipe changes after sale commitment do not alter historical ingredient deductions.
6. Ingredient lines preserve recipe quantity, stock unit, resolved quantity, stock before and stock after.

## 10. Story 40.5 Stocktake Reconciliation Integration

Objective:

Align stocktake posting with canonical movement and variance evidence.

Deliverables:

1. Stocktake posting evidence review.
2. Stocktake movement watermark.
3. Expected-at-count-start quantity.
4. Movement-during-count handling.
5. Expected-at-posting quantity.
6. Stock correction movement contract.
7. Posted stocktake audit payload.
8. Reconciliation report between stocktake lines and current stock.
9. Tests for posted session immutability and movement output.

Out of scope:

1. New mobile counting UX.
2. Barcode scanner integration.
3. Procurement receiving changes.

Acceptance checks:

1. Posting creates controlled correction evidence.
2. Posted sessions cannot be silently mutated.
3. Current stock after posting matches posted evidence.
4. Branch and tenant isolation are enforced.
5. Stocktake lines preserve the movement watermark used for reconciliation.
6. Activity during count is either blocked by policy or reconciled through the movement watermark.

## 11. Story 40.6 Inventory Adjustment Authorization

Objective:

Harden manual stock adjustment authorization, reasons, and audit evidence.

Deliverables:

1. Adjustment reason policy.
2. Direction-aware adjustment reason catalog.
3. Opening-balance special handling.
4. Approval framework integration for high-risk adjustments.
5. Quantity and optional value thresholds.
6. Adjustment source snapshot.
7. Before/after movement snapshot.
8. Tests for unauthorized and cross-branch adjustment attempts.

Out of scope:

1. Accounting journal posting.
2. Supplier return workflow changes.
3. Stocktake posting changes.

Acceptance checks:

1. Manual adjustments require reason evidence.
2. High-risk adjustments require approval if configured.
3. Adjustment movement rows remain append-only.
4. Unauthorized adjustments are blocked.
5. Signed quantity is constrained by the selected reason direction policy.
6. Opening balance is only allowed before prior committed branch/product movements exist.

## 12. Story 40.7 Inventory Reporting and Audit Evidence

Objective:

Unify inventory reports around movement, variance, and reconciliation evidence.

Deliverables:

1. Current Stock Report.
2. Stock Card Report.
3. Movement Summary Report.
4. Negative Stock Exception Report.
5. Physical Count Variance Report.
6. System Reconciliation Exception Report.
7. Expected versus Recorded Inventory Usage foundation.
8. Configuration Gap Report.
9. Inventory Integrity Exception Report.
10. Report as-of watermarks and consistency metadata.
11. CSV/export parity, permissions, and scope limits.

Out of scope:

1. New accounting reports.
2. Procurement automation.
3. Forecasting.

Acceptance checks:

1. Reports distinguish current stock, stock cards, movement summaries, variances, and stocktake corrections.
2. Exports match screen filters and permissions.
3. No report mutates inventory.
4. Branch-limited users cannot see unassigned branch inventory.
5. Expected sale-driven usage is reported only when independent immutable expected evidence exists.
6. Recorded sale-driven usage comes from committed inventory movements.
7. Expected usage is marked unavailable when independent evidence is missing.
8. Current recipe definitions are never used to reinterpret historical sales.
9. Non-sale inventory effects remain separately classified and are not presented as physical consumption.
10. Business-date Movement Summary opening and closing balances are derived from an authoritative baseline plus signed movements through the captured branch watermark. It must not select a historical `quantity_after` solely by business date because late-posted movements can make sequence history and business-date order differ.

## 13. Story 40.8 Pilot UAT and Operational Recovery

Objective:

Validate the hardened inventory lifecycle against controlled UAT, pilot governance, operational recovery, and post-activation hypercare.

Deliverables:

1. UAT checklist.
2. Pilot entry criteria and scope record.
3. Controlled UAT versus live pilot stage definitions.
4. Defect severity, disposition, waiver, and retest rules.
5. Go/no-go, signoff, hypercare, and exit criteria.
6. Operational containment and recovery playbook.
7. Support diagnostics checklist.
8. Replay validation for sale deductions and partial refunds.
9. Offline sale synchronization validation.
10. Stocktake activity-during-count validation.
11. Adjustment approval denial validation.
12. Movement/current-stock mismatch detection.
13. Recipe ingredient lineage investigation.
14. Evidence manifest and privacy rules.
15. Hypercare thresholds and stop triggers.
16. Documentation updates.

Out of scope:

1. New runtime features.
2. Production deployment automation.
3. External ERP integration.
4. Deleting, rewriting, or directly rolling back committed inventory evidence.

Acceptance checks:

1. Pilot walkthrough covers sale deduction, refund return, void reversal, stocktake posting, and variance review.
2. Pilot entry, go/no-go, and exit criteria are explicit.
3. Severity 1 and Severity 2 defects block rollout.
4. Exact replay validation proves no movement, stock, revision, approval, or exception drift.
5. Offline sale synchronization remains server-authoritative and idempotent.
6. Recovery steps route users through governed workflows and classified containment.
7. Containment modes identify whether they are system-enforced, feature-flag-enforced, procedural, or unavailable.
8. Hypercare monitoring includes thresholds and immediate stop conditions.
9. User guide reflects implemented behavior.
10. Support diagnostics identify source evidence for inventory mismatches.
