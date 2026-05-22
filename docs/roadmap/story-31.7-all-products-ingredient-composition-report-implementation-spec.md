# Implementation Spec: Story 31.7 — All-Products Ingredient Composition Report

Status: PROPOSED
Epic: Epic 31 — Product Catalog & Inventory Admin UX
Date: 2026-05-22

## 1. Objective

Implement a read-only reporting surface that shows granular raw-material composition for every sellable product, with optional branch stock and cost context.

This spec turns existing recipe deduction logic into a manager-facing report for planning, purchasing, and margin governance.

## 2. Current Foundation (Already Implemented)

The following capabilities are already in the codebase and will be reused:

1. Product recipe structure (`product_recipes`) with `product_id`, `ingredient_id`, `quantity`, `unit`.
2. Product relationships: `Product::recipes()` and `Product::ingredientOf()`.
3. Recipe deduction engine in `InventoryService::deductFromSale()` and `deductComponent()`.
4. Unit conversion engine via `unit_conversions` table and `InventoryService::convertUnit()`.
5. Inventory variance logs for shortage scenarios (`inventory_variance_logs`).
6. Existing inventory report permission gates (`view_inventory_reports`, `audit_inventory`).

## 3. Scope

### In Scope

1. New read-only report screen listing product-to-ingredient composition across catalog.
2. Optional recursive expansion of sub-recipes (flattened effective ingredient demand).
3. Optional branch-aware stock/cost context per ingredient.
4. CSV export of the filtered report.
5. Tenant-safe and branch-safe filtering.

### Out of Scope

1. Editing recipe composition from this report (editing remains in Product Edit UI).
2. Changing deduction behavior.
3. New costing algorithm beyond existing fields (`branch_inventories.average_cost`, `products.cost_price`).
4. Procurement automation trigger logic (only report inputs for planning).

## 4. Report Semantics (Critical)

### 4.1 Mode Semantics

1. `direct_only` mode mirrors current live POS deduction behavior.
2. `flatten_subrecipes` mode is planning-only analytics and may differ from live POS deduction until recursive deduction is implemented in checkout services.
3. UI must display an explicit advisory banner when `flatten_subrecipes` is active:
1. "Flattened mode is planning-only. Live POS currently deducts direct recipe components only."

### 4.2 Behavior Alignment Guardrail

1. No language in page labels, exports, or help text should imply flattened rows are currently deducted at checkout.
2. CSV export in flattened mode must include a `mode_semantics` column with value `planning_only`.

## 5. Calculation Rules

### 5.1 Quantity Rules

Definitions:

1. For edge `A -> B`, let:
1. `q_edge` = recipe quantity on edge
1. `u_edge` = recipe unit on edge
1. `u_base(B)` = base unit of ingredient product B (`products.unit_of_measure`)
2. Converted edge qty:
1. `q_edge_base(B) = convert(q_edge, u_edge, u_base(B), B.id)`
3. Path multiplier for parent sale quantity `q_parent`:
1. `path_multiplier = product(q_edge_base(child) over path edges)`
4. Effective base quantity for leaf ingredient L:
1. `effective_qty_base(L) = q_parent * path_multiplier`

Worked example:

1. Burger -> Patty: `1 piece`
2. Patty -> Beef: `150 gram`
3. Parent qty = 1 Burger
4. Assume no conversion needed for `piece` and `gram` to leaf base.
5. `effective Beef = 1 * 1 * 150 = 150 gram`

Unit conversion rule:

1. Convert exactly once per edge into that edge ingredient base unit.
2. Never reconvert already-base quantities for the same node.

### 5.2 Cost Rules

Cost source precedence per ingredient row:

1. `branch_inventories.average_cost` when branch selected and value is not null.
2. Fallback to `products.cost_price`.
3. If both null, cost fields are null.

Missing definition for branch average cost:

1. Missing if branch inventory row does not exist OR `average_cost` is null.
2. `average_cost = 0` is treated as present but flagged with `cost_status=zero_cost_suspicious`.

Precision:

1. Currency computations use decimal scale 4.
2. UI displays 2 to 4 decimals based on existing monetary formatting policy.

Formula:

1. `effective_cost_per_parent_unit = effective_qty_base * selected_unit_cost`

Cost unit assumption:

1. `branch_inventories.average_cost` is interpreted as cost per ingredient base unit.
2. `products.cost_price` fallback is interpreted as cost per `products.unit_of_measure` (the ingredient base unit used by this report).

### 5.3 Coverage Rules

Row-level (ingredient-specific):

1. `coverage_ingredient_parent_units = current_stock_base / effective_qty_base`
2. Null when no branch selected or effective qty is zero.

Parent-level bottleneck coverage:

1. `coverage_parent_bottleneck_units = min(coverage_ingredient_parent_units across required ingredients of same parent)`
2. Exposed in grouped metadata and optional dedicated column.

## 6. Sorting and Pagination Contract

To avoid parity drift between page and export:

1. Use one shared row builder method for both page and CSV export.
2. Processing order:
1. Filter parent products (tenant scoped).
1. Build all report rows according to mode.
1. Apply deterministic sort.
1. Paginate rows (not parent products).
3. Deterministic default sort:
1. `parent_product_name ASC`
1. `depth ASC`
1. `path_signature ASC`
1. `ingredient_name ASC`
4. Export uses same filters and same sorted row set, without pagination.

## 7. UX and Information Architecture

### New Navigation Entry

Add report route under Inventory Reports section:

1. Label: `Product Ingredient Composition`
2. Path: `/inventory/reports/product-composition`

Navigation implementation note:

1. Update Inventory Reports entry point to include the new link in dashboard navigation.

### Report Screen Layout

1. Filters:
1. Product search (name/SKU)
1. Category filter
1. Product type filter (`finished_good`, `semi_finished`, `raw_material`)
1. Branch filter (optional; enables stock and branch cost context)
1. Expansion mode (`direct_only`, `flatten_subrecipes`)
1. Max recursion depth (default 5)

2. Table columns (baseline):
1. Parent product SKU
1. Parent product name
1. Ingredient SKU
1. Ingredient name
1. Ingredient product type
1. Direct recipe qty
1. Direct recipe unit
1. Effective qty in ingredient base unit
1. Ingredient base unit
1. Path/depth (for flattened mode)

3. Branch context columns (shown only when branch selected):
1. Current stock
1. Reorder level
1. Avg cost (branch)
1. Effective ingredient cost per parent unit
1. Coverage (ingredient row)
1. Parent bottleneck coverage (optional grouped metric)

4. Actions:
1. Export CSV
1. Reset filters

Branchless behavior:

1. When `branch_id` is omitted, branch context columns are hidden in UI.
2. CSV branch columns remain present for schema stability and are emitted as blank values when no branch is selected.

## 8. Backend Design

### 8.1 Routes

Add inside existing inventory report permission middleware group in `routes/web.php`:

1. `GET /inventory/reports/product-composition` -> `Inventory\ProductCompositionReportController@index`
2. `GET /inventory/reports/product-composition/export` -> `Inventory\ProductCompositionReportController@export`

Middleware:

1. `permission:view_inventory_reports`

Field-level sensitivity policy:

1. Actors with `view_inventory_reports` can access report composition quantities, ingredient names, and stock context.
2. Cost fields are included only for actors with `audit_inventory` (or a new dedicated cost-visibility permission if introduced later).
3. Without cost permission, cost columns are null/hidden in page payload and CSV.

### 8.2 Controller

Create `app/Http/Controllers/Inventory/ProductCompositionReportController.php`.

Responsibilities:

1. Validate filter input.
2. Authorize tenant/branch scope.
3. Delegate heavy computation to service.
4. Return Inertia page payload.
5. Stream CSV export with formula-injection protection (reuse pattern from `VarianceLogController`).

Validation contract:

1. `search` nullable|string|max:255
2. `category_id` nullable|uuid|Rule::exists('product_categories', 'id')->where('tenant_id', currentTenantId)
3. `product_type` nullable|in:finished_good,semi_finished,raw_material
4. `branch_id` nullable|uuid|Rule::exists('branches', 'id')->where('tenant_id', currentTenantId)
5. `expansion_mode` nullable|in:direct_only,flatten_subrecipes
6. `max_depth` nullable|integer|min:1|max:10

### 8.3 Service

Create `app/Services/Inventory/ProductCompositionReportService.php`.

Public methods:

1. `paginate(array $filters, int $perPage = 25): LengthAwarePaginator`
2. `exportRows(array $filters): Collection`
3. `buildRows(array $filters): Collection` (shared canonical row builder)

Core algorithm:

1. Query parent products (default sellable, active, tenant-scoped).
2. Eager-load direct recipes and ingredients.
3. For each direct recipe row:
1. Compute effective quantity in ingredient base unit.
1. Build direct row.
1. If `flatten_subrecipes`, recursively expand when ingredient has its own recipe rows.
4. In recursion:
1. Multiply quantities through path using converted per-edge base quantities.
1. Convert each edge quantity from recipe unit -> that edge ingredient base unit, exactly once per edge.
1. Keep `visited` set to prevent loops.
1. Enforce `max_depth`.
5. If `branch_id` provided:
1. Load `branch_inventories` for referenced ingredients.
1. Attach `current_stock`, `reorder_level`, `average_cost`.
1. Derive fallback cost from `products.cost_price` when branch average missing (null or no row, not zero).
6. Compute row-level coverage and parent bottleneck coverage.
7. Deterministically sort rows.
8. Paginate sorted rows at row level.

Implementation notes:

1. Reuse conversion resolution logic with shared component to avoid divergence from deduction engine.
2. If no conversion path exists, mark row with `conversion_status=missing_rule` instead of throwing.
3. Include `path_signature` for flattened rows (example: `BURGER > PATTY > BEEF`).
4. Include `mode_semantics` in each row (`matches_live_deduction` or `planning_only`).
5. Include recursion warning status in each row so truncation/cycle handling is explicit.

### 8.4 Conversion Consistency

To avoid duplicated conversion logic drifting from checkout behavior:

1. Extract conversion resolver from `InventoryService` into dedicated reusable class:
1. `app/Services/Inventory/UnitConversionResolver.php`
2. Update `InventoryService` to consume resolver.
3. Report service consumes same resolver.

Resolver contract:

1. `convert(float $quantity, string $fromUnit, string $toUnit, ?string $productId, bool $strict = false): array`
2. Return shape:
1. `value` float
1. `resolved_by` enum (`product_rule`, `global_rule`, `metric_fallback`, `identity`, `missing`)
1. `missing` bool
1. `from_unit` string
1. `to_unit` string

## 9. Data Contract (Inertia Payload)

Top-level payload:

1. `rows` paginated
2. `filters`
3. `branches`
4. `categories`
5. `meta`
6. `semantics` (mode semantics labels/warnings)

Row schema:

1. `parent_product_id`
2. `parent_product_name`
3. `parent_product_sku`
4. `ingredient_id`
5. `ingredient_name`
6. `ingredient_sku`
7. `ingredient_product_type`
8. `direct_quantity`
9. `direct_unit`
10. `effective_quantity_base`
11. `ingredient_base_unit`
12. `depth`
13. `path_signature`
14. `conversion_status`
15. `mode_semantics`
16. `branch_current_stock` (nullable)
17. `branch_reorder_level` (nullable)
18. `branch_average_cost` (nullable)
19. `fallback_cost_price` (nullable)
20. `cost_status` (`ok`, `fallback_used`, `missing`, `zero_cost_suspicious`)
21. `effective_cost_per_parent_unit` (nullable)
22. `coverage_ingredient_parent_units` (nullable)
23. `coverage_parent_bottleneck_units` (nullable or repeated in grouped rows)
24. `recursion_status` (`ok`, `max_depth_reached`, `cycle_detected`)
25. `row_warnings` (array of warning codes/messages, optional)

## 10. Frontend Design

Create `resources/js/Pages/Inventory/ProductComposition/Index.jsx`.

Requirements:

1. Preserve existing visual language from Inventory report pages.
2. URL-synced filters via Inertia query string.
3. Sticky table header and horizontal scrolling for wide columns.
4. Branch context columns conditionally shown when branch selected.
5. CSV export button preserving current filters.
6. Row badge for `conversion_status=missing_rule`.
7. Show planning-only semantics banner when flattened mode selected.
8. Hide or mask sensitive cost fields when actor lacks cost permission.

Optional enhancement:

1. Expand/collapse grouped view by parent product in client-side rendering.

## 11. CSV Export Spec

Controller `export` streams all filtered rows with the following columns:

1. Parent SKU
2. Parent Name
3. Ingredient SKU
4. Ingredient Name
5. Ingredient Type
6. Direct Qty
7. Direct Unit
8. Effective Qty (Base)
9. Base Unit
10. Depth
11. Path
12. Conversion Status
13. Mode Semantics
14. Branch Stock
15. Branch Reorder Level
16. Branch Avg Cost
17. Fallback Cost
18. Cost Status
19. Effective Ingredient Cost / Parent Unit
20. Coverage (Ingredient)
21. Coverage (Parent Bottleneck)
22. Recursion Status

Security:

1. Prefix potentially dangerous CSV cells with `'` when value starts with `=`, `+`, `-`, `@`.
2. Export must use the same row builder and semantics as page output.

Export safety:

1. Export implementation must stream rows and must not materialize the full CSV payload in memory.
2. Use a configurable export ceiling (for example, `REPORT_EXPORT_MAX_ROWS`) to prevent runaway exports in very large tenants.
3. If row count exceeds ceiling, return a validation-style response instructing the user to narrow filters (future enhancement: background export job).

## 12. Performance and Safety

1. Tenant-scoped query only.
2. Branch filter must validate branch belongs to tenant.
3. Category filter must validate category belongs to tenant.
4. Eager-load recipes and ingredients to avoid N+1.
5. Use in-memory map for ingredient inventory/cost lookup by `ingredient_id`.
6. Recursion guard:
1. max depth
1. visited node tracking
7. Recommend adding/confirming indexes:
1. `product_recipes(product_id)`
1. `product_recipes(ingredient_id)`
1. `branch_inventories(branch_id, product_id)`

## 13. Test Plan

### Feature Tests

Create `tests/Feature/Inventory/ProductCompositionReportTest.php`.

Coverage:

1. Route authorization by permission.
2. Tenant isolation for products and branches.
3. Tenant-scoped category filter validation.
4. Direct mode returns direct recipe rows only.
5. Flatten mode expands nested sub-recipes and shows planning-only semantics.
6. Recursive loop detection returns safe result and explicit row warning/status.
7. Missing conversion rule flags row without 500.
8. Branch context enrichment includes stock and average cost.
9. Branchless mode hides or nulls branch-only columns.
10. CSV export contains filtered rows and protected cell escaping.
11. Export parity: direct and flattened export rows match page row builder output.
12. Export path uses full filtered row set and does not reuse paginated subset.

### Unit Tests

Create `tests/Unit/Inventory/UnitConversionResolverTest.php`.

Coverage:

1. Product-specific override precedence.
2. Tenant global conversion fallback.
3. Metric fallback behavior.
4. Missing rule behavior in strict/non-strict modes.
5. Edge conversion applied once-per-edge in nested path calculation.

## 14. Implementation Slices

Slice A: Backend Read API

1. Add routes.
2. Add controller `index` only.
3. Add service with direct mode only.
4. Add tenant-scoped validation rules.
5. Add feature tests for direct mode.

Slice B: Flattened Expansion + Conversion Resolver

1. Add resolver class.
2. Wire resolver into report service and inventory service.
3. Add flatten mode recursion safeguards.
4. Add semantics warning plumbing.
5. Add unit and feature tests.

Slice C: Frontend Report UI

1. Add Inertia page and filters.
2. Add branch context columns.
3. Add empty-state and conversion warning badges.
4. Add flattened-mode planning-only banner.
5. Add sensitive field masking behavior.

Slice D: CSV Export + Hardening

1. Add export endpoint.
2. Add CSV formula-injection mitigation.
3. Ensure export parity with shared row builder.
4. Add export tests.

## 15. Acceptance Criteria

1. Managers can view ingredient composition for all products from one report page.
2. Report can switch between direct and flattened sub-recipe views.
3. Flattened view is clearly labeled planning-only and does not imply live deduction behavior.
4. Branch-selected view shows stock and cost context for each ingredient.
5. Exported CSV matches applied filters and includes composition details.
6. Page and export row outputs are parity-consistent.
7. No cross-tenant data leakage.
8. Missing conversion rules are visible as report warnings, not fatal errors.

## 16. Risks and Mitigations

1. Risk: Circular recipe dependencies.
1. Mitigation: recursion visited-set and max depth.
2. Risk: Conversion mismatch between report and checkout.
1. Mitigation: shared `UnitConversionResolver` used by both, plus explicit planning-only semantics for flattened mode.
3. Risk: Large dataset response time.
1. Mitigation: row-level deterministic pagination, eager loading, export streaming, optional background export later.

## 17. File-Level Change Plan

New files:

1. `app/Http/Controllers/Inventory/ProductCompositionReportController.php`
2. `app/Services/Inventory/ProductCompositionReportService.php`
3. `app/Services/Inventory/UnitConversionResolver.php`
4. `resources/js/Pages/Inventory/ProductComposition/Index.jsx`
5. `tests/Feature/Inventory/ProductCompositionReportTest.php`
6. `tests/Unit/Inventory/UnitConversionResolverTest.php`

Modified files:

1. `routes/web.php`
2. `app/Services/InventoryService.php` (consume resolver)
3. `resources/js/Pages/Inventory/Dashboard/Index.jsx` (or current inventory reports navigation host)
