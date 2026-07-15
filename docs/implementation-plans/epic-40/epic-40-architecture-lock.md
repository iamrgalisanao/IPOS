# Epic 40 Inventory Operational Control and Reconciliation Architecture Lock

## 1. Status

Draft for Review

Date: 2026-07-15

## 2. Purpose

Define the non-negotiable architecture for hardening IPOS inventory operations.

Epic 40 builds on existing inventory foundations:

1. `InventoryService`
2. `BranchInventory`
3. `InventoryMovement`
4. `InventoryVarianceLog`
5. `UnitConversion`
6. `UnitConversionResolver`
7. `ProductRecipe`
8. Stocktake sessions and stocktake posting
9. Inventory visibility and variance reporting

The epic should improve reliability and reconciliation without turning inventory into procurement, accounting, or sales authority.

## 3. Domain Boundaries

### Inventory Owns

1. Current branch stock state.
2. Inventory movement history.
3. Unit conversion resolution.
4. Negative stock variance evidence.
5. Stocktake reconciliation evidence.
6. Inventory operational reports.

### Inventory Does Not Own

1. Sale creation.
2. Payment settlement.
3. Refund authority.
4. Accounting liability.
5. Procurement purchase-order approval.
6. Product pricing.
7. Tax or receipt compliance.

## 4. Canonical Records

Canonical inventory evidence:

1. `inventory_movements`
2. `inventory_variance_logs`
3. `stocktake_sessions`
4. `stocktake_lines`
5. `branch_inventories`
6. `unit_conversions`
7. `product_recipes`

Rule:

`branch_inventories.current_stock` is operational state. It must be explainable by movement and reconciliation records.

## 5. Movement Invariants

1. Inventory movements are append-only.
2. Every committed stock movement must preserve:
   - branch-scoped `movement_sequence`,
   - `quantity_before`,
   - signed `quantity_delta`,
   - `quantity_after`,
   - `base_unit_id`,
   - `source_unit_id`,
   - `source_quantity`,
   - `conversion_snapshot`,
   - `source_type`,
   - `source_id`,
   - `source_reference`,
   - `business_date`,
   - `posted_at`.
3. Historical before and after quantities must not be recomputed from current stock.
4. Movement quantity signs must remain deterministic:
   - stock in: positive
   - sale deduction: negative
   - refund return: positive
   - void reversal: positive
   - manual adjustment: signed by intent
5. Every movement must have tenant and branch scope.
6. Movement sequence is unique by tenant and branch.
7. Every movement caused by a sale, void, refund, stocktake, or adjustment must reference the source.
8. Movement history must not be deleted to hide operational mistakes.

## 6. Stock State Invariants

1. Current stock cannot be changed without corresponding movement or reconciliation evidence.
2. Strict deduction policy blocks insufficient stock.
3. Soft-negative deduction policy permits negative stock only with variance evidence.
4. Missing inventory records are configuration errors unless a story explicitly introduces controlled auto-initialization.
5. Stocktake posting must create controlled correction evidence.

## 7. Unit Conversion Invariants

1. Every inventory item has exactly one base stock unit.
2. Recipe quantities resolve into the base stock unit.
3. Purchase units may be defined, but procurement behavior remains out of scope for Epic 40.
4. Each alternate unit identifies a conversion to the base stock unit.
5. Incompatible unit dimensions are prohibited.
6. The base-unit conversion factor is exactly one.
7. Unit conversion is tenant-scoped.
8. Product-specific conversion overrides tenant-wide conversion.
9. Metric fallback is allowed only for known compatible metric units.
10. Unknown conversions fail closed in strict deduction paths.
11. Conversion snapshots must be captured when deduction depends on a conversion rule.
12. A conversion rule already referenced by a committed inventory movement must not be materially edited.
13. A changed conversion ratio creates a new rule version.
14. Conversion snapshots must preserve:
   - `conversion_rule_id`,
   - `conversion_rule_version`,
   - `conversion_schema_version`,
   - source unit,
   - target unit,
   - numerator,
   - denominator,
   - resolved quantity,
   - resolution source.

## 8. Variance Invariants

1. Variance logs are immutable.
2. Variance categories must remain distinct:
   - negative stock variance,
   - physical count variance,
   - system reconciliation variance,
   - configuration variance.
3. Variance logs do not correct stock by themselves.
4. Variance logs must include source sale, stocktake, product or ingredient, required quantity, available quantity, resulting quantity, and policy.
5. Variance reporting must preserve branch and tenant boundaries.
6. Only negative stock variance may arise from soft sale deduction.
7. System reconciliation variance is a system-integrity exception, not an ordinary operational variance.
8. Physical stock variance is the difference between real-world count and system expected stock.

## 9. Stocktake Invariants

1. Stocktake sessions are controlled workflows.
2. Count submission does not equal stock correction.
3. Posting is the reconciliation event.
4. Posted stocktake evidence must remain auditable.
5. Stocktake posting must not erase original movement history.
6. Every stocktake line must identify the inventory movement watermark against which the physical count was reconciled.
7. Stocktake reconciliation must distinguish:
   - expected quantity at count start,
   - counted quantity,
   - movements during count,
   - expected quantity at posting,
   - posted variance.

## 10. POS Integration

1. Sale inventory deduction occurs through the existing inventory deduction path.
2. Inventory deduction must be idempotent by sale source.
3. Strict sale deduction requires sale commit and inventory movements in the same transaction.
4. Soft-negative sale deduction requires sale commit, movements, and variance evidence in the same transaction.
5. Insufficient stock does not fail if branch policy allows soft negative, but movement and variance posting remain atomic.
6. Asynchronous deduction is prohibited unless a future architecture revision defines durable source events, retry, visible pending state, idempotency, and reconciliation alarms.
7. Void reversal must not over-restore inventory.
8. Refund return must not over-restore inventory.
9. Refund and void restoration movements must participate in the owning refund or void transaction unless an approved asynchronous inventory workflow exists.
10. Dining, loyalty, store credit, and payments must not write inventory directly.

## 11. Reporting

Inventory reporting must distinguish:

1. operational current stock,
2. stock cards,
3. movement summaries,
4. movement evidence,
5. variance evidence,
6. stocktake reconciliation,
7. theoretical versus actual consumption,
8. setup/configuration gaps.

Stock cards are one row per committed movement, ordered by branch movement sequence, and must show before, delta, after, and source reference.

Movement summaries aggregate beginning stock, total in, total out, and ending stock by product, branch, and period.

Reports must not invent accounting conclusions.

## 12. Reconciliation Equations

Current stock must remain explainable:

```text
current_stock
=
opening_balance
+ stock_in_movements
- stock_out_movements
+ stocktake_corrections
+ manual_adjustments
```

For a reporting period:

```text
closing_stock
=
opening_stock
+ period_in
- period_out
```

System reconciliation variance:

```text
system_reconciliation_variance
=
branch_inventory.current_stock
- movement_derived_stock
```

Expected invariant:

```text
system_reconciliation_variance = 0
```

Physical stock variance:

```text
physical_count_variance
=
counted_stock
- system_expected_stock
```

## 13. Recipe Deduction Evidence

Recipe deductions must preserve a complete ingredient explosion result.

Each committed deduction must identify:

1. sale,
2. sale item,
3. parent product,
4. recipe and recipe version,
5. sold quantity,
6. ingredient lines,
7. conversion evidence,
8. stock before and after for each ingredient movement.

Recipe changes after sale commitment must never alter or regenerate historical ingredient deductions.

## 14. Adjustment Reason Governance

Manual adjustment reasons must be structured and direction-aware.

Each reason must define:

1. code,
2. name,
3. direction policy,
4. whether notes are required,
5. whether approval is required,
6. quantity threshold for approval,
7. optional value threshold for approval,
8. active state.

Opening balance is a special source type. It may only be used when no prior committed movement exists for the branch and product.

## 15. Offline Policy

First-release Epic 40 inventory mutation remains online-authoritative.

Offline inventory mutation is prohibited unless a future story explicitly defines queueing, conflict detection, and reconciliation.

## 16. Architecture Constraints

The following constraints may not be violated by future stories unless this document is revised:

1. Inventory movement history remains append-only.
2. Current stock changes require movement or reconciliation evidence.
3. Unit conversion resolution is deterministic and tenant-scoped.
4. Negative stock requires explicit branch policy and variance evidence.
5. Stocktake posting is the only stocktake correction authority.
6. POS sales, voids, and refunds integrate through aggregate services and must not write inventory tables directly from controllers.
7. Inventory reports are evidence projections, not mutation surfaces.
8. Inventory does not create accounting liability.
9. Procurement may consume inventory signals but must not be hidden inside inventory hardening stories.
10. Offline inventory mutation remains prohibited in this epic.
11. Movement before, delta, and after quantities are immutable historical evidence.
12. Branch-scoped movement sequences are the ordering authority for stock cards and stocktake watermarks.
13. Conversion rules referenced by movements are versioned, not overwritten.
14. Stock cards and movement summaries are separate report contracts.
15. Opening balance must not be reused as a generic adjustment reason.
