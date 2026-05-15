# Story 14.2: Tax Breakdown Source-of-Truth Hardening

Status: Service Preparation In Progress 2026-05-13

Implementation status summary:

- Slice A: Implemented 2026-05-13
- Slice B: Implemented 2026-05-13
- Slice C: Implemented 2026-05-13
- Slice D: Implemented 2026-05-13
- Slice E: Implemented 2026-05-13
- Model Preparation Slice A: Implemented 2026-05-13
- Model Preparation Slice B: Implemented 2026-05-13
- Model Preparation Slice C: Implemented 2026-05-13
- Model Preparation Slice D: Implemented 2026-05-13
- Controlled Migration Slice: Implemented 2026-05-13
- Tax Snapshot Preparation Slice: Implemented 2026-05-13

## 1. Goal

Define and implement the minimum immutable data model and write-path contract needed for PH tax reporting, statutory discount evidence, and invoice-grade reconstruction so later Epic 14 reporting, export, and lock stories consume one source of truth.

## 2. Current Repository Anchors

Story 14.2 should extend the repository surfaces that already own sale evidence, receipt output, and additive reversals:

- `database/migrations/2026_05_10_164800_create_sales_table.php` already defines immutable sale header totals, making `sales` the correct anchor for compliance header buckets and invoice evidence.
- `database/migrations/2026_05_10_164810_create_sale_items_table.php` already defines immutable line-level tax snapshots, making `sale_items` the correct anchor for PH reporting buckets.
- `app/Models/Sale.php` already blocks mutation of financial totals and identity fields, making it the correct model anchor for write-once compliance header fields.
- `app/Models/SaleItem.php` already enforces immutability, making it the correct anchor for line-level compliance fields.
- `app/Services/POS/SaleCreationService.php` already computes sale and line snapshots, making it the correct anchor for PH bucket persistence.
- `app/Services/POS/PaymentRecordingService.php` already finalizes paid-sale state and emits grouped tax payloads, making it the correct anchor for reporting-basis finalization and invoice evidence attachment.
- `app/Services/POS/ReceiptService.php` already formats read-only receipt output, making it the correct consumer anchor for later invoice-grade rendering.
- `app/Services/POS/RefundService.php` and `app/Services/POS/VoidService.php` already create additive reversals, making them the correct anchors for compliance adjustment evidence.
- `app/Services/Settlement/SettlementSummaryQueryService.php` already applies period boundaries, making it the correct downstream anchor for later disclosure and prior-period impact logic.

## 3. Purpose and Outcomes

By the end of Story 14.2, IPOS should support:

- immutable transaction-level PH reporting buckets on sales
- immutable line-level PH reporting buckets on sale items
- append-only statutory discount evidence separated from commercial discounts
- machine-profile and principal invoice references attached to historical sale evidence
- additive reversal metadata sufficient for later prior-period disclosure logic

## 4. Non-Goals

Story 14.2 should explicitly avoid:

- implementing eAccReg, PTU, or ATG submission workflows
- building final BIR export layouts or UI screens
- implementing commercial promotion or discount-engine logic
- redesigning all tenant or branch configuration screens
- claiming BIR accreditation or certification from repository-side field additions alone

## 5. Implementation Boundaries

Implement only:

1. immutable sale header compliance fields
2. immutable sale item PH bucket fields
3. append-only statutory discount evidence records
4. machine-profile and principal invoice reference persistence
5. additive compliance adjustment metadata for refunds and voids
6. tests proving historical reconstruction does not depend on current catalog or config state

Do not implement yet:

- final tax-report UI
- final compliance export package
- e-invoicing transmission
- supplier accreditation workflow automation
- commercial discount policy management

## 6. Data Model Direction

### 1. Machine and invoice profile reference

Introduce a branch-scoped or tenant-scoped compliance profile model to capture the repository-side fields later receipt and export surfaces must reference.

Recommended model: `sales_machine_profiles`

Suggested fields:

- `id`
- `tenant_id`
- `branch_id` nullable for tenant-wide defaults
- `profile_code`
- `machine_identification_number` nullable
- `machine_serial_number` nullable
- `software_license_number` nullable
- `permit_to_use_number` nullable
- `permit_issued_at` nullable
- `authority_to_generate_control_number` nullable
- `supplier_name` nullable
- `supplier_tin` nullable
- `supplier_branch_code` nullable
- `supplier_address` nullable
- `supplier_accreditation_number` nullable
- `supplier_accreditation_issued_at` nullable
- `supplier_accreditation_expires_at` nullable
- `status`

Purpose:

- let the repository persist invoice and machine identifiers without claiming to automate eAccReg submission flows
- keep historical sales tied to the machine profile used when the invoice evidence was generated

### 2. Immutable sale compliance header

Extend `sales` with compliance reconstruction fields.

Suggested fields:

- `sales_machine_profile_id` nullable
- `principal_invoice_number` nullable
- `principal_invoice_type` nullable (`vat`, `non_vat`)
- `principal_invoice_label` nullable defaulting later to invoice-first wording
- `invoice_issued_at` nullable
- `reporting_basis_at` nullable
- `gross_sales_amount` decimal
- `vatable_sales_amount` decimal
- `vat_exempt_sales_amount` decimal
- `zero_rated_sales_amount` decimal
- `non_vat_sales_amount` decimal
- `vat_amount` decimal
- `statutory_discount_total` decimal
- `commercial_discount_total` decimal
- `other_adjustment_total` decimal
- `contains_statutory_discount` boolean
- `compliance_version` string nullable

Purpose:

- preserve the transaction header buckets that reports and exports need without recalculating them from changing business rules

### 3. Immutable line-level compliance fields

Extend `sale_items` so each line can be reconstructed into compliance buckets.

Suggested additional fields:

- `tax_bucket_code` nullable
- `vatable_amount` decimal default 0
- `vat_exempt_amount` decimal default 0
- `zero_rated_amount` decimal default 0
- `non_vat_amount` decimal default 0
- `vat_amount` decimal default 0
- `statutory_discount_amount` decimal default 0
- `commercial_discount_amount` decimal default 0
- `net_sale_amount_for_reporting` decimal default 0
- `discount_treatment_code` nullable
- `beneficiary_count` integer default 0

Purpose:

- prevent later reporting from reverse-engineering PH bucket logic from generic tax and discount totals alone

### 4. Statutory discount evidence tables

Add append-only evidence for statutory discount claims.

Recommended model: `sale_statutory_discounts`

Suggested fields:

- `id`
- `tenant_id`
- `branch_id`
- `sale_id`
- `sale_item_id` nullable for bill-level application
- `discount_type` (`senior`, `pwd`, future statutory values)
- `beneficiary_name`
- `beneficiary_id_number`
- `beneficiary_tin` nullable
- `beneficiary_reference_type` nullable
- `discount_rate` decimal
- `discount_amount` decimal
- `vat_exempt_amount` decimal
- `metadata` json nullable
- `captured_at`

Optional child model if multi-beneficiary detail grows:

- `sale_discount_beneficiaries`

Purpose:

- keep statutory discount evidence separate from commercial promotions
- support invoice rendering, audit books, and later export packs

### 5. Compliance-oriented reversal evidence

Extend reversal records or add a dedicated adjustment view so reports can determine whether a void or refund affects open-period results or requires disclosure against a prior reviewed or locked period.

Suggested fields or derived contract:

- original sale reporting basis timestamp
- reversal reporting basis timestamp
- prior_period_impact_flag
- reopened_period_disclosure_flag
- related settlement period ids when available

## 7. Write-Path Contract

Story 14.2 should keep all compliance fields write-once on the sale path:

1. sale confirmation or payment completion resolves the reporting basis timestamp selected by Story 14.1
2. invoice number and machine profile are attached before principal invoice evidence is finalized
3. line-level tax buckets are computed once and persisted
4. statutory discount evidence is captured append-only
5. refunds and voids create additive reversal evidence instead of mutating original sale compliance fields

Guardrails:

- do not mutate original sale compliance fields during refunds or voids
- do not derive statutory discount evidence from rendered receipt text
- do not overload generic discount fields as the only source of statutory compliance truth
- do not depend on current `TaxCategory` rows to reconstruct historical sale bucket values

## 8. Proposed Implementation Order

### Slice A: Compliance Header Fields

Implement:

- header-level compliance bucket fields on `sales`
- reporting-basis timestamp persistence
- principal invoice identity fields needed for immutable reconstruction
- model protections ensuring the new compliance fields follow the same write-once discipline as existing sale totals

Expected outcome:

- every completed sale carries explicit PH reporting header buckets and invoice reference fields rather than only generic subtotal and tax totals

Slice A execution prompt:

- Add the `sales_machine_profiles` table and the initial Epic 14 compliance header fields on `sales`.
- Keep all new fields additive, nullable, or zero-defaulted so existing historical sales remain valid without backfill.
- Do not implement Story 14.2 write-path logic yet beyond schema support.
- Preserve the current immutability contract of `Sale` by preparing schema support only; do not widen write-side behavior in this slice.

Required deliverables for Slice A:

- migration creating `sales_machine_profiles`
- migration adding `sales_machine_profile_id`, principal invoice fields, reporting basis timestamp, and PH header bucket totals to `sales`
- focused migration validation proving the new schema compiles cleanly

Out of scope for Slice A:

- `sale_items` PH bucket fields
- statutory discount evidence tables
- refund or void compliance metadata
- model, service, or UI wiring beyond what is strictly required for migration safety

### Slice B: Line-Level PH Bucket Fields

Implement:

- PH reporting bucket fields on `sale_items`
- schema support for future item-level PH tax source-of-truth data
- reporting-oriented indexes only where needed for later query support
- reuse the existing `tax_rate` field instead of adding a duplicate rate column

Expected outcome:

- later reporting can reconstruct VATable, exempt, zero-rated, and non-VAT treatment from immutable line evidence alone

Slice B execution prompt:

- Add the PH tax bucket and source-of-truth fields to `sale_items` only.
- Keep this slice schema-only. Do not update models, casts, checkout, settlement, accounting, exports, or UI.
- Reuse the existing `sale_items.tax_rate` field as the project tax-rate column instead of introducing a duplicate field.
- Add only rollback-safe fields and indexes needed for later reporting support.

Required deliverables for Slice B:

- migration adding `tax_bucket`, `net_amount`, `vatable_amount`, `vat_exempt_amount`, `zero_rated_amount`, `non_vat_amount`, `tax_source`, and `tax_snapshot` to `sale_items`
- focused migration validation proving the new schema compiles cleanly in pretend mode
- Story 14.2 artifact update reflecting Slice B completion

Out of scope for Slice B:

- model updates
- write-path logic
- tax computation
- settlement, reporting, export, accounting, or UI changes

Slice B completion note:

- Implemented as schema-only groundwork on 2026-05-13.
- Existing `sale_items.tax_rate` remains the canonical rate field for later Slice B write-path use.

### Slice C: Statutory Discount Evidence

Implement:

- append-only `sale_statutory_discounts` persistence
- linkage to sale and optionally sale item
- privacy-safe beneficiary reference fields for SC/PWD and approved future statutory types
- source and snapshot fields for later write-path and audit-safe reconstruction

Expected outcome:

- statutory discount reporting no longer depends on generic discount totals or receipt rendering

Slice C execution prompt:

- Add the `sale_statutory_discounts` table only.
- Keep this slice schema-only. Do not update models, relationships, casts, checkout, settlement, accounting, reporting, exports, or UI.
- Prefer privacy-safe beneficiary storage for this slice by using reference and hash fields rather than raw personal identity fields.
- Keep the table rollback-safe and add only practical reporting indexes.

Required deliverables for Slice C:

- migration creating `sale_statutory_discounts`
- focused migration validation proving the schema compiles cleanly in pretend mode
- migration status check confirming the migration remains pending
- Story 14.2 artifact update reflecting Slice C completion

Out of scope for Slice C:

- model updates
- relationships
- write-path logic
- discount computation
- VAT reclassification logic
- settlement, reporting, export, accounting, or UI changes

Slice C completion note:

- Implemented as schema-only groundwork on 2026-05-13.
- Beneficiary storage for this slice is privacy-safe through `beneficiary_reference` and `beneficiary_hash` fields instead of raw beneficiary identity columns.

### Slice D: Reversal Impact and Source Metadata Hardening

Implement:

- source-version metadata on `sales`
- tax computation source metadata on `sales`
- snapshot storage for tax profile context used at sale creation time
- reversal marker and original-sale linkage fields on `sales`
- reversal reason and reversal tax impact snapshot fields on `sales`

Expected outcome:

- later compliance logic can trace tax-source provenance and reversal relationships without mutating original tax evidence or relying on transient runtime state

Slice D execution prompt:

- Add the reversal-impact and source-metadata fields to `sales` only.
- Keep this slice schema-only. Do not update models, relationships, casts, checkout, settlement, accounting, reporting, exports, or UI.
- Reuse existing Epic 14 sales fields where already present and add only the missing source and reversal metadata fields.
- Keep the migration rollback-safe and limited to Slice D additions.

Required deliverables for Slice D:

- migration adding `tax_source_version`, `tax_computation_source`, `tax_profile_snapshot`, `is_reversal`, `reversal_of_sale_id`, `reversal_reason`, and `reversal_tax_impact_snapshot` to `sales`
- focused migration validation proving the schema compiles cleanly in pretend mode
- migration status check confirming the migration remains pending
- Story 14.2 artifact update reflecting Slice D completion

Out of scope for Slice D:

- model updates
- relationships
- write-path logic
- tax computation
- statutory discount computation
- VAT reclassification logic
- reversal processing logic
- settlement, reporting, export, accounting, or UI changes

Slice D completion note:

- Implemented as schema-only groundwork on 2026-05-13.
- Source and reversal-impact metadata now have dedicated `sales` columns for later compliance-safe write-path and adjustment work.

### Slice E: Schema Review and Closure Checkpoint

Implement:

- validation and closure review across Story 14.2 schema slices
- confirmation that the Epic 14 source-of-truth schema set is complete before any model or write-path wiring
- explicit follow-on handoff for model fillable/cast preparation

Expected outcome:

- Epic 14 can move from schema-only groundwork into controlled application-layer wiring with a clean approval checkpoint

Slice E execution prompt:

- Review Story 14.2 schema migrations only.
- Confirm ordering, duplicate-field avoidance, rollback safety, index sanity, privacy-safe statutory discount storage, and pending migration state.
- Keep this slice review-only and schema-only. Do not add models, casts, relationships, factories, seeders, or write-path logic.
- Update the Story 14.2 artifact and roadmap status surfaces to reflect schema-groundwork closure if the review passes.

Required deliverables for Slice E:

- full Story 14.2 migration review across Slices A-D
- pretend validation across all Story 14.2 migration files
- migration status confirmation that all Story 14.2 migrations remain pending
- Story 14.2 artifact update reflecting schema closure
- roadmap update reflecting schema-groundwork completion

Out of scope for Slice E:

- model updates
- fillable or cast wiring
- relationships
- factories or seeders
- write-path logic
- reporting, export, settlement, accounting, or UI changes

Slice E completion note:

- Completed on 2026-05-13 as a schema-only review and closure checkpoint.
- Migration ordering is coherent, no duplicate Epic 14 fields were introduced, rollback scope remains slice-local, index coverage is practical, and statutory discount storage remains privacy-safe.
- Story 14.2 schema groundwork is complete and ready for the next phase of controlled model preparation.

### Next Phase Slice A: Model Fillable and Cast Preparation

Implement:

- `Sale` model fillable updates for Epic 14 compliance, source, and reversal metadata
- `Sale` model casts for decimal, boolean, datetime, and JSON snapshot fields
- `SaleItem` model fillable and cast updates for PH tax bucket metadata
- minimal `SalesMachineProfile` and `SaleStatutoryDiscount` models with fillable and cast metadata only
- focused model-level tests that validate metadata alignment without requiring pending migrations to run

Expected outcome:

- application models recognize the pending Epic 14 schema additions while behavior remains unchanged

Next Phase Slice A completion note:

- Implemented on 2026-05-13 as metadata-only model preparation.
- No relationships, write-path wiring, computation logic, settlement changes, accounting changes, reporting changes, export changes, UI changes, or POS payload changes were introduced.
- Focused validation passed via `php artisan test tests/Feature/Epic14/TaxSourceOfTruthModelPreparationTest.php`.

### Next Phase Slice B: Relationship Preparation Between Epic 14 Models

Implement:

- `Sale` relationships for `salesMachineProfile`, `statutoryDiscounts`, `reversalOfSale`, and `reversals`
- `SaleItem` relationship for `statutoryDiscounts` while preserving the existing `sale()` relation
- `SalesMachineProfile` relationship for `sales`
- `SaleStatutoryDiscount` relationships for `sale` and `saleItem`
- relationship-only tests that assert relation metadata without requiring pending Epic 14 migrations to run

Expected outcome:

- Epic 14 source-of-truth models expose the relationship contract needed for later write-path work while runtime behavior remains unchanged

Next Phase Slice B completion note:

- Implemented on 2026-05-13 as relationship-only model preparation.
- `SalesMachineProfile` tenant and branch relationships did not require new methods because they are already provided by the existing `BelongsToTenant` and `BelongsToBranch` traits.
- Focused relationship validation passed via `php artisan test tests/Feature/Epic14/TaxSourceOfTruthRelationshipPreparationTest.php`.
- Prior Epic 14 model-preparation tests, the settlement variance regression file, and the full backend suite remained green after the relationship additions.

### Next Phase Slice C: Source-of-Truth Constants and Helper Preparation

Implement:

- model-level tax bucket constants and helper methods on `SaleItem`
- model-level tax source and tax computation source constants and helper methods on `Sale`
- shared tax source helper access on `SaleItem`
- model-level statutory discount type constants and helper methods on `SaleStatutoryDiscount`
- model-level reversal reason constants and helper methods on `Sale`
- focused constant/helper tests that do not depend on pending Epic 14 migrations being applied

Expected outcome:

- Epic 14 source-of-truth classifications have one reusable model-layer contract before write-path or validation enforcement begins

Next Phase Slice C completion note:

- Implemented on 2026-05-13 as constant/helper-only model preparation.
- Existing repository conventions favored model constants over PHP enums, so this slice followed the same approach.
- Classification values use the repository's established lowercase string style to stay aligned with current persisted status and type conventions.
- `SalesMachineProfile` status constants were intentionally deferred because the schema currently defines a generic `status` field but does not yet establish an approved closed set of machine-profile lifecycle values beyond the default active state.
- Focused constants validation passed via `php artisan test tests/Feature/Epic14/TaxSourceOfTruthConstantsTest.php`, and the existing Epic 14 model tests, settlement variance regression file, and full backend suite remained green.

### Next Phase Slice D: Model Preparation Closure Checkpoint

Implement:

- review fillable and cast alignment against the pending Epic 14 migrations
- review relationship alignment across `Sale`, `SaleItem`, `SalesMachineProfile`, and `SaleStatutoryDiscount`
- review constants and helper alignment for tax buckets, tax sources, discount types, and reversal reasons
- confirm pending-migration compatibility by keeping Epic 14 tests model-level only
- rerun the focused Epic 14 suite, settlement variance regression, and full backend regression

Expected outcome:

- Story 14.2 model preparation can be treated as complete and non-invasive before any decision about migration application or write-path wiring

Next Phase Slice D completion note:

- Completed on 2026-05-13 as a review-only closure checkpoint.
- `Sale`, `SaleItem`, `SalesMachineProfile`, and `SaleStatutoryDiscount` remain aligned with the pending Epic 14 schema slices for fillables, casts, relationships, and helper constants.
- Epic 14 tests remain model-level and do not require pending database columns to exist.
- Focused Epic 14 model preparation tests, the settlement variance regression file, and the full backend suite all remained green, with the risky baseline unchanged at 1.

Model preparation closure statement:

- Story 14.2 model preparation is complete.
- The codebase now has model-level support for the pending Epic 14 source-of-truth schema through fillables, casts, relationships, and constants/helpers, without activating checkout or write-path behavior.

Decision point:

1. Apply the pending Story 14.2 migrations in a controlled database migration slice.
2. Begin checkout write-path wiring after migration application is approved.
3. Add service-level tax snapshot preparation without writing values yet.
4. Pause Story 14.2 and proceed to another roadmap item.

### Controlled Migration Slice: Apply Pending Epic 14 Source-of-Truth Migrations

Implement:

- pre-migration status confirmation for the five Story 14.2 migrations
- controlled live application of the five approved Epic 14 migrations only
- post-migration status confirmation
- non-interactive schema verification for new tables and columns
- focused Epic 14 model-suite validation, settlement regression validation, and full backend regression validation

Expected outcome:

- the previously pending Epic 14 source-of-truth schema becomes active in the database without enabling write-path behavior

Controlled Migration Slice completion note:

- Completed on 2026-05-13 as a validation-first live migration application slice.
- The following migrations were applied successfully:
	- `2026_05_13_000001_create_sales_machine_profiles_table.php`
	- `2026_05_13_000002_add_epic14_compliance_fields_to_sales_table.php`
	- `2026_05_13_000003_add_ph_tax_bucket_fields_to_sale_items_table.php`
	- `2026_05_13_000004_create_sale_statutory_discounts_table.php`
	- `2026_05_13_000005_add_reversal_and_source_metadata_to_sales_table.php`
- Post-migration status confirmed all five migrations as applied.
- Schema verification confirmed the new Epic 14 tables and columns exist in the active database schema.
- Focused Epic 14 tests, the settlement variance regression file, and the full backend suite remained green, with the risky baseline unchanged at 1.

Controlled migration statement:

- Story 14.2 schema is no longer pending in the active database for the approved Epic 14 source-of-truth surfaces.
- No checkout/write-path, tax computation, reporting, settlement, accounting, export, UI, or POS payload behavior was introduced during migration application.

### Tax Snapshot Preparation Slice: Service-Level Tax Snapshot Preparation

Implement:

- non-persistent service-level shaping for sale tax profile snapshots
- non-persistent service-level shaping for sale item tax bucket snapshots
- non-persistent service-level shaping for statutory discount snapshots
- reusable source metadata shaping that reuses the completed Epic 14 constants/helpers
- focused tests proving the snapshot contract stays model-level and does not perform writes

Expected outcome:

- the codebase has a reusable tax snapshot preparation layer ready for later write-path integration without activating checkout persistence

Tax Snapshot Preparation Slice completion note:

- Implemented on 2026-05-13 as a service-level preparation slice only.
- Added `TaxSourceSnapshotService` to shape sale-level, sale-item, statutory discount, and source metadata arrays without persisting them.
- Reused existing `Sale`, `SaleItem`, and `SaleStatutoryDiscount` constants/helpers instead of introducing a second classification source.
- Focused tax snapshot preparation tests, the full Epic 14 feature suite, the settlement variance regression file, and the full backend suite remained green, with the risky baseline unchanged at 1.

Current Story 14.2 execution note:

- schema groundwork: complete and applied
- model preparation: complete
- service-level tax snapshot preparation: complete
- checkout/write-path wiring: not started
- reporting/query behavior: not started
- UI/export behavior: not started

## 9. Acceptance Criteria

1. Every completed sale can be reconstructed into VATable, VAT-exempt, zero-rated, and non-VAT buckets without consulting current catalog settings.
2. Statutory discounts are stored separately from commercial discounts.
3. Principal invoice number and machine-profile reference can be reconstructed from immutable sale evidence.
4. Refunds and voids preserve original sale evidence and record additive compliance adjustments.
5. The repository can explain whether an adjustment belongs to the current reporting period or discloses impact on a prior period.
6. Story 14.3 can query PH tax totals without re-deriving beneficiary evidence from receipt text.

## 10. Validation Expectations

Primary validation areas:

- mixed-tax bucket persistence on sale creation or payment finalization paths
- invoice and machine-profile references survive historical reconstruction
- statutory discount evidence is append-only and queryable independently of rendered receipts
- refund and void paths keep original sale evidence immutable while adding adjustment evidence
- later query services can consume the new fields without consulting current product or tax configuration

## 11. Suggested Test and Check Surfaces

Minimum validation surfaces once implementation starts:

- sale creation and payment confirmation tests for mixed VAT buckets
- receipt or invoice data contract tests for required fields
- statutory discount evidence tests for SC/PWD capture
- refund and void tests showing additive compliance adjustments
- reporting-query tests proving immutable historical reconstruction

Suggested command checks:

- focused Epic 14 feature tests for new sale and reversal evidence
- full `php artisan test`
- `npm run build` only if receipt or reporting UI surfaces change during execution

## 12. Delivery Notes

Story 14.2 is infrastructure-first. The first acceptable version is the smallest end-to-end implementation that turns today’s generic sale snapshots into immutable PH compliance evidence without widening into UI, export, or accreditation workflow scope.

Migration-level schema draft: [epic-14-migration-schema-plan.md](../epic-14-migration-schema-plan.md)
