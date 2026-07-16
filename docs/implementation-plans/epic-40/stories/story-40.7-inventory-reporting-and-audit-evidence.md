# Story 40.7 Inventory Reporting and Audit Evidence

## 1. Status

Approved for Implementation

Date: 2026-07-16

## 2. Objective

Unify inventory reports around canonical movement, variance, stocktake, recipe, configuration, and reconciliation evidence without creating any new mutation path.

Story 40.7 turns the evidence built in Stories 40.1 through 40.6 into operator-facing and audit-facing reports. The goal is not one giant inventory report. The goal is a clearly separated reporting system where every result can be explained by canonical evidence.

The story is approved for implementation with the locked reporting, consistency, and export boundaries below.

## 3. User Story

As an inventory manager or auditor,
I want inventory reports to be separated by evidence type and user purpose,
so that I can explain current stock, movement history, physical counts, exceptions, configuration gaps, and usage patterns without relying on mutable summaries or hidden calculations.

## 4. Architecture Alignment

This story implements the reporting requirements from:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Inventory reports are evidence projections, not mutation surfaces.
2. Reports must distinguish operational current stock, stock cards, movement summaries, movement evidence, variance evidence, stocktake reconciliation, expected versus recorded usage, configuration gaps, and integrity exceptions.
3. Stock cards are one row per committed movement, ordered by branch movement sequence.
4. Movement summaries aggregate opening stock, total in, total out, net movement, and closing stock by product, branch, and period.
5. Branch-scoped movement sequences are the ordering authority for stock cards and stocktake watermarks.
6. Movement before, delta, and after quantities are immutable historical evidence.
7. System reconciliation variance is a system-integrity exception, not an ordinary operational variance.
8. Physical stock variance is a stocktake/counting result, not a negative-stock exception.
9. Expected usage must come from immutable independent sale-item, recipe-plan, or recipe-effect evidence where available.
10. Recorded usage must come from committed inventory movement evidence.
11. Reports must preserve tenant and branch permission boundaries.
12. CSV and print/export outputs must match screen filters and permission scope.
13. Reports must not invent accounting, tax, procurement, valuation, or cost-of-goods conclusions.
14. Offline inventory mutation remains prohibited.

## 5. Competitor and Industry Context

Publicly documented competitor behavior supports the high-level report model:

1. Mosaic Resto iQ publicly separates inventory reports, stock cards, movement reports, and physical count variance concepts.
2. StoreHub publicly validates demand for real-time inventory, movement visibility, multi-outlet reporting, ingredient monitoring, and waste insight.
3. UTAK reinforces the need for simple operator-facing terminology and learnable reporting surfaces.

IPOS intentionally goes beyond those public baselines through:

1. Branch movement sequence as the stock-card ordering authority.
2. Movement-derived versus operational-stock reconciliation.
3. Evidence-quality labels.
4. Report as-of watermarks.
5. Immutable recipe and adjustment snapshots.
6. Export parity and no-mutation tests.
7. Separation of negative-stock exceptions, physical count variance, system reconciliation exceptions, configuration gaps, and integrity exceptions.

These IPOS-specific controls are audit and integrity requirements. They should not be presented as simple competitor parity.

## 6. Existing Implementation Context

Current report and evidence surfaces:

| Area | Current artifact | Current behavior |
| --- | --- | --- |
| Inventory dashboard | `app/Http/Controllers/Inventory/InventoryDashboardController.php` and `resources/js/Pages/Inventory/Dashboard/Index.jsx` | Shows low/negative stock visibility and a compact recent movement summary for a selected branch. |
| Movement JSON API | `app/Http/Controllers/Inventory/InventoryMovementController.php` | Lists active-branch movements as JSON using `InventoryService::getMovementsForBranch()`. |
| Inventory visibility report | `app/Http/Controllers/Inventory/InventoryVisibilityReportController.php` and `InventoryVisibilityReportService` | Provides current stock visibility, expiry risk, low stock, slow movement, and CSV export. |
| Variance log report | `app/Http/Controllers/Inventory/VarianceLogController.php` | Lists and exports inventory variance logs, and owns mutation actions for variance status transitions. |
| Stocktake reports | `StocktakeReportController` and `StocktakeVarianceCsvExportService` | Shows stocktake summary and exports variance CSV with Story 40.5 reconciliation evidence fields. |
| Reconciliation service | `InventoryReconciliationService` | Calculates movement-derived stock versus operational current stock for a branch/product. |
| Recipe deduction evidence | `RecipeDeductionService` and inventory movement metadata | Stores recipe/ingredient deduction snapshots for finalized sale deductions. |
| Product composition report | `ProductCompositionReportController` and `ProductCompositionReportService` | Reports product composition and recipe/ingredient relationships. |
| RBAC | `RbacSeeder` | Existing relevant permissions include `view_inventory_reports`, `audit_inventory`, and `view_branch_inventory`. |

Current gaps:

1. Current stock visibility, stock card history, movement summary, exception reports, and reconciliation reports are not yet defined as separate report contracts.
2. The movement JSON endpoint is branch-context only and is not a stock-card report.
3. The dashboard movement summary is useful but not a canonical movement summary report.
4. Variance log reporting mixes negative-stock exceptions with other variance concepts unless the report explicitly filters and labels them.
5. `InventoryReconciliationService` exists, but there is no operator-facing reconciliation exception report with baseline and as-of semantics.
6. Expected versus recorded usage has no stable first-release definition.
7. Report exports exist in places, but there is no shared rule that export output must match screen filters, branch permissions, evidence quality, ordering, and row limits.
8. Branch resolution logic is repeated across report controllers.
9. Report routes do not yet make all Epic 40 reporting surfaces discoverable from one inventory reporting boundary.

## 7. Report Audience Split

Story 40.7 must separate operational reports from audit and integrity reports.

### 7.1 Operational Reports

Primary users:

1. Branch inventory controller.
2. Store manager.
3. Operations manager.

Reports:

1. Current Stock.
2. Stock Card.
3. Movement Summary.
4. Physical Count Variance.

Behavior:

1. Default terminology should be operator-friendly.
2. Primary tables should avoid overwhelming technical evidence fields.
3. Technical evidence is shown through an evidence drawer, detail panel, or export.
4. Screen and export permissions may be the same when the data is operational.

### 7.2 Audit and Integrity Reports

Primary users:

1. Inventory auditor.
2. Tenant administrator.
3. Support or implementation team.

Reports:

1. Negative Stock Exception.
2. System Reconciliation Exception.
3. Expected versus Recorded Inventory Usage.
4. Configuration Gaps.
5. Inventory Integrity Exceptions.

Behavior:

1. Defaults should emphasize exceptions and evidence quality.
2. Exports have stricter permissions than screen views.
3. Reports may link to remediation workflows, but the report services remain read-only.
4. Technical evidence fields are first-class in the report detail/export.

Do not place all reports as equal tabs in one ordinary branch-manager page.

## 8. Scope

### 8.1 In Scope

1. Define report contracts for:
   - current stock,
   - stock card,
   - movement summary,
   - negative stock exception,
   - physical count variance,
   - system reconciliation exception,
   - expected versus recorded inventory usage,
   - configuration gaps,
   - inventory integrity exceptions.
2. Introduce or refine read-only query services for Epic 40 inventory reports.
3. Reuse existing canonical tables:
   - `branch_inventories`,
   - `inventory_movements`,
   - `inventory_variance_logs`,
   - `inventory_variance_status_events`,
   - `inventory_variance_correction_links`,
   - `stocktake_sessions`,
   - `stocktake_lines`,
   - `product_recipes`,
   - `unit_conversions`.
4. Define report filters, sorting, pagination, date basis, as-of semantics, and export semantics.
5. Preserve tenant and branch permissions across screen and export outputs.
6. Add stable movement reporting categories while preserving raw movement type in detail.
7. Add baseline status and evidence-quality labels where needed.
8. Add CSV export limits and formula injection mitigation.
9. Add feature tests for permission boundaries, branch filtering, report calculations, export filter matching, export limits, and no-mutation behavior.
10. Update inventory reporting navigation or hub links only where needed to expose implemented reports.

### 8.2 Out of Scope

1. New inventory mutation endpoints.
2. Stocktake posting changes.
3. Manual adjustment behavior changes.
4. Unit conversion rule changes.
5. Recipe deduction mutation changes.
6. Procurement automation or reorder generation.
7. Accounting, inventory valuation, cost-of-goods, or liability reporting.
8. Forecasting or demand planning.
9. BI warehouse, scheduled reports, or background exports.
10. Offline report caching.
11. New approval workflows.
12. Full dashboard redesign.
13. All-product stock-card browsing. Use a future Branch Movement Journal if needed.

## 9. Locked Decisions

### 9.1 Reports Are Read-Only

No Story 40.7 report route, controller action, service, export, or UI interaction may:

1. Update `branch_inventories.current_stock`.
2. Create `inventory_movements`.
3. Create, update, or resolve `inventory_variance_logs`.
4. Post stocktakes.
5. Create adjustments.
6. Create purchase orders, supplier returns, or procurement drafts.

Variance status transitions remain in the existing variance lifecycle routes guarded by `audit_inventory`. They are not report-generation behavior.

### 9.2 Report Taxonomy Is Explicit

| Report | Primary question answered | Canonical evidence | Audience |
| --- | --- | --- | --- |
| Current Stock | What does the system currently think is on hand? | `branch_inventories` | Operational |
| Stock Card | What happened to this branch/product over time? | One row per `inventory_movements` row | Operational |
| Movement Summary | What moved in and out during a period? | Aggregated `inventory_movements` | Operational |
| Physical Count Variance | Where did physical count differ from expected stock? | `stocktake_lines` and `stocktake_sessions` | Operational/Audit |
| Negative Stock Exception | Where did policy permit shortage evidence? | `inventory_variance_logs` category `negative_stock` | Audit |
| System Reconciliation Exception | Where does current stock differ from movement-derived stock? | `branch_inventories` versus `inventory_movements` | Audit |
| Expected versus Recorded Inventory Usage | What sale-driven usage was expected or recorded, and what non-sale effects occurred? | Sale/recipe snapshots and `inventory_movements` | Audit |
| Configuration Gap | What setup prevents reliable inventory behavior? | Product, branch inventory, recipe, and conversion setup | Audit/Support |
| Inventory Integrity Exception | What evidence chain issues need investigation? | Movement chain, missing source evidence, negative stock without exception | Audit/Support |

### 9.3 Stock Card Requires Branch and Product

First-release stock card requires:

```text
branch_id
product_id
```

Reason:

1. Movement tables can become large.
2. Stock cards are item-specific ledgers.
3. Cross-product browsing is a different report.
4. Heavy exports should not be accidental.

Future report:

```text
Branch Movement Journal
```

### 9.4 Stock Card Ordering

Stock card rows are ordered by:

```text
tenant_id
branch_id
movement_sequence ASC
```

`created_at` and `posted_at` may be displayed, but they are not the stock-card ordering authority.

Primary stock-card columns:

1. sequence,
2. business date,
3. movement type or movement category,
4. source reference,
5. quantity before,
6. signed change,
7. quantity after,
8. unit,
9. reason,
10. actor.

Evidence drawer or export fields:

1. movement UUID,
2. source effect key,
3. raw movement type,
4. source type and source id,
5. conversion snapshot,
6. recipe snapshot,
7. adjustment approval snapshot,
8. stocktake posting snapshot,
9. exception linkage,
10. branch movement watermark.

### 9.5 Movement Reporting Categories

Reports must introduce stable movement reporting categories so raw movement types can evolve without breaking summaries.

Every report/export using categories must include:

```text
movement_category_mapping_version
```

The classifier is deterministic:

```text
raw movement type
+ signed quantity direction where needed
= reporting category
```

Example:

```text
manual_adjustment + positive quantity_change = adjustment_in
manual_adjustment + negative quantity_change = adjustment_out
```

First-release movement categories:

```text
sales_out
sales_return_in
receiving_in
supplier_return_out
transfer_in
transfer_out
adjustment_in
adjustment_out
stocktake_correction
opening_balance
migration_baseline
other
```

Raw movement type remains visible in details and exports.

Movement summary filters can include:

```text
movement_category
movement_type
source_type
```

### 9.6 Movement Summary Formula and Baseline Quality

Movement summary supports business-date activity reporting in first release.

Do not calculate opening stock by selecting the latest `quantity_after` row by business date. Late-posted rows can have an earlier business date while their stored `quantity_after` already includes later ledger-sequence effects.

Approved first-release calculation:

```text
summary_calculation_basis = business_date_activity
ledger_as_of_sequence = captured branch movement watermark

opening_stock = authoritative baseline
              + sum signed movements where business_date < date_from
                and movement_sequence <= ledger_as_of_sequence
period_in = sum positive quantity_change where date_from <= business_date <= date_to
            and movement_sequence <= ledger_as_of_sequence
period_out = absolute sum negative quantity_change where date_from <= business_date <= date_to
             and movement_sequence <= ledger_as_of_sequence
net_movement = period_in - period_out
closing_stock = opening_stock + net_movement
```

This preserves business-date reporting while keeping the arithmetic bounded by captured ledger evidence.

Future optional mode:

```text
summary_calculation_basis = ledger_period
opening_stock = quantity_after at the last sequence before the posting boundary
```

Opening stock basis:

```text
complete
baseline_only
no_prior_movement
reconciliation_mismatch
legacy_unavailable
```

Zero-movement and baseline-only rules:

| Condition | Output |
| --- | --- |
| Valid baseline, no period activity | Opening equals closing; stock in/out are zero |
| No baseline and no movement | Evidence unavailable |
| Migration baseline only | `opening_stock_basis = migration_baseline` |
| Branch inventory exists but ledger is empty | Configuration or integrity warning |

If no prior movement or valid baseline exists:

```text
opening_stock = 0
opening_stock_basis = no_prior_movement
evidence_quality = unavailable
```

This is arithmetic scaffolding, not a claim that true physical opening stock was zero.

Operational current stock may be displayed for comparison, but it must be labelled separately from movement-derived closing stock.

### 9.7 Date Basis Per Report

Do not silently mix date bases in one result set.

| Report | Default date basis |
| --- | --- |
| Current Stock | As-of current operational state |
| Stock Card | Movement sequence ordered, filtered by `business_date` |
| Movement Summary | `business_date` |
| Negative Stock Exception | Source business date; event date optional |
| Physical Count Variance | Count date or post date, explicitly selected |
| System Reconciliation Exception | As-of generated time |
| Expected versus Recorded Inventory Usage | `business_date` |
| Configuration Gap | As-of generated time |
| Inventory Integrity Exception | As-of generated time |

Legacy fallback:

```text
date_basis = posted_at_fallback
evidence_quality = legacy
```

### 9.8 Report As-Of Semantics

Every report response and export must include:

```text
generated_at
date_basis
branch_scope
filter_fingerprint
```

Movement-backed reports must also include:

```text
data_as_of_movement_sequence
data_as_of_timestamp
consistency_level
```

For multi-branch reports:

```text
branch_watermarks[]
```

Each branch watermark includes:

```text
branch_id
latest_movement_sequence
latest_movement_posted_at
```

This allows auditors to understand the sequence boundary reflected by a report or export.

Captured watermarks are query constraints, not only metadata. Movement-backed queries and exports must apply:

```text
inventory_movements.movement_sequence <= captured_branch_watermark
```

For multi-branch reports, apply each branch's own captured maximum sequence.

Recommended consistency levels:

| Report | Consistency level |
| --- | --- |
| Stock Card | `sequence_bounded` |
| Movement Summary | `sequence_bounded` |
| Negative Stock Exception | `sequence_bounded` plus lifecycle as-of timestamp |
| Current Stock | `operational_snapshot` or `best_effort` |
| System Reconciliation Exception | `operational_snapshot` |
| Configuration Gap | `best_effort` |
| Inventory Integrity Exception | Depends on check |

### 9.9 System Reconciliation Baseline and Watermark

Reconciliation formula:

```text
movement_derived_stock = sum inventory_movements.quantity_change
system_reconciliation_variance = branch_inventories.current_stock - movement_derived_stock
```

This is valid only when a baseline exists.

Baseline status:

```text
complete
migration_baseline
opening_balance
missing
legacy_unverifiable
```

Reconciliation status:

```text
reconciled
exception
indeterminate
```

Rules:

1. If no opening or migration baseline exists, reconciliation status is `indeterminate`, not a confirmed variance.
2. Rows with absolute variance below `0.0001` are reconciled when baseline status is adequate.
3. Default view shows only `exception` and `indeterminate`.
4. Optional filter may include reconciled rows.
5. A reconciliation report does not create correction records.

As-of flow:

1. Capture latest branch movement sequence.
2. Read movement-derived balance through that sequence.
3. Read operational stock and `inventory_revision`.
4. Return watermark and revision.
5. If mutation is detected during the query, retry or mark result potentially stale.

Current Stock and Reconciliation responses include:

```text
inventory_revision
latest_movement_sequence
generated_at
```

For multi-product reports, these values may be per row. If movement sequence or inventory revision changes during a reconciliation calculation, retry within a bounded limit or return:

```text
consistency_status = potentially_stale
```

Do not claim an exact reconciliation snapshot when the operational stock row changed during the query.

### 9.10 Expected versus Recorded Inventory Usage

Do not call this report "Theoretical versus Actual Consumption" in first release.

Approved name:

```text
Expected versus Recorded Inventory Usage
```

Acceptable alternate UI label:

```text
Recipe Usage Reconciliation
```

Definitions:

Independent expected sale-driven usage:

```text
immutable sale-item recipe snapshot
or persisted pre-movement RecipeDeductionPlan
or separate expected recipe effect record
```

Recorded sale-driven usage:

```text
committed ingredient sale-deduction inventory movements
```

Non-sale inventory effects:

```text
stocktake corrections
manual adjustments
damage
expiry
spoilage
shrinkage
supplier returns
transfers
other controlled non-sale movement categories
```

Rules:

1. Do not derive expected usage from the same committed movement rows used as recorded usage.
2. Do not recalculate historical expectations from current recipe definitions.
3. If independent expected evidence is unavailable, show:

```text
expected_usage = null
expected_usage_status = unavailable
```

4. In that case, show recorded sale-driven deductions and non-sale effects only.
5. Do not imply physical actual consumption unless physical counts provide a reliable period boundary and all movements are classified.
6. Do not treat recipe metadata stored on the same movement row as both expected usage and recorded usage.

### 9.11 Configuration Gaps Versus Integrity Exceptions

Configuration gaps are setup problems.

Examples:

1. Inventory-tracked product missing branch inventory.
2. Recipe ingredient missing branch inventory.
3. Required unit conversion missing or inactive.
4. Composite product has recipe gaps.
5. Invalid tracking configuration.
6. Product movements missing base unit metadata.

Inventory integrity exceptions are evidence-chain problems.

Examples:

1. Current stock/movement-derived mismatch.
2. Negative current stock without exception evidence.
3. Movement chain discontinuity.
4. Missing source effect key where required.
5. Incomplete stocktake posting evidence.

The implementation may place both categories on one audit page, but they must be labelled separately.

### 9.12 Gap Severity

Configuration and integrity reports must expose:

```text
gap_code
severity
affected_capability
product
branch
evidence
recommended_setup_page
owner_type
remediation_capability
remediation_permission
```

Severity values:

```text
blocking
high
warning
informational
```

Examples:

| Gap | Severity |
| --- | --- |
| Recipe ingredient missing branch inventory | blocking |
| Required conversion missing | blocking |
| Product movements missing base unit | high |
| Current stock mismatch | high |
| Negative stock without exception evidence | high |
| Inactive product with inventory balance | warning |
| No prior movement history | informational or high depending migration status |

Ownership examples:

| Issue | Owner type |
| --- | --- |
| Missing branch inventory | Inventory administrator |
| Missing conversion | Catalog administrator |
| Movement chain discontinuity | Support/engineering |
| Current-stock mismatch | Inventory auditor |
| Incomplete stocktake evidence | Inventory/support |

### 9.13 Movement Chain Integrity

Stock-card and integrity reports may detect:

```text
previous movement quantity_after != next movement quantity_before
```

Chain status:

```text
continuous
discontinuous
legacy_unverifiable
```

Do not treat legacy rows without complete before/after evidence as confirmed corruption.

Use separate state dimensions rather than one overloaded enum:

```text
evidence_quality
baseline_status
opening_stock_basis
reconciliation_status
chain_status
expected_usage_status
```

### 9.14 Export Permissions

Operational report screen/export permissions may match.

Audit and integrity report screen access may use:

```text
view_inventory_reports
or
audit_inventory
```

Audit and integrity exports require:

```text
audit_inventory
```

If operations needs broader export access later, add a dedicated permission:

```text
export_inventory_reports
```

Do not overload `view_inventory_reports` for all sensitive exports.

Successful audit/integrity exports must write a lightweight audit event containing:

```text
report_type
user_id
tenant_id
branch_scope
filter_fingerprint
generated_at
row_count
watermarks
filename
```

Do not write the exported dataset into the audit log.

### 9.15 Export Scope Limits

Because Story 40.7 excludes background exports, synchronous CSV exports need limits.

First-release guardrails:

1. Stock card export requires branch and product.
2. Stock card export maximum date range is 12 months.
3. Movement summary export requires branch and maximum 12-month range.
4. Audit reports enforce a configured maximum row count.
5. Audit reports may require narrowing filters before export.

If the request exceeds limits:

```text
422 EXPORT_SCOPE_TOO_LARGE
```

Do not silently truncate export rows.

### 9.16 Filter DTOs

Each report must use one immutable filter object for:

1. screen query,
2. export query,
3. audit metadata,
4. filename summary,
5. filter fingerprint.

Recommended examples:

```text
StockCardReportFilter
MovementSummaryReportFilter
NegativeStockExceptionReportFilter
PhysicalCountVarianceReportFilter
ReconciliationExceptionReportFilter
UsageReconciliationReportFilter
InventoryIntegrityReportFilter
```

Do not parse filters separately for screen and export actions.

### 9.17 Current Stock Is Operational, Not Historical

The Current Stock report is a current operational projection.

It must not offer arbitrary historical as-of dates. Historical stock questions belong to the Stock Card, Movement Summary, or Reconciliation reports.

The `generated_at` metadata means when the current projection was read. It does not make the current-stock projection historically reproducible unless the implementation reconstructs the state from movement evidence.

## 10. Report Contracts

### 10.1 Current Stock Report

Audience:

Operational.

Purpose:

Show current branch/product inventory state for operations.

This is a current operational projection, not a historical as-of stock report.

Source:

```text
branch_inventories
products
branches
expiry_lots where available
last inventory movement where available
```

Required filters:

1. branch,
2. category,
3. product search,
4. stock state: all, normal, low, negative,
5. expiry risk where applicable.

Required columns:

1. branch,
2. product name,
3. SKU/barcode,
4. category,
5. current stock,
6. reorder level,
7. base unit,
8. stock state,
9. next expiry date where applicable,
10. last movement date,
11. last sale date where available,
12. inventory revision,
13. latest movement sequence,
14. consistency status.

Existing `InventoryVisibilityReportService` is the likely starting point.

### 10.2 Stock Card Report

Audience:

Operational with audit detail available.

Purpose:

Show one product/branch movement ledger in branch movement sequence order.

Required filters:

1. branch required,
2. product required,
3. date range,
4. movement category,
5. raw movement type,
6. source type,
7. source reference search.

Primary columns:

1. movement sequence,
2. business date,
3. movement category,
4. movement type,
5. source reference,
6. quantity before,
7. quantity change,
8. quantity after,
9. unit,
10. reason,
11. actor.

Evidence drawer/export:

1. movement UUID,
2. source effect key,
3. conversion snapshot,
4. recipe snapshot,
5. adjustment approval,
6. stocktake posting snapshot,
7. exception linkage,
8. chain status.

Pagination:

1. Use sequence-based cursor pagination, not offset pagination.
2. For descending screen views, use `movement_sequence < cursor`.
3. For canonical ascending exports, use `movement_sequence > cursor`.
4. Always bound pagination by the captured maximum sequence.

### 10.3 Movement Summary Report

Audience:

Operational.

Purpose:

Aggregate movement activity by branch, product, movement category, and period.

Required filters:

1. branch required,
2. date range maximum 12 months for export,
3. category,
4. product search,
5. movement category,
6. movement type/source type.

Required output:

1. opening stock,
2. opening stock basis,
3. total stock in,
4. total stock out,
5. net movement,
6. movement-derived closing stock,
7. operational current stock comparison,
8. movement count,
9. first and last movement sequence in period,
10. evidence quality,
11. summary calculation basis,
12. ledger as-of sequence,
13. movement category mapping version.

### 10.4 Negative Stock Exception Report

Audience:

Audit and integrity.

Purpose:

Surface Story 40.3 soft-negative deduction exceptions and lifecycle.

Source:

```text
inventory_variance_logs where variance_category = negative_stock
inventory_variance_status_events
inventory_variance_correction_links
inventory_movements
sales
products
```

Required filters:

1. branch,
2. source business date range,
3. event date range optional,
4. status,
5. policy,
6. severity,
7. product/ingredient search,
8. sale/source reference.

Required columns:

1. branch,
2. product/ingredient,
3. source sale/reference,
4. movement sequence,
5. incremental shortage quantity,
6. resulting negative quantity,
7. current status,
8. severity,
9. age,
10. recurrence indicator,
11. correction link count,
12. latest status event,
13. status basis,
14. lifecycle as-of timestamp.

Do not call this report "Negative Stock Variance Report" in UI copy. Internal category may remain `negative_stock`.

Recommended first-release status mode:

```text
status_basis = current_at_generated_time
```

Do not imply the displayed lifecycle status was the same on the source business date.

### 10.5 Physical Count Variance Report

Audience:

Operational and audit.

Purpose:

Explain physical count differences from Story 40.5 stocktake evidence.

Required filters:

1. branch,
2. stocktake session,
3. count date or post date basis,
4. posted/review status,
5. product/category,
6. variance direction: positive, negative, zero, all.

Primary columns:

1. stocktake number,
2. branch,
3. product,
4. counted quantity,
5. expected at count time,
6. physical count variance,
7. posted variance,
8. posting outcome,
9. evidence quality.

Evidence drawer/export:

1. count snapshot UUID,
2. physically counted at,
3. count recorded at,
4. operation mode,
5. scope type,
6. count-start watermark,
7. counted watermark,
8. movement-after-count delta,
9. posting movement sequence,
10. projection policy version.

### 10.6 System Reconciliation Exception Report

Audience:

Audit and integrity.

Purpose:

Detect where operational stock state no longer agrees with movement evidence.

Required filters:

1. branch,
2. product/category,
3. show reconciled rows toggle,
4. baseline status,
5. reconciliation status,
6. minimum absolute variance threshold.

Required columns:

1. branch,
2. product,
3. operational current stock,
4. movement-derived stock,
5. system reconciliation variance,
6. baseline status,
7. reconciliation status,
8. inventory revision,
9. last movement sequence,
10. data as-of watermark,
11. consistency status,
12. recommended investigation entry point.

### 10.7 Expected Versus Recorded Inventory Usage

Audience:

Audit and integrity.

Purpose:

Provide a defensible first-release usage-reconciliation foundation without pretending to be physical consumption or cost accounting.

Required filters:

1. branch,
2. business date range,
3. product/ingredient,
4. category,
5. evidence quality,
6. movement category.

Required output:

1. item/product,
2. expected sale-driven usage where independently available,
3. expected usage status,
4. recorded sale-driven deduction movements,
5. non-sale inventory effects by category,
6. stocktake correction impact,
7. manual adjustment impact by reason category,
8. expected versus recorded variance only when evidence permits,
9. evidence quality,
10. independent expected evidence source.

If expected evidence is unavailable, show:

```text
expected_usage_status = unavailable
```

Do not calculate expected usage from current recipes.

### 10.8 Configuration Gap Report

Audience:

Audit and support.

Purpose:

Identify setup problems that make inventory reports or inventory operations unreliable.

First-release gap types:

1. Inventory-tracked product missing branch inventory.
2. Branch inventory exists for inactive or non-inventory-tracked product.
3. Composite product has recipe gaps.
4. Recipe ingredient lacks branch inventory.
5. Required unit conversion is missing or inactive.
6. Product has movements but missing base unit metadata.

Required output:

1. gap code,
2. severity,
3. affected capability,
4. branch,
5. product,
6. evidence,
7. recommended setup page,
8. owner type,
9. remediation capability,
10. remediation permission.

### 10.9 Inventory Integrity Exception Report

Audience:

Audit and support.

Purpose:

Identify evidence-chain issues that are not ordinary configuration gaps.

First-release exception types:

1. Current-stock/movement-derived mismatch.
2. Negative current stock without negative-stock exception evidence.
3. Movement chain discontinuity.
4. Missing source effect key where required.
5. Incomplete stocktake posting evidence.

Required output:

1. exception code,
2. severity,
3. branch,
4. product,
5. evidence summary,
6. chain status where applicable,
7. baseline status where applicable,
8. recommended investigation entry point.

## 11. Technical Design

### 11.1 Controllers

Do not implement all reports in one large controller.

Recommended controllers:

```text
App\Http\Controllers\Inventory\Reports\CurrentStockReportController
App\Http\Controllers\Inventory\Reports\StockCardReportController
App\Http\Controllers\Inventory\Reports\MovementSummaryReportController
App\Http\Controllers\Inventory\Reports\NegativeStockExceptionReportController
App\Http\Controllers\Inventory\Reports\PhysicalCountVarianceReportController
App\Http\Controllers\Inventory\Reports\ReconciliationExceptionReportController
App\Http\Controllers\Inventory\Reports\UsageReconciliationReportController
App\Http\Controllers\Inventory\Reports\InventoryIntegrityReportController
```

Configuration gap reporting may be included in `InventoryIntegrityReportController` if the categories remain clearly separated.

### 11.2 Services

Use thin controllers and report-specific query services.

Recommended services:

```text
App\Services\Inventory\Reports\InventoryReportScopeService
App\Services\Inventory\Reports\InventoryCsvExportService
App\Services\Inventory\Reports\MovementCategoryClassifier
App\Services\Inventory\Reports\ReportWatermarkService
App\Services\Inventory\Reports\CurrentStockReportService
App\Services\Inventory\Reports\StockCardReportService
App\Services\Inventory\Reports\MovementSummaryReportService
App\Services\Inventory\Reports\NegativeStockExceptionReportService
App\Services\Inventory\Reports\PhysicalCountVarianceReportService
App\Services\Inventory\Reports\ReconciliationExceptionReportService
App\Services\Inventory\Reports\UsageReconciliationReportService
App\Services\Inventory\Reports\InventoryIntegrityReportService
```

Reuse existing services where appropriate:

```text
InventoryVisibilityReportService
InventoryReconciliationService
StocktakeVarianceCsvExportService
ProductCompositionReportService
```

Do not duplicate behavior if an existing service can be safely refined.

### 11.3 Route Shape

Recommended route group:

```text
/inventory/reports/current-stock
/inventory/reports/stock-card
/inventory/reports/movement-summary
/inventory/reports/negative-stock-exceptions
/inventory/reports/physical-count-variance
/inventory/reports/reconciliation-exceptions
/inventory/reports/usage-reconciliation
/inventory/reports/integrity
```

Each report may expose:

```text
GET index
GET export
```

Existing routes may be kept if backwards compatibility matters, but report naming should become explicit.

### 11.4 Report Landing Page

Use a reports landing page plus separate routes/pages.

Rules:

1. The landing page is a directory.
2. Each report owns its route, filters, permissions, exports, and tests.
3. Do not load all report state into one massive tabbed page.

### 11.5 Shared Branch Scope

`InventoryReportScopeService` should:

1. Resolve tenant context.
2. Resolve accessible branch IDs for authenticated user.
3. Validate requested branch filters.
4. Return display branch options.
5. Fail closed for unauthorized branch filters.

It must respect:

1. `view_multi_branch_dashboard`,
2. user branch assignments,
3. active `BranchContext`,
4. tenant context.

### 11.6 Export Helper

`InventoryCsvExportService` should handle:

1. formula injection mitigation,
2. filter metadata rows,
3. generated timestamp,
4. report as-of metadata,
5. consistent filename generation,
6. maximum row/range errors.

Do not introduce a large reporting framework.

## 12. UI Requirements

First-release UI should be simple and report-focused.

Rules:

1. Add or extend an Inventory Reports landing page in the Inventory Hub.
2. Separate operational reports from audit and integrity reports.
3. Use dense operational tables.
4. Use clear names:
   - Current Stock,
   - Stock Card,
   - Movement Summary,
   - Physical Count Variance,
   - Negative Stock Exceptions,
   - Reconciliation Exceptions,
   - Usage Reconciliation,
   - Configuration and Integrity.
5. Use primary columns for operators.
6. Place technical evidence in an evidence drawer, detail panel, or export.
7. Export buttons must use current filters.
8. Do not add inline editing or mutation buttons to read-only reports.

Existing pages that may be reused or extended:

```text
resources/js/Pages/Inventory/Hub/Index.jsx
resources/js/Pages/Inventory/Dashboard/Index.jsx
resources/js/Pages/Inventory/Visibility/Index.jsx
resources/js/Pages/Inventory/VarianceLogs/Index.jsx
resources/js/Pages/Inventory/Stocktake/Summary.jsx
resources/js/Pages/Inventory/ProductComposition/Index.jsx
```

New pages, if needed:

```text
resources/js/Pages/Inventory/Reports/
```

## 13. Data and Calculation Rules

### 13.1 Decimal Policy

Use decimal string or four-decimal presentation consistently for stock quantities.

Since Story 40.7 is read-only, no persisted report snapshot is expected in first release.

### 13.2 Period Boundaries

Date ranges:

1. `date_from` inclusive start of day.
2. `date_to` inclusive end of day.
3. Default period is report-specific.
4. Export maximum range is enforced per report.

### 13.3 Movement Sign Policy

Use `inventory_movements.quantity_change` as the signed movement value.

For summaries:

```text
stock_in = sum(quantity_change > 0)
stock_out = abs(sum(quantity_change < 0))
net = stock_in - stock_out
```

Do not infer direction from movement type alone.

### 13.4 Evidence Quality

General `evidence_quality` values:

```text
complete
partial
legacy
inferred
unavailable
legacy_unverifiable
```

Report-specific states must remain separate fields:

```text
baseline_status
opening_stock_basis
reconciliation_status
chain_status
expected_usage_status
```

Examples:

1. Stocktake lines without watermarks: `legacy`.
2. Recipe deduction without independent expected snapshot: `partial`.
3. Product with no movement history: `evidence_quality = unavailable` and `opening_stock_basis = no_prior_movement`.
4. Movement chain without before/after evidence: `legacy_unverifiable`.

## 14. Authorization and Security

Rules:

1. All report queries are tenant-scoped.
2. Branch-limited users are branch-scoped.
3. Unauthorized branch filters return `403`, not empty misleading reports.
4. CSV exports use the same filter DTO as screen reports.
5. Numeric and decimal CSV fields are serialized using validated numeric formatting.
6. Formula protection applies to user-controlled or textual fields beginning with `=`, `+`, `-`, or `@`.
7. Negative numeric values such as `-5.0000` must remain numeric output.
8. Unsafe text values such as remarks beginning with `-cmd` must be neutralized as text.
9. Cost and valuation fields remain out of scope.
10. Operational exports can use report-view permission.
11. Audit/integrity exports require `audit_inventory` unless a future `export_inventory_reports` permission is introduced.
12. Exports must not silently truncate rows.

## 15. Testing Requirements

Feature tests must cover:

1. Current stock report branch filtering and export parity.
2. Stock card requires branch and product.
3. Stock card orders by movement sequence.
4. Stock card shows before/change/after from movement rows.
5. Movement summary opening, in, out, net, and closing calculations.
6. Movement summary exposes opening stock basis and evidence quality.
7. Movement summary does not use `branch_inventories.current_stock` as its closing-stock source.
8. Movement category normalization groups technical movement types while preserving raw movement type in detail.
9. Negative Stock Exception report uses Story 40.3 fields and excludes stocktake physical count variance.
10. Physical Count Variance report uses Story 40.5 count snapshot identity, operation mode, scope type, and posting evidence.
11. System reconciliation report returns `indeterminate` when baseline is missing.
12. System reconciliation report detects current-stock/movement-derived mismatch when baseline is adequate.
13. Reconciliation reports include generated time and branch movement watermark.
14. Expected versus Recorded Inventory Usage marks expected usage unavailable when independent immutable expected evidence is missing.
15. Configuration gaps and inventory integrity exceptions are labelled separately.
16. Movement-chain discontinuity is detected where before/after evidence is complete.
17. Legacy movement chains without complete evidence are `legacy_unverifiable`, not confirmed corruption.
18. Branch-limited user cannot see another branch on screen or export.
19. Audit/integrity exports require `audit_inventory`.
20. CSV export matches screen filter DTO.
21. CSV output preserves negative numeric values while neutralizing formula-like text values.
22. Export requests exceeding row or date limits return `422 EXPORT_SCOPE_TOO_LARGE`.
23. Business-date movement summary opening/closing balances are derived from a valid baseline plus signed movements through the captured ledger watermark.
24. Movement-backed queries and exports exclude rows above the captured branch watermark.
25. Negative Stock Exception reports identify current lifecycle status as `current_at_generated_time`.
26. Reports combining movement and operational stock include consistency level, movement watermark, and inventory revision where applicable.
27. Stock-card pagination uses sequence-based cursors bounded by the captured upper watermark.
28. Audit/integrity exports write a lightweight export audit event.
29. Report routes do not mutate:
    - `branch_inventories`,
    - `inventory_movements`,
    - `inventory_variance_logs`,
    - `stocktake_sessions`,
    - `stocktake_lines`.

Existing test files that should inform implementation:

```text
tests/Feature/Inventory/InventoryVisibilityReportTest.php
tests/Feature/Inventory/InventoryDashboardTest.php
tests/Feature/Inventory/InventoryMovementTest.php
tests/Feature/Inventory/StocktakeReportTest.php
tests/Feature/Inventory/VarianceLogAuditingTest.php
tests/Feature/Inventory/ProductCompositionReportTest.php
tests/Feature/POS/InventoryMovementVisibilityTest.php
```

Recommended new test file:

```text
tests/Feature/Inventory/InventoryReportingAuditEvidenceTest.php
```

## 16. Implementation Slices

### Slice 1 - Shared Reporting Contracts

Deliver:

1. Branch scope resolver.
2. Report filter DTOs.
3. Movement reporting categories.
4. Shared CSV safety and export metadata.
5. Report as-of watermarks.

### Slice 2 - Core Operational Reports

Deliver:

1. Current stock.
2. Stock card.
3. Movement summary.
4. Physical count variance.

### Slice 3 - Exception Reports

Deliver:

1. Negative-stock exception report.
2. System reconciliation with baseline status.
3. Configuration gap categories.
4. Inventory integrity exception categories.

### Slice 4 - Usage Reconciliation Foundation

Deliver:

1. Recorded sale-driven deductions.
2. Non-sale movement classification.
3. Evidence-quality logic.
4. Expected usage only where independent evidence exists.

### Slice 5 - Security, Exports, and Regression

Deliver:

1. Export permissions.
2. Row/range limits.
3. Filter parity tests.
4. No-mutation tests.
5. Existing report regression tests.
6. Documentation status update.

If implementation pressure is high, split delivery into:

```text
40.7A Core Inventory Reports
40.7B Audit and Reconciliation Reports
```

Do not let usage reconciliation delay stock-card and movement-summary delivery.

## 17. Acceptance Criteria

Story 40.7 is accepted when:

1. Reports are separated into operational and audit/integrity groups.
2. A report landing page links to separate report routes/pages instead of one large all-report tab surface.
3. Stock card requires branch and product in first release.
4. Stock cards show one row per movement ordered by branch movement sequence.
5. Stock-card technical source details are available through evidence detail or export.
6. Movement summaries calculate opening, in, out, net, and closing from movement evidence.
7. Movement summaries expose baseline quality and do not imply zero opening stock is physically true when no prior movement exists.
8. Movement summaries group by stable reporting categories while preserving raw movement type in detail.
9. Current stock reports remain operational state views and do not pretend to be movement summaries.
10. Negative-stock exceptions, physical-count variance, system reconciliation exceptions, configuration gaps, and integrity exceptions are separate concepts.
11. System reconciliation includes baseline status, reconciliation status, inventory revision, and as-of movement watermark.
12. Missing baseline produces `indeterminate`, not a confirmed variance.
13. Physical-count variance exposes accepted count identity and Story 40.5 stocktake evidence.
14. Expected versus Recorded Inventory Usage does not calculate expected usage from current recipes or from the same movement evidence being compared.
15. Expected usage is marked unavailable when independent immutable expected evidence is unavailable.
16. Configuration gaps are severity-labelled setup issues.
17. Integrity exceptions are evidence-chain issues, not configuration gaps.
18. Exports match screen filter DTOs.
19. Audit/integrity exports require `audit_inventory` or a future dedicated export permission.
20. Exports include generated time, date basis, filter metadata, and branch movement watermark where applicable.
21. Exports reject overly broad requests with `422 EXPORT_SCOPE_TOO_LARGE`.
22. Branch-limited users cannot view or export unassigned branch data.
23. Report routes and exports are read-only.
24. Formula-injection mitigation is applied to CSV output.
25. Relevant feature tests pass.
26. No Epic 40 Architecture Lock constraints are violated.
27. Business-date movement summaries calculate opening and closing balances from an authoritative baseline plus signed movements through the captured ledger watermark, not from a `quantity_after` row selected only by business date.
28. Movement-backed reports apply captured branch watermarks as query constraints.
29. CSV exports keep negative numeric quantities valid while neutralizing unsafe text values.
30. Negative-stock lifecycle status identifies whether it is current at generation time or historical as-of status.
31. Reports combining movement and operational-stock evidence include consistency level, movement watermark, and relevant inventory revision.
32. Stock-card pagination is cursor-stable while new movements are appended.

## 18. Definition of Done

Done means:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Frontend build passes if UI is touched.
4. Tenant and branch isolation tests pass.
5. CSV export parity tests pass.
6. Report calculation tests pass.
7. Export scope-limit tests pass.
8. No report endpoint mutates inventory state.
9. Existing inventory dashboard, visibility report, variance report, and stocktake report tests remain green.
10. Documentation status is updated.
11. Local PR review is completed before commit.

## 19. Non-Goals Reminder

Story 40.7 must not become:

1. Inventory valuation.
2. Cost accounting.
3. Procurement planning.
4. Forecasting.
5. Stock correction workflow.
6. A new mutation workflow hidden behind reports.
7. A replacement for stocktake, adjustment, or variance lifecycle services.
8. A background export system.
