# Story 40.4 Recipe Deduction Snapshot Integrity

## 1. Status

Done

Date: 2026-07-16

## 2. Objective

Ensure recipe-based ingredient deductions are explainable and replay-safe.

Story 40.4 strengthens the existing POS recipe deduction path so every ingredient movement can be traced back to:

1. The committed sale.
2. The specific sale item.
3. The parent product sold.
4. The recipe line that produced the deduction.
5. The ingredient product deducted.
6. The conversion rule or metric identity used.
7. The stock state before and after the deduction.
8. Any negative-stock exception created by Story 40.3.

Recipe changes after sale commitment must never alter historical deduction evidence.

## 3. User Story

As an inventory controller,
I want recipe ingredient deductions to preserve complete parent-product, recipe-line, conversion, and movement evidence,
so that historical sales can be replayed, audited, and reconciled even after recipes or unit conversion rules change.

## 4. Architecture Alignment

This story implements the recipe deduction requirements from:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Inventory movements remain append-only.
2. Ingredient deductions are stock movements, not sales records.
3. Sales, payments, receipts, taxes, and compliance records remain owned by the sales checkout flow.
4. Recipe deduction may consume sale and sale item snapshots, but it must not recalculate sale totals.
5. Unit conversion must use Story 40.2 resolution and snapshot rules.
6. Negative-stock behavior must use Story 40.3 policy and exception lifecycle.
7. Missing conversion rules fail closed.
8. Missing branch inventory for an ingredient is a configuration error and fails closed.
9. Insufficient ingredient stock follows the branch deduction policy.
10. Recipe snapshots must be preserved on movement evidence.
11. Recipe edits must create new versioned evidence instead of rewriting historical recipe lines.
12. Recursive recipe deduction remains out of scope.
13. Offline inventory mutation remains prohibited.

## 5. Existing Implementation Context

Current files and behavior that must be respected:

| Area | Current File | Current Behavior |
| --- | --- | --- |
| Sale deduction entry point | `app/Services/InventoryService.php` | `deductFromSale()` loops sale items, deducts direct products or recipe components, and writes movements. |
| Recipe line model | `app/Models/ProductRecipe.php` | Stores tenant, parent product, ingredient, quantity, and unit. |
| Recipe table | `database/migrations/2026_05_16_133306_create_product_recipes_table.php` | Enforces one ingredient row per parent product and has no version or snapshot fields yet. |
| Movement writer | `app/Services/Inventory/InventoryMovementRecorder.php` | Provides source-effect idempotency and replay drift checks. |
| Conversion resolver | `app/Services/Inventory/UnitConversionResolver.php` | Resolves direct, inverse, identity, and metric conversion paths and returns conversion snapshots. |
| Negative-stock exceptions | `app/Services/Inventory/NegativeStockExceptionService.php` | Creates movement-linked exception evidence under `allow_negative_with_warning`. |
| Existing tests | `tests/Feature/POS/InventoryDeductionPolicyTest.php` | Covers recipe shortage, conversion rules, conversion precedence, inactive conversions, unknown conversions, and negative-stock exception behavior. |
| Composition report | `tests/Feature/Inventory/ProductCompositionReportTest.php` | Documents direct recipe rows and nested subrecipe planning semantics. |

Important current gaps:

1. `deductFromSale()` has a sale-level idempotency guard. This is too coarse for recipe evidence and can hide partial/incomplete source effects.
2. Recipe ingredient movement keys currently identify sale item and ingredient, but not the specific recipe line.
3. Duplicate ingredient lines or future recipe versioning could collide without line-level source identity.
4. Recipe lines do not have stable UUID/version/schema evidence separate from row IDs.
5. The deduction flow mutates ingredient stock as each recipe component is processed, so a later component failure can risk partial work if not kept inside a verified atomic plan.
6. Recipe movement metadata does not yet preserve the complete ingredient explosion snapshot.
7. Negative-stock exception source snapshots do not yet include recipe-line version evidence.
8. Recipe edits are not yet governed as historical evidence changes.

## 6. Competitive Research Classification

Publicly visible behavior from comparable POS inventory systems supports the baseline requirement that finished-product sales can deduct configured ingredient quantities. Public materials also support keeping recipe setup, stock movements, adjustments, physical counts, and stock reports as separate operational concepts.

Competitor-validated requirements:

1. A sold parent item can deduct configured ingredient quantities.
2. Ingredient quantities may be fractional.
3. Ingredient deduction is operationally linked to the sold product.
4. Ingredient deduction must remain separate from stock correction and stocktake workflows.
5. Operator-facing recipe setup should remain simple.
6. Modifier-driven ingredient effects are a relevant future requirement.

IPOS integrity enhancements:

1. Stable recipe-line UUIDs.
2. Append-only recipe versions.
3. Deterministic recipe batch correlation.
4. Plan-before-mutate validation.
5. Line-level source-effect keys.
6. Replay drift detection.
7. Immutable recipe snapshots on movement rows.
8. Deterministic ingredient-row locking.
9. Atomic all-ingredient deduction.

These IPOS enhancements should not be described as competitor parity. They exist to satisfy IPOS movement-ledger, audit, replay, and historical explainability requirements.

## 7. Scope

### In Scope

1. Add stable recipe line identity and version evidence.
2. Define the recipe deduction snapshot schema.
3. Define the `RecipeDeductionResult` contract.
4. Preserve parent product, sale item, recipe line, ingredient product, and conversion evidence on each ingredient movement.
5. Ensure recipe deduction uses line-level source-effect idempotency.
6. Plan recipe deductions before applying stock mutations.
7. Integrate recipe snapshots with Story 40.3 negative-stock exception evidence.
8. Ensure replay detects drift and never duplicates movement rows.
9. Add tests for mixed direct and recipe deductions.
10. Add tests for duplicate ingredient-line safety.
11. Add tests for recipe and conversion changes after sale commitment.

### Out of Scope

1. Recursive recipe deduction.
2. Recipe editor redesign.
3. Nested subrecipe live POS deduction.
4. Recipe costing.
5. Cost accounting or COGS posting.
6. Yield, trim loss, spoilage, density, or production output modeling.
7. Procurement receiving.
8. Stocktake posting changes.
9. Offline inventory mutation.

## 8. Locked Decisions

### 8.1 Direct Recipe Components Only

Live POS deduction remains direct-only in this story.

Approved:

```text
Parent sale item
        ↓
Direct product_recipes rows
        ↓
Ingredient inventory movements
```

Not approved:

```text
Parent sale item
        ↓
Subrecipe
        ↓
Subrecipe ingredient explosion
        ↓
Live POS deduction
```

Rules:

1. Composition reports may show planning-only flattened subrecipes.
2. POS live deduction must deduct only the direct recipe lines attached to the sold parent product.
3. Any product with nested recipes must wait for a future recursive deduction story before live nested explosion is allowed.

### 8.2 One Movement Per Recipe Line

Each active recipe line that produces an ingredient deduction must create its own movement effect.

Rules:

1. Do not aggregate multiple recipe lines into one movement in Story 40.4.
2. If the same ingredient appears in multiple recipe lines, each line gets its own movement.
3. The source-effect key must include recipe-line identity.
4. This preserves exact line-level recipe evidence and avoids ambiguous replay.

Canonical source-effect key:

```text
sale:{sale_id}:sale_item:{sale_item_id}:recipe_line:{recipe_line_uuid}:ingredient:{ingredient_product_id}
```

Direct product deduction remains:

```text
sale:{sale_id}:sale_item:{sale_item_id}:product:{product_id}
```

### 8.3 Plan Before Mutate

Recipe deduction must plan the full ingredient explosion before applying any ingredient stock movement.

Required order:

```text
Load sale item
        ↓
Load active recipe lines
        ↓
Snapshot parent product and recipe lines
        ↓
Resolve all unit conversions
        ↓
Verify all ingredient branch inventories exist
        ↓
Lock ingredient inventories in deterministic order
        ↓
Apply movements
        ↓
Create negative-stock exceptions if policy permits
        ↓
Commit
```

Failure rules:

1. Missing recipe conversion fails before any movement is written.
2. Missing branch inventory fails before any movement is written.
3. Strict-policy insufficient ingredient stock fails before any movement is written.
4. Soft-negative insufficient stock may write movement and exception evidence, but both must commit atomically.
5. No partial ingredient explosion may be committed.

### 8.4 Recipe Versioning Is Evidence, Not Costing

Recipe versioning exists to explain historical deductions.

It does not introduce:

1. Recipe costing.
2. Production planning.
3. Yield management.
4. Accounting valuation.
5. Procurement planning.

Material recipe-line edits must create new version evidence.

Identity semantics:

```text
recipe_line_uuid
=
logical line identity across versions

product_recipes.id
=
physical version-row identity
```

Material fields:

1. `quantity`.
2. `unit`.
3. Active/inactive state.

Non-material fields:

1. Internal remarks.
2. Display ordering, unless ordering is included in the source-effect key.

Ingredient replacement rule:

1. Changing quantity keeps the same `recipe_line_uuid`, creates a new row, increments `recipe_version`, and sets `supersedes_recipe_id`.
2. Changing unit keeps the same `recipe_line_uuid`, creates a new row, increments `recipe_version`, and sets `supersedes_recipe_id`.
3. Changing ingredient product ends the old logical line and creates a new `recipe_line_uuid`.
4. Activating or deactivating a line appends lifecycle/version evidence.
5. Remarks may update in place when they are not part of deduction evidence.

### 8.5 Recipe Changes Do Not Rewrite History

Once a sale deduction commits, historical movement evidence must remain independent of current recipe configuration.

Rules:

1. Existing movements keep their original recipe snapshot.
2. Existing movements keep their original conversion snapshot.
3. Updating a recipe after the sale creates new recipe version evidence for future sales only.
4. Replaying an already-deducted sale must not use the current recipe to reinterpret historical movement rows.

### 8.6 Negative Stock Remains Movement-Linked

Story 40.4 does not change the negative-stock lifecycle.

Rules:

1. Recipe deductions use the same branch policy as direct product deductions.
2. Strict policy blocks insufficient ingredient stock.
3. Soft-negative policy creates one negative-stock exception per negative ingredient movement effect.
4. The exception source snapshot must include recipe-line evidence.
5. Exception resolution still happens through Story 40.3 lifecycle workflows, not through recipe deduction.

### 8.7 Modifier Context Is Reserved

Story 40.4 records parent-product recipe deductions only, but the snapshot contract must remain extensible for future modifier-driven ingredient effects.

Rules:

1. `configuration_source` must be present in recipe snapshots.
2. Story 40.4 uses `configuration_source = parent_recipe`.
3. `modifier_context` must be nullable in the snapshot.
4. Future stories may use `configuration_source = modifier_recipe`, `variant_recipe`, or `production_recipe`.
5. Story 40.4 does not implement modifier ingredient deduction unless existing checkout already produces those ingredient effects.
6. `production_recipe` is reserved for a future production-planning workflow and must not be implemented in Story 40.4.

### 8.8 No Failed Canonical Deduction Records

Failed recipe plans are not inventory evidence.

Rules:

1. Failed validation may write application diagnostics or audit events.
2. Failed validation must not create inventory movements.
3. Failed validation must not create negative-stock exceptions.
4. Failed validation must not create a canonical recipe-deduction batch record.
5. Failure diagnostics should include sale, sale item, parent product, failed recipe line, failure code, and validation reason.

### 8.9 Direct Ingredient Stock Mode

Story 40.4 supports only direct ingredient stock deduction.

If an active direct ingredient is itself configured as a recipe or production item, checkout must either:

1. Treat it as an ordinary stocked ingredient when its inventory mode is `stock_directly`; or
2. Reject the configuration when live explosion would be required.

Future mode:

```text
ingredient_stock_mode:
- stock_directly
- explode_recipe_future
```

Story 40.4 supports only `stock_directly`.

## 9. Data Model Requirements

### 9.1 Product Recipe Version Evidence

Additive migration should extend `product_recipes` with stable evidence fields.

Recommended fields:

| Field | Purpose |
| --- | --- |
| `recipe_line_uuid` | Stable public identity for the logical recipe line. Backfilled for existing rows. |
| `recipe_schema_version` | Snapshot schema version. Start with `1`. |
| `recipe_version` | Monotonic version for the logical recipe line. Start with `1`. |
| `is_active` | Determines whether the line participates in live deduction. |
| `active_slot` | Portable active uniqueness helper. Use `1` for active, `0` or unique historical value for inactive rows. |
| `supersedes_recipe_id` | Links a new version to the previous row when a material edit occurs. |
| `locked_at` | Optional marker set once a recipe line has been used in committed movement evidence. |
| `metadata` | Optional non-authoritative operational notes. |

Portable active uniqueness:

```text
tenant_id
product_id
ingredient_id
active_slot
```

Rules:

1. Only one active recipe line for the same parent product and ingredient is allowed in the first release.
2. If duplicate same-ingredient lines are needed later, introduce an explicit `line_role` or `line_sequence` and include it in uniqueness and source-effect identity.
3. Existing rows should be backfilled as version `1`, schema version `1`, active.
4. Historical inactive rows must remain available for movement references.
5. Ingredient replacement must deactivate the old logical line and create a new logical `recipe_line_uuid`.
6. Quantity and unit edits must retain the logical `recipe_line_uuid` and create a new physical version row.

### 9.2 Inventory Movement Recipe Snapshot

Recipe deduction movements must persist recipe evidence without requiring current recipe rows to be re-read.

Recommended storage:

1. Keep canonical stock quantities in existing inventory movement quantity fields.
2. Store recipe-specific evidence in `inventory_movements.metadata.recipe_deduction_snapshot`.
3. Add indexed nullable lineage columns for high-value query fields.

Recommended movement lineage columns:

1. `sale_item_id`.
2. `parent_product_id`.
3. `recipe_line_uuid`.
4. `recipe_batch_uuid`.

Do not add every snapshot field as a column. The complete immutable payload remains in JSON metadata.

Required recipe deduction snapshot:

```json
{
  "schema_version": 1,
  "recipe_batch_uuid": "uuid",
  "configuration_source": "parent_recipe",
  "modifier_context": null,
  "sale_id": "uuid",
  "sale_number": "S-0001",
  "sale_item_id": "uuid",
  "parent_product_id": "uuid",
  "parent_product_snapshot": {
    "name": "Burger",
    "sku": "BRG-001",
    "sold_quantity": "2.0000"
  },
  "recipe_line": {
    "id": "uuid",
    "recipe_line_uuid": "uuid",
    "recipe_schema_version": 1,
    "recipe_version": 1,
    "ingredient_id": "uuid",
    "quantity": "2.0000",
    "unit": "gram"
  },
  "ingredient_snapshot": {
    "product_id": "uuid",
    "name": "Sauce",
    "sku": "SAUCE-001",
    "base_stock_unit": "ml"
  },
  "deduction": {
    "recipe_quantity_per_parent": "2.0000",
    "parent_quantity": "2.0000",
    "source_quantity": "4.0000",
    "source_unit": "gram",
    "resolved_quantity": "6.0000",
    "base_unit": "ml",
    "rounding_mode": "half_up",
    "quantity_before": "10.0000",
    "quantity_delta": "-6.0000",
    "quantity_after": "4.0000"
  },
  "conversion_snapshot": {},
  "source_effect_key": "sale:...:recipe_line:...:ingredient:..."
}
```

Rules:

1. Snapshot values must be strings when precision matters.
2. Snapshot must include both source recipe quantity and resolved base stock quantity.
3. Snapshot must include the conversion snapshot returned by Story 40.2.
4. Snapshot must not depend on mutable current recipe or conversion rows for historical replay.
5. `recipe_batch_uuid` is deterministic correlation evidence, not a new aggregate table in Story 40.4.
6. `configuration_source` must be `parent_recipe` in Story 40.4.
7. `modifier_context` must be nullable and schema-stable for future modifier recipe stories.

### 9.3 Recipe Batch Correlation

Story 40.4 must not introduce a persisted recipe batch aggregate table.

`recipe_batch_uuid` is used only as deterministic correlation evidence in:

1. Ingredient movement snapshots.
2. `RecipeDeductionResult`.
3. Audit payloads.
4. Negative-stock exception evidence.

Recommended identity:

```text
recipe_batch_uuid
=
deterministic UUID derived from tenant_id + sale_id + sale_item_id
```

Alternative:

```text
recipe_batch_uuid
=
UUID generated once by sale inventory-deduction orchestration and reused on replay
```

It must not change on exact replay.

### 9.4 Negative Stock Exception Source Snapshot

When recipe deduction creates a negative-stock exception, the exception source snapshot must include:

1. `recipe_batch_uuid`.
2. `recipe_line_uuid`.
3. `recipe_version`.
4. `parent_product_id`.
5. `sale_item_id`.
6. `ingredient_product_id`.
7. `conversion_snapshot`.
8. `movement_id`.
9. `source_effect_key`.
10. `configuration_source`.
11. `modifier_context`.

## 10. Service Design

### 10.1 Recipe Deduction Service

Introduce a dedicated recipe service instead of expanding `InventoryService` directly.

Recommended class:

```text
App\Services\Inventory\RecipeDeductionService
```

Responsibilities:

1. `plan()`: build a `RecipeDeductionPlan`.
2. `validatePlan()`: validate recipe configuration, conversions, inventory rows, and strict-policy availability.
3. `recordPlan()`: write movements, negative-stock exceptions, and audit evidence atomically.
4. Return `RecipeDeductionResult`.

`InventoryService::deductFromSale()` remains the entry point used by POS checkout, but it delegates recipe sale items to the recipe service.

Extract a separate `RecipeDeductionPlanner` only if implementation complexity warrants it.

### 10.2 RecipeDeductionResult Contract

`RecipeDeductionResult` should be a typed DTO or value object.

Required fields:

| Field | Meaning |
| --- | --- |
| `recipe_batch_uuid` | Groups all recipe movements for one sale item. |
| `sale_id` | Source sale. |
| `sale_item_id` | Source sale item. |
| `parent_product_id` | Sold composite product. |
| `parent_quantity` | Sold quantity. |
| `lines` | Collection of ingredient deduction line results. |
| `replayed` | True only when all expected source effects already existed and matched. |
| `movement_ids` | Movements created or replayed. |
| `negative_stock_exception_ids` | Exception rows created or replayed. |

Line result fields:

| Field | Meaning |
| --- | --- |
| `recipe_line_id` | Current row ID referenced at deduction time. |
| `recipe_line_uuid` | Stable logical recipe-line identity. |
| `recipe_version` | Version used for deduction. |
| `ingredient_product_id` | Ingredient product deducted. |
| `source_quantity` | Recipe quantity multiplied by parent quantity. |
| `source_unit` | Recipe unit. |
| `resolved_quantity` | Quantity deducted from branch inventory. |
| `base_unit` | Ingredient stock unit. |
| `conversion_snapshot` | Story 40.2 conversion evidence. |
| `quantity_before` | Stock before movement. |
| `quantity_after` | Stock after movement. |
| `movement_id` | Inventory movement. |
| `source_effect_key` | Idempotency key. |
| `negative_stock_exception_id` | Optional exception linkage. |

### 10.3 Recipe Deduction Flow

Recommended flow for each recipe sale item:

```text
Start transaction
        ↓
Build recipe plan
        ↓
Resolve every conversion
        ↓
Verify every ingredient inventory exists
        ↓
Check strict-policy availability for every line
        ↓
Lock ingredient inventories by ingredient_product_id ASC, recipe_line_uuid ASC
        ↓
Record one movement per recipe line
        ↓
Create negative-stock exceptions where required
        ↓
Return RecipeDeductionResult
        ↓
Commit
```

Rules:

1. Plan validation happens before mutation.
2. Inventory row locking must use this exact order: `ingredient_product_id ASC`, then `recipe_line_uuid ASC`.
3. Existing exact source effects may be returned as replayed movements.
4. Drifted source effects must throw before writing additional movement rows.

## 11. Idempotency and Replay

### 11.1 Replace Sale-Level Early Return

The existing sale-level guard:

```text
if any movement exists for sale:
    return
```

must not remain the only idempotency behavior for recipe deductions.

Required behavior:

1. Direct product deductions remain idempotent by sale item and product source effect.
2. Recipe deductions are idempotent by sale item, recipe line, and ingredient source effect.
3. Exact replay returns existing matching movements.
4. Drifted replay is rejected.
5. Existing unrelated movement rows for the same sale must not suppress missing expected recipe movement rows.

### 11.2 Replay Drift Checks

Recipe replay drift must compare at least:

1. Movement type.
2. Ingredient product ID.
3. Quantity delta.
4. Source effect key.
5. Base unit.
6. Source unit.
7. Source quantity.
8. Resolved quantity.
9. Conversion snapshot identity.
10. Recipe line UUID.
11. Recipe version.
12. Parent product ID.
13. Sale item ID.
14. Recipe batch UUID.

Do not recompute historical `quantity_before` or `quantity_after` against current stock during replay. Those values remain immutable stored evidence, but later stock movements naturally change the current branch inventory state.

If any differ, throw a domain-specific exception such as:

```text
RecipeDeductionReplayDriftException
```

### 11.3 Partial Replay Policy

Partial replay is dangerous if the original recipe plan is not persisted separately from movement rows.

First-release rule:

1. If all expected source effects exist and match, return a replayed `RecipeDeductionResult`.
2. If no expected source effects exist, build from the current active recipe selected for the not-yet-deducted sale and post atomically.
3. If only some expected source effects exist, reject partial replay with `RecipeDeductionPartialReplayException`.
4. Do not attempt to repair missing lines automatically in Story 40.4.
5. Do not mix existing historical movement evidence with current recipe rows after a partial failure.

This prevents a sale from being completed under one recipe version and later partially completed under another.

## 12. Validation Rules

### 12.1 Recipe Configuration

Reject deduction when:

1. Parent product has inactive or missing recipe lines but is configured as recipe-deducted.
2. Recipe line quantity is zero or negative.
3. Recipe line unit is blank or unsupported.
4. Ingredient product is missing, inactive, cross-tenant, or not inventory tracked.
5. Ingredient branch inventory row is missing.
6. Required unit conversion cannot be resolved.
7. Recursive recipe deduction would be required.
8. Active duplicate ingredient rows exist for the same parent product and ingredient.
9. Ingredient stock mode requires future recursive explosion.

### 12.2 Branch Policy

Strict branch policy:

1. If any ingredient would go negative, reject the entire recipe deduction.
2. No movement rows should be committed.
3. No negative-stock exception should be committed.

Soft-negative branch policy:

1. Ingredient movements may go negative.
2. Each negative ingredient movement must create Story 40.3 exception evidence.
3. Movement and exception evidence must commit atomically.

## 13. API and UI Impact

This is primarily backend behavior.

Expected UI impact:

1. Inventory movement detail views may display recipe snapshot metadata.
2. Negative-stock exception detail views may display parent product and recipe-line context.
3. Product recipe editor may need version-aware persistence if edited through existing admin UI.
4. Normal recipe setup should remain ordinary create/edit/deactivate behavior; versioning is primarily a server-side evidence concern.

No new primary operator UI is required in Story 40.4.

## 14. Audit and Reporting

Audit events:

1. `inventory_recipe_deduction_planned`
2. `inventory_recipe_deduction_recorded`
3. `inventory_recipe_deduction_replayed`
4. `inventory_recipe_deduction_failed`
5. `inventory_recipe_deduction_drift_detected`

Audit payloads should include:

1. Tenant and branch.
2. Sale and sale item.
3. Parent product.
4. Recipe batch UUID.
5. Movement IDs.
6. Negative-stock exception IDs.
7. Failure reason when applicable.

Reporting requirements:

1. Story 40.7 stock cards must be able to show parent sale item and recipe source context.
2. Movement export must preserve recipe snapshot metadata or provide a detail endpoint.
3. Reports must not recompute historical recipe quantities from current recipe rows.
4. Reports should use indexed lineage columns for common filtering and JSON metadata for full evidence detail.

## 15. Error Model

Recommended domain exceptions:

| Exception | Meaning |
| --- | --- |
| `RecipeDeductionConfigurationException` | Recipe or ingredient setup is invalid. |
| `RecipeDeductionMissingInventoryException` | Ingredient branch inventory row is missing. |
| `RecipeDeductionConversionException` | Unit conversion cannot be resolved. |
| `RecipeDeductionInsufficientStockException` | Strict policy blocks deduction. |
| `RecipeDeductionReplayDriftException` | Existing source effect differs from expected evidence. |
| `RecipeDeductionPartialReplayException` | Partial source effects exist and first-release repair is intentionally unsupported. |

Error mapping should preserve existing POS checkout behavior while returning actionable messages for support diagnostics.

## 16. Implementation Slices

### Slice 1 - Recipe Evidence Foundation

Deliverables:

1. Add recipe line UUID/version/schema fields.
2. Define ingredient replacement as a new logical line.
3. Reject duplicate active ingredients.
4. Backfill existing recipe rows as version `1`.
5. Add portable active uniqueness.
6. Update `ProductRecipe` casts/fillable fields.
7. Add model/service guard for material edits.

### Slice 2 - Deduction Plan and Validation

Deliverables:

1. Create `RecipeDeductionService`.
2. Create `RecipeDeductionPlan`, `RecipeDeductionResult`, and line result DTOs.
3. Resolve all conversion evidence.
4. Validate all ingredient inventory rows.
5. Evaluate strict stock policy before mutation.
6. Detect unsupported recursive configuration.
7. Build deterministic source-effect keys.

### Slice 3 - Atomic Recording

Deliverables:

1. Create deterministic batch correlation.
2. Record one movement per recipe line.
3. Add hybrid query columns and full JSON snapshot.
4. Include recipe snapshot in negative-stock exception source snapshots.
5. Preserve existing direct product deduction behavior.

### Slice 4 - Replay and Drift

Deliverables:

1. Replace sale-level early return with source-effect replay behavior.
2. Validate source effects individually.
3. Reject partial replay in the first release.
4. Compare historical snapshot identity, not current stock before/after.
5. Add replay drift checks for recipe evidence.

### Slice 5 - Admin Version Behavior and Regression

Deliverables:

1. Convert material recipe edits into versions.
2. Ensure deleted/deactivated recipe lines remain historically referenceable.
3. Keep existing recipe editor workflows functional.
4. Add modifier-ready nullable snapshot context.
5. Add audit events for recipe version changes.
6. Run direct, recipe, mixed-cart, strict, soft-negative, conversion, and replay tests.

## 17. Acceptance Criteria

1. Ingredient deductions reference the parent product, sale item, recipe line, and ingredient product.
2. Recipe movement source-effect keys include recipe-line identity.
3. Conversion evidence is preserved for every ingredient movement.
4. Recipe snapshots are preserved on movement metadata.
5. Negative-stock exceptions created from recipe lines include recipe snapshot evidence.
6. Missing ingredient branch inventory fails closed before any recipe movement is committed.
7. Missing conversion fails closed before any recipe movement is committed.
8. Strict-policy insufficient ingredient stock fails closed before any recipe movement is committed.
9. Soft-negative policy creates movement and exception evidence atomically.
10. Replayed sale deduction does not duplicate movement rows.
11. Existing unrelated movements for the sale do not suppress missing expected recipe movements.
12. Drifted replay is rejected before mutation.
13. Recipe changes after sale commitment do not alter historical movement evidence.
14. Ingredient lines preserve recipe quantity, recipe unit, stock unit, resolved quantity, stock before, and stock after.
15. Direct product deduction behavior remains backward compatible.
16. No recursive live recipe deduction is introduced.
17. No cost accounting or recipe costing behavior is introduced.
18. Quantity or unit changes retain the logical `recipe_line_uuid`, increment `recipe_version`, and reference the previous physical row.
19. Ingredient product replacement deactivates the previous logical line and creates a new `recipe_line_uuid`.
20. Every ingredient movement for one recipe sale item references the same deterministic `recipe_batch_uuid`.
21. Partial replay rejects safely and creates no additional movements.
22. Parent recipe snapshots persist `configuration_source = parent_recipe` and nullable modifier context.
23. Replay validation does not compare newly calculated current stock against historical `quantity_before` or `quantity_after`.

## 18. Test Plan

### Backend Feature Tests

1. `test_recipe_deduction_records_parent_product_sale_item_recipe_line_and_ingredient_snapshot`
2. `test_recipe_deduction_source_effect_key_includes_recipe_line_identity`
3. `test_recipe_deduction_preserves_conversion_snapshot`
4. `test_recipe_deduction_replay_does_not_duplicate_movements`
5. `test_recipe_deduction_replay_drift_is_rejected`
6. `test_existing_sale_movement_does_not_skip_missing_recipe_movements`
7. `test_missing_ingredient_inventory_fails_without_partial_movements`
8. `test_missing_conversion_fails_without_partial_movements`
9. `test_strict_policy_ingredient_shortage_fails_without_partial_movements`
10. `test_soft_negative_recipe_deduction_creates_exception_with_recipe_snapshot`
11. `test_recipe_edit_after_sale_does_not_change_historical_snapshot`
12. `test_mixed_direct_and_recipe_sale_deductions_are_idempotent_independently`
13. `test_recipe_quantity_or_unit_edit_creates_new_version_with_same_logical_uuid`
14. `test_recipe_ingredient_replacement_creates_new_logical_uuid`
15. `test_recipe_batch_uuid_is_deterministic_on_replay`
16. `test_partial_recipe_replay_is_rejected_without_new_movements`
17. `test_recipe_snapshot_reserves_modifier_context`
18. `test_replay_does_not_recompute_historical_before_after_against_current_stock`

### Unit Tests

1. `RecipeDeductionResult` serialization.
2. Source-effect key builder.
3. Recipe snapshot builder.
4. Replay drift comparator.

### Regression Tests

1. Existing single-item inventory deduction.
2. Existing split-payment inventory deduction.
3. Existing recipe shortage logging.
4. Existing unit conversion precedence.
5. Existing inactive conversion rejection.
6. Existing unknown conversion failure.

## 19. Definition of Done

Story 40.4 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Existing POS checkout inventory tests pass.
4. Existing unit conversion tests pass.
5. Existing negative-stock exception tests pass.
6. Recipe movement evidence is append-only and replay-safe.
7. Recipe versioning does not break current product recipe management.
8. Tenant and branch isolation are verified.
9. Documentation is updated.
10. Code review approves the implementation against this specification.

## 20. Decisions for Review

These decisions are recommended for freezing unless review identifies a specific implementation risk:

1. Use additive version rows in `product_recipes`, not a separate history table.
2. Use hybrid movement storage: indexed lineage columns plus full JSON recipe snapshot.
3. Reject duplicate active ingredient rows until a future story introduces explicit recipe line roles, line sequences, or modifier identity.
4. Keep `recipe_batch_uuid` as deterministic correlation evidence, not a persisted aggregate table.
5. Do not persist failed recipe plans as inventory evidence.
