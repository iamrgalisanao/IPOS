# Story 40.2 Unit Conversion Governance

## 1. Status

Approved for Implementation

Date: 2026-07-16

## 2. Objective

Make unit conversion behavior deterministic, visible, versioned, and auditable before Epic 40 tightens negative stock, recipe deduction, stocktake, and reporting behavior.

This story builds on the movement evidence foundation from Story 40.1. It does not redesign recipes, procurement, or stocktake workflows. Its job is to define one authoritative conversion contract that downstream inventory movements can snapshot safely.

## 3. User Story

As an inventory controller,
I want unit conversions to be deterministic, validated, and historically preserved,
so that stock deductions, recipe usage, procurement quantities, and inventory reports do not change meaning after conversion rules are edited.

## 4. Architecture Alignment

This story implements the unit conversion invariants from:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Unit conversion resolution is tenant-scoped.
2. Product-specific conversion overrides tenant-wide conversion.
3. Metric fallback is allowed only for known compatible metric units.
4. Unknown conversions fail closed in strict deduction paths.
5. Conversion rules referenced by committed inventory movements are versioned, not overwritten.
6. Every material conversion-rule change creates a new rule version.
7. Cross-dimension and business package conversions require product-specific rules by default.
8. Direct and inverse resolution are supported; arbitrary multi-hop conversion is out of scope.
9. Unit conversion must not encode preparation yield, wastage, cooking loss, or recipe output.
10. Canonical metric conversions are reserved and cannot be overridden by tenant or product rules.
11. Conversion snapshots preserve the conversion UUID, version, schema version, requested/configured units, conversion path, inversion flag, ratio, unrounded quantity, rounded quantity, rounding mode, and resolution source.
12. Inventory remains operational evidence, not accounting authority.
13. Offline inventory mutation remains prohibited.

## 5. Existing Implementation Context

Current files and behavior that must be respected:

| Area | Current File | Current Behavior |
| --- | --- | --- |
| Conversion model | `app/Models/UnitConversion.php` | Stores tenant, optional product, `from_unit`, `to_unit`, `conversion_factor`, and `is_active`. |
| Conversion migration | `database/migrations/2026_05_19_100002_create_unit_conversions_table.php` | Creates `unit_conversions` with scope uniqueness for tenant-wide and product-specific rules. |
| Resolver | `app/Services/Inventory/UnitConversionResolver.php` | Resolves identity, product rule, global rule, metric fallback, or missing rule. Strict mode throws on missing rule. |
| Unit admin | `app/Http/Controllers/Inventory/UnitConversionController.php` | Allows create, update, and soft deactivate of conversion rows. |
| Product unit | `app/Models/Product.php` | Uses `unit_of_measure` as the current stock/base unit signal. |
| Recipe unit | `app/Models/ProductRecipe.php` | Uses `unit` as the recipe quantity unit for an ingredient line. |
| POS deduction | `app/Services/InventoryService.php` | Converts recipe unit to ingredient product unit in strict mode before deduction. |
| Movement evidence | `app/Models/InventoryMovement.php` | Story 40.1 added `base_unit_id`, `source_unit_id`, `source_quantity`, and `conversion_snapshot`. |
| Movement writer | `app/Services/Inventory/InventoryMovementRecorder.php` | Accepts conversion snapshot fields when supplied. |
| Resolver tests | `tests/Unit/Inventory/UnitConversionResolverTest.php` | Covers product override, global fallback, metric fallback, and missing strict behavior. |
| Management tests | `tests/Feature/Inventory/UnitConversionManagementTest.php` | Covers admin access, create, uniqueness, and soft deactivation. |

Important current gaps:

1. Conversion rows are mutable through `UnitConversionController::update()`.
2. The resolver returns a narrow array and does not expose rule UUID, rule version, factor numerator/denominator, unit-kind evidence, or snapshot schema version.
3. There is no unit-kind compatibility guard, so unsafe conversions can be configured.
4. Product base stock unit, recipe unit, and purchase unit are not explicitly named as roles.
5. Story 40.1 movement fields exist, but strict deduction paths do not yet populate conversion snapshot evidence.

## 6. Scope

### In Scope

1. Define canonical item-level unit role names for inventory base unit, recipe unit, purchase/source unit, and target stock unit.
2. Harden `unit_conversions` so rules have immutable evidence identity and version metadata.
3. Define a deterministic conversion resolution result contract.
4. Preserve product-specific override precedence.
5. Preserve tenant-wide fallback behavior.
6. Restrict metric fallback to compatible known dimensions.
7. Reject unsafe tenant-wide cross-dimension and non-canonical package conversions at creation/update/replacement time.
8. Require explicit admin unit-kind classification for unknown units.
9. Replace every material conversion edit with a new version instead of overwriting old evidence.
10. Capture conversion snapshots into inventory movements when a deduction or movement depends on a conversion.
11. Preserve strict failure behavior for unknown conversions.
12. Add tests for versioning, compatibility, snapshot shape, precedence, inversion, rounding, and strict failure.

### Out of Scope

1. New recipe editor UX.
2. Recipe explosion redesign. Covered by Story 40.4.
3. Procurement receiving workflow redesign.
4. Stocktake posting changes. Covered by Story 40.5.
5. Negative stock variance lifecycle changes. Covered by Story 40.3.
6. Full inventory reporting UI. Covered by Story 40.7.
7. Offline conversion sync.
8. Accounting, costing, tax, payment, or receipt behavior.

## 7. Terminology

| Term | Meaning |
| --- | --- |
| Base stock unit | The product's canonical inventory unit. Currently represented by `products.unit_of_measure`. |
| Source unit | The unit supplied by the business operation, such as recipe unit or purchase unit. |
| Target unit | The unit the operation must resolve into, usually the product base stock unit. |
| Recipe unit | The unit stored on `product_recipes.unit`. |
| Purchase unit | A procurement-facing source unit. This story may define the role but does not redesign procurement workflows. |
| Unit kind | The operational class of a unit, such as mass, volume, count, package, or custom. |
| Canonical package unit | A package/count unit with an invariant meaning, such as dozen, gross, or pair. |
| Business package unit | A package unit whose quantity depends on product, supplier, or operation, such as case, tray, crate, bag, bottle, bucket, or sack. |
| Identity conversion | Source and target units are identical. Factor is exactly 1. |
| Product rule | A conversion row scoped to a specific product. |
| Tenant rule | A conversion row scoped to a tenant with `product_id = null`. |
| Metric fallback | Built-in conversion for known compatible metric units only. |
| Conversion snapshot | Immutable movement evidence describing how a source quantity became a base stock quantity. |

## 8. Unit Role Model

Unit roles belong to the inventory item, not to a generic conversion rule.

Conceptual model:

```text
Inventory Product
├── base_stock_unit
├── default_recipe_unit
└── default_purchase_unit
```

Rules:

1. Every inventoried product has exactly one base stock unit.
2. `products.unit_of_measure` remains the base stock unit for compatibility in Story 40.2.
3. Recipe and purchase units are optional operational roles.
4. Recipe and purchase units must resolve deterministically into the product base stock unit before they affect inventory.
5. This story may reserve future explicit fields such as `inventory_base_unit`, `default_recipe_unit`, and `default_purchase_unit`, but should not redesign the product catalog unless required for safe implementation.
6. Downstream services should not choose arbitrary target units; inventory-affecting resolution should target the product base stock unit.

## 9. Data Model Requirements

### 9.1 `unit_conversions`

Add or backfill fields needed for versioned conversion evidence:

```text
conversion_uuid
conversion_schema_version
version
source_unit_kind
target_unit_kind
factor_numerator
factor_denominator
supersedes_conversion_id
locked_at
is_active
created_by
updated_by
metadata
unit_kind_confidence
```

Implementation notes:

1. `conversion_uuid` is the immutable external identity. The existing primary key remains the database primary key.
2. `conversion_schema_version` starts at `1`.
3. `version` starts at `1` for each tenant/product/unit pair.
4. Existing `conversion_factor` may remain for compatibility in this story, but new resolution should prefer numerator and denominator when present.
5. `factor_numerator / factor_denominator` represents how to convert one `from_unit` into `to_unit`.
6. `factor_numerator` and `factor_denominator` should be decimal fields, not integer-only fields.
7. `source_unit_kind` and `target_unit_kind` classify operational unit families, such as mass, volume, count, package, or custom.
8. `locked_at` marks a row that has been referenced by inventory movement evidence.
9. `supersedes_conversion_id` preserves replacement lineage.
10. `is_active = true` identifies the rule eligible for future resolution.
11. `metadata` may store migration notes or legacy source details.
12. `unit_kind_confidence` may be used during migration to distinguish certain inferred kinds from rows needing admin review.

Optional first-release fields if easy to support:

```text
superseded_by_conversion_id
effective_from
effective_until
locked_reason
```

These optional fields must not turn Story 40.2 into a full temporal-rule engine.

### 9.2 Compatibility With Existing Indexes

Current uniqueness prevents duplicate rows for the same tenant/product/unit pair. Story 40.2 must revise that uniqueness so historical versions can coexist.

Recommended unique constraints:

```text
tenant_id, product_id nullable, from_unit, to_unit, version
tenant_id, product_id nullable, from_unit, to_unit, is_active latest rule guard
conversion_uuid unique
```

Because nullable partial uniqueness differs by database engine, implementation should preserve SQLite test compatibility and MySQL/Postgres deployment compatibility.

### 9.3 Unit Kind Catalog

This story may implement a small in-code unit-kind catalog or a small table if it matches existing project patterns.

Minimum built-in unit kinds:

```text
mass: kg, gram, g
volume: liter, litre, l, ml
count: piece, pc, pcs, unit
package: sack, tray, case, bottle, scoop
custom: tenant-defined operational units
```

Rules:

1. Metric fallback may convert only within the same recognized metric dimension or unit kind.
2. Cross-dimension conversions require an explicit product-specific rule by default.
3. Tenant-wide cross-dimension rules are prohibited by default.
4. Tenant-wide rules should be limited to safe universal conversions, such as `kg -> gram`, `liter -> ml`, `dozen -> piece`, `gross -> piece`, or `pair -> piece`.
5. Tenant-wide package conversions are permitted only for canonical package units with invariant meanings.
6. Business package units such as `case`, `tray`, `crate`, `bag`, `bottle`, `bucket`, `sack`, or `scoop` require product scope.
7. Unknown units require explicit admin unit-kind classification.
8. Unknown units must not be silently classified as `custom`.
9. No automatic fallback from `custom` or package units is allowed.
10. Units should be normalized for lookup, but display labels should preserve the configured unit string where practical.

## 10. Conversion Resolution Contract

Create or formalize a stable result object. An array is acceptable if project conventions favor arrays, but the fields must be stable.

Recommended service contract:

```text
UnitConversionResolver::resolve(
    quantity,
    fromUnit,
    toUnit,
    productId = null,
    strict = false
) : ConversionResolution
```

Compatibility rule:

Existing callers using `convert()` may continue to work during the transition, but `convert()` should delegate to the new resolver contract.

Resolution result fields:

```text
value
source_quantity
resolved_quantity
from_unit
to_unit
normalized_from_unit
normalized_to_unit
source_unit_kind
target_unit_kind
resolved_by
missing
conversion_rule_uuid
conversion_rule_version
conversion_schema_version
factor_numerator
factor_denominator
configured_from_unit
configured_to_unit
requested_from_unit
requested_to_unit
was_inverted
conversion_path
unrounded_resolved_quantity
rounded_resolved_quantity
rounding_mode
quantity_scale
product_id
tenant_id
snapshot
```

Allowed `resolved_by` values:

```text
identity
product_rule
tenant_rule
metric_fallback
missing
```

Backwards compatibility:

1. Existing `resolved_by = global_rule` may be accepted as an alias in tests during migration, but the new canonical name should be `tenant_rule`.
2. Existing result key `value` must remain available until all consumers migrate.
3. Existing key `missing` must remain available.

## 11. Resolution Precedence

Resolution order:

```text
1. Identity conversion if source unit equals target unit.
2. Active product-specific direct rule.
3. Active product-specific inverse rule.
4. Active tenant-wide direct rule.
5. Active tenant-wide inverse rule.
6. Compatible metric fallback.
7. Missing conversion result or strict exception.
```

Rules:

1. Product-specific rules must not be bypassed by tenant-wide rules.
2. Inactive rules must not be used for new resolutions.
3. Superseded rules must not be used for new resolutions unless resolving a historical snapshot by ID.
4. Historical movement snapshots preserve the original rule ID and version even after a newer rule exists.
5. Strict deduction paths must throw before stock or sale inventory effects are mutated if no conversion can be resolved.
6. Explicit rules must not override canonical metric conversions within the same recognized metric dimension.
7. Metric fallback applies only when no explicit valid direct or inverse rule exists.

Canonical metric conversions are reserved. Tenant and product rules must not override them.

Examples:

```text
Blocked:
1 kg = 900 g

Reason:
This is not unit conversion. It is yield, wastage, or production logic.
```

## 12. Direct, Inverse, and Path Restrictions

Supported resolution paths:

1. identity conversion,
2. one direct rule,
3. one mathematical inverse of a direct rule,
4. built-in metric fallback.

Out of scope:

1. arbitrary multi-hop rule chaining,
2. graph traversal,
3. automatic creation of inverse rows,
4. cycle detection across chained conversions.

Rules:

1. A valid rule may be resolved in its configured direction or mathematically inverted.
2. Inversion is allowed only when the configured factor is non-zero and the same scope and unit-kind restrictions are satisfied.
3. For inverted use, effective numerator equals configured denominator and effective denominator equals configured numerator.
4. Every operational source unit should resolve directly to the product base stock unit through one direct rule, one inverse rule, identity, or metric fallback.
5. Multi-hop examples such as `case -> bottle -> ml` are not approved in Story 40.2.

## 13. Decimal Arithmetic and Rounding

Formula:

```text
resolved_quantity
=
source_quantity * factor_numerator / factor_denominator
```

Rules:

1. Use decimal arithmetic; never binary floating-point for persisted movement evidence.
2. Do not round intermediate calculations.
3. Round only when producing the inventory movement quantity.
4. Use the existing inventory quantity scale, currently `decimal(19,4)`.
5. Use one platform rounding mode for inventory conversion output: `HALF_UP`, unless existing inventory standards require a different mode.
6. Preserve the unrounded calculation at higher precision in the conversion snapshot when practical.
7. `factor_numerator` and `factor_denominator` should support at least 8 decimal places.
8. `factor_denominator` must be greater than zero.
9. `factor_numerator` and `factor_denominator` must be normalized before storage.

Examples:

```text
Store:
2 / 1

Do not store:
2000 / 1000
```

## 14. Conversion Is Not Yield

Unit conversion must not encode:

1. preparation loss,
2. wastage,
3. cooking yield,
4. recipe output,
5. trim loss,
6. evaporation or shrinkage.

Examples:

```text
Allowed conversion:
1 sack flour = 50 kg flour

Not allowed as conversion:
1 kg raw onions = 850 g usable onions
```

Yield belongs to recipe, production, or variance evidence, not unit conversion.

Scientific property-based conversions such as density, specific gravity, or concentration are outside Story 40.2 and require a future production or manufacturing architecture.

## 15. Versioning and Locking Policy

### 15.1 Material Changes

The following changes are material:

1. `from_unit`
2. `to_unit`
3. `conversion_factor`
4. `factor_numerator`
5. `factor_denominator`
6. `source_unit_kind`
7. `target_unit_kind`
8. `product_id`

If a material change is requested, implementation must always:

1. keep the old row,
2. mark the old row inactive or superseded for future resolution,
3. create a new row with `version = old version + 1`,
4. link replacement lineage,
5. ensure new resolutions use the new row,
6. preserve historical movement snapshots unchanged.

This rule applies even before the previous row is referenced by movement evidence. The UI may present the operation as a normal edit, but the server stores it as a new version.

### 15.2 Non-Material Changes

Non-material admin metadata may be updated in place only if it does not change conversion meaning.

Examples:

1. notes,
2. display label,
3. inactive status when no new rule is being created.

### 15.3 Lock Detection

A conversion rule is considered referenced when any committed inventory movement has a `conversion_snapshot` containing:

```text
conversion_rule_uuid
conversion_rule_version
```

Implementation may also lock a rule proactively at first use by setting `locked_at`.

## 16. Conversion Snapshot Contract

When a movement uses conversion logic, the movement must preserve:

```json
{
  "conversion_schema_version": 1,
  "resolution_source": "product_rule",
  "conversion_rule_uuid": "uuid-or-null",
  "conversion_rule_version": 2,
  "requested_from_unit": "sack",
  "requested_to_unit": "kg",
  "normalized_from_unit": "sack",
  "normalized_to_unit": "kg",
  "configured_from_unit": "sack",
  "configured_to_unit": "kg",
  "was_inverted": false,
  "conversion_path": "direct",
  "source_unit_kind": "package",
  "target_unit_kind": "mass",
  "source_quantity": "2.00000000",
  "factor_numerator": "50.00000000",
  "factor_denominator": "1.00000000",
  "unrounded_resolved_quantity": "100.00000000",
  "resolved_quantity": "100.0000",
  "rounding_mode": "HALF_UP",
  "quantity_scale": 4,
  "product_id": "ingredient-product-id",
  "tenant_id": "tenant-id"
}
```

Movement fields should align with the snapshot:

```text
inventory_movements.source_unit_id = from unit
inventory_movements.base_unit_id = target/base stock unit
inventory_movements.source_quantity = source quantity before conversion
inventory_movements.quantity_change = signed resolved base-unit quantity
inventory_movements.conversion_snapshot = immutable resolution snapshot
```

Identity conversions may omit `conversion_rule_uuid` but should still identify `resolution_source = identity` if a source quantity was supplied.
Snapshots should use stable `conversion_rule_uuid`, not the database primary key, as the canonical conversion identity.

Historical inventory replay must reconstruct quantities exclusively from the stored movement snapshot. Replay must never re-resolve historical quantities against the current active conversion rules.

## 17. Service Design

### 17.1 `UnitConversionResolver`

Responsibilities:

1. Normalize units for lookup.
2. Enforce tenant scope.
3. Apply precedence.
4. Enforce unit-kind compatibility.
5. Return stable conversion evidence.
6. Throw in strict mode when no valid conversion exists.
7. Support direct and inverse rule resolution.
8. Reject arbitrary multi-hop resolution.

Must not:

1. mutate stock,
2. create inventory movements,
3. create variance records,
4. make procurement decisions,
5. make recipe explosion decisions.

### 17.2 `UnitConversionGovernanceService`

Recommended new service:

```text
App\Services\Inventory\UnitConversionGovernanceService
```

Responsibilities:

1. Create conversion rules.
2. Replace material conversion rules by versioning.
3. Deactivate conversion rules.
4. Validate unit-kind compatibility.
5. Determine whether a rule is referenced by movement evidence.
6. Normalize admin request payloads.
7. Always create a new version for material changes.

Controllers should call this service rather than mutating `UnitConversion` directly.

### 17.3 `ConversionResolution` Value Object

Recommended new value object:

```text
App\Services\Inventory\ConversionResolution
```

Responsibilities:

1. expose converted numeric value for existing callers,
2. expose snapshot payload for movement writer,
3. expose `missing` and `resolved_by` status,
4. keep result serialization stable for tests and API responses.

If a value object is too much for the first implementation, an associative array is acceptable only if it contains the full stable fields listed in this specification.

## 18. Integration Requirements

### 18.1 POS Recipe Deduction

`InventoryService::deductComponent()` currently converts recipe unit to ingredient base unit and passes only the converted float into `performDeduction()`.

Story 40.2 should preserve behavior but add evidence:

1. Resolve conversion through the hardened resolver.
2. Pass source unit, base unit, source quantity, and conversion snapshot into the movement writer.
3. Ensure strict conversion failure aborts before stock mutation.
4. Preserve existing product-specific override tests.
5. Preserve existing strict missing-conversion failure tests.

This story should not implement the full recipe deduction result contract from Story 40.4.

### 18.2 Direct Stock Movements

For stock movements already in base unit:

1. `source_unit_id` may equal `base_unit_id`.
2. `source_quantity` may equal absolute movement quantity.
3. `conversion_snapshot` may use identity resolution or remain null if no source-unit conversion was involved.

Implementation should prioritize conversion snapshot capture in recipe deduction paths where conversion is actually used.

### 18.3 Procurement

This story may define purchase unit semantics but must not redesign purchase receiving, supplier return, or IBT workflows.

If procurement source units are encountered during implementation, capture only what is already available without changing procurement business flow.

## 19. Authorization and Isolation

Rules:

1. Unit conversion admin remains protected by `manage_unit_conversions`.
2. Tenant isolation must be enforced for create, update, replace, deactivate, and lookup.
3. Product-specific rules must reference products belonging to the active tenant.
4. Cross-tenant product IDs must be rejected or hidden.
5. Branch scope is not required for conversion rules unless future stories introduce branch-specific conversions.

## 20. API and UI Behavior

The existing Inertia page may remain the management surface.

Required behavior changes:

1. Creation must show or derive known unit kind and require admin classification for unknown units.
2. Editing a material field must create a new version, not overwrite the old row.
3. Deactivation must preserve historical evidence.
4. Listing should expose at least version, active state, product scope, unit pair, factor, and unit kinds.
5. Validation errors must be deterministic for duplicate active rules, unsafe tenant-wide conversions, and incompatible unit kinds.
6. Rule replacement should explain that future deductions use the new conversion while past movements keep prior snapshots.
7. Admins should see a preview example, such as `2 sacks -> 90 kg`, before saving.
8. Cross-dimension or package conversions should show a warning.
9. Version history should be secondary, not the primary form experience.
10. Inactive historical versions should not be arbitrarily editable.

Out of scope:

1. A large unit management redesign.
2. Bulk import/export of conversion rules.
3. Branch-specific conversion UI.

## 21. Error Handling

Recommended domain exceptions:

```text
UnitConversionNotFoundException
UnitConversionUnitKindMismatchException
UnitConversionRuleLockedException
UnitConversionReplayDriftException
UnitConversionScopeException
```

HTTP behavior:

| Condition | Status |
| --- | ---: |
| Validation failure | 422 |
| Unauthorized | 403 |
| Cross-tenant hidden resource | 404 |
| Incompatible unit kinds | 422 |
| Unsafe tenant-wide cross-dimension rule | 422 |
| Missing strict conversion during checkout | Existing POS failure behavior |
| Attempt to overwrite material rule | Versioned replacement |

For admin material edits, prefer versioned replacement over blocking whenever the user has permission and the new rule is valid.

## 22. Migration and Backfill Requirements

Backfill existing conversion rows:

1. Assign `conversion_uuid`.
2. Set `conversion_schema_version = 1`.
3. Set `version = 1`.
4. Populate numerator and denominator from `conversion_factor`.
5. Infer unit kind when units are known.
6. Record unit-kind inference confidence where practical.
7. For known units such as `kg`, confidence should be `certain`.
8. For ambiguous units such as `cup`, `case`, or `scoop`, confidence should be `uncertain` and surfaced for admin review when a review surface exists.
9. For unknown units, require admin classification before creating new rules after the migration.
10. For existing unknown explicit rules, backfill a conservative `custom` or `package` classification only if required to keep legacy data readable; mark the row for admin review in metadata.
11. Preserve `is_active` state.

Do not generate inventory movement changes during this migration.

## 23. Acceptance Criteria

1. Product-specific conversion wins over tenant-wide conversion.
2. Tenant-wide conversion is used when no product-specific rule exists.
3. Compatible metric fallback remains deterministic.
4. Incompatible metric dimensions are not silently converted.
5. Unknown conversions fail closed in strict deduction paths.
6. Unknown units require explicit unit-kind classification during admin setup.
7. Cross-dimension and business package conversions require product-specific rules unless the unit is a canonical package unit with invariant meaning.
8. Direct and inverse resolution are supported.
9. Arbitrary multi-hop conversion is not supported.
10. Canonical metric conversions cannot be overridden by tenant or product rules.
11. Conversion resolution returns stable UUID identity, rule version, schema version, ratio evidence, requested/configured units, unit kinds, conversion path, inversion flag, unrounded quantity, rounded quantity, and resolved quantity.
12. Deduction movements that depend on conversion capture source unit, base unit, source quantity, and conversion snapshot.
13. Historical movement snapshots keep the original conversion version after a rule is changed.
14. Historical inventory replay uses stored snapshots only and never re-resolves against active conversion rules.
15. Every material change creates a new version instead of rewriting the previous rule.
16. Inactive or superseded rules are not used for new resolutions.
17. Tenant isolation is enforced for rule management and resolution.
18. Existing conversion management permissions remain enforced.
19. Unit conversion cannot be used to encode yield, wastage, recipe-output transformation, density, specific gravity, or concentration.

## 24. Test Requirements

Backend tests:

1. `UnitConversionResolverTest`
   - identity resolution,
   - product-specific precedence,
   - tenant-wide fallback,
   - metric fallback within dimension,
   - incompatible metric rejection,
   - product-specific cross-dimension rule allowed,
   - tenant-wide cross-dimension rule rejected,
   - inverse direct rule resolution,
   - multi-hop resolution rejected,
   - no tenant or product override of canonical metric conversion,
   - conversion path in snapshot,
   - normalized factor storage,
   - strict missing conversion exception,
   - snapshot field completeness.
2. `UnitConversionManagementTest`
   - create with unit kind,
   - unknown unit requires classification,
   - duplicate active rule validation,
   - every material edit creates a new version,
   - business package tenant-wide rule rejected,
   - canonical package tenant-wide rule allowed,
   - deactivation preserves old row,
   - cross-tenant product rejected,
   - permission enforcement preserved.
3. POS inventory deduction tests
   - converted recipe movement captures conversion snapshot,
   - product-specific override snapshot is preserved,
   - missing strict conversion rolls back inventory mutation.
4. Migration tests or feature coverage
   - existing conversion rows receive version metadata,
   - historical versions can coexist without uniqueness collisions.

Regression tests:

1. Existing dynamic unit conversion tests continue to pass.
2. Existing product-specific override tests continue to pass.
3. Existing inactive conversion tests continue to pass.
4. Existing unknown conversion checkout failure behavior continues to pass.

## 25. Implementation Slicing

Recommended PR sequence:

1. Migration and model metadata
   - add version fields,
   - backfill existing rules,
   - revise uniqueness/indexes,
   - update casts/fillable.
2. Resolver contract
   - normalize units,
   - unit-kind catalog,
   - richer result/snapshot,
   - direct and inverse resolution,
   - multi-hop rejection,
   - canonical metric override blocking,
   - canonical versus business package distinction,
   - decimal arithmetic and rounding policy,
   - strict failure behavior.
3. Governance service and controller update
   - create/replace/deactivate through service,
   - preserve permissions,
   - validate unit kinds,
   - require product scope for cross-dimension and unsafe package rules.
4. Movement snapshot integration
   - pass conversion snapshot from recipe deduction to `InventoryMovementRecorder`,
   - preserve Story 40.1 movement evidence.
5. Tests and documentation
   - resolver tests,
   - management tests,
   - POS deduction snapshot tests.

## 26. Out-of-Scope Guardrails

Do not pull these into Story 40.2:

1. Recursive recipe handling.
2. Full `RecipeDeductionResult`.
3. Stocktake movement watermarks.
4. Variance status workflows.
5. Procurement receiving redesign.
6. Supplier unit catalogs.
7. Accounting entries.
8. Offline queueing.
9. New inventory report pages.
10. Multi-hop conversion graph.
11. Yield, wastage, or production-loss modeling.
12. Scientific property conversion such as density, specific gravity, or concentration.

## 27. Definition of Done

Story is done when:

1. Acceptance criteria pass.
2. Required backend tests pass.
3. Existing unit conversion and POS deduction tests pass.
4. Migrations run cleanly and roll back cleanly.
5. Conversion rules referenced by movements cannot be materially overwritten.
6. Converted movements include stable conversion snapshots.
7. Every material conversion edit creates a replacement version.
8. Direct inverse conversion is tested and multi-hop conversion is rejected.
9. Canonical metric conversion override is blocked.
10. Historical replay uses stored snapshots only.
11. Tenant isolation and `manage_unit_conversions` authorization are verified.
12. No offline inventory mutation path is introduced.
13. Code review confirms no inventory, procurement, payment, accounting, yield-modeling, or scientific-property boundary violations.

## 28. Locked Review Decisions

1. Unknown units require admin-provided unit-kind classification.
2. Every material edit creates a new conversion version, even before first use.
3. `tenant_rule` is the canonical resolution source; `global_rule` may remain a temporary serialization alias for compatibility.
4. Cross-dimension conversion requires product-specific scope by default.
5. Direct and inverse resolution are supported.
6. Arbitrary multi-hop conversion is out of scope.
7. Conversion snapshots use stable conversion UUID, not database primary key, as canonical identity.
8. Unit conversion does not model yield, wastage, or recipe-output transformation.
9. Tenant-wide package conversions are allowed only for canonical package units with invariant meanings.
10. Business package units require product scope.
11. Factor numerator and denominator are normalized before storage.
12. Snapshots include `conversion_path`.
13. Canonical metric conversions are reserved and non-overridable.
14. Historical replay uses stored snapshots only.
15. Scientific property-based conversions are reserved for a future production or manufacturing architecture.
