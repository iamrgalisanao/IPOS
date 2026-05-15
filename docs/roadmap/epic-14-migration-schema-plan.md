# Epic 14 Migration Schema Plan

Last updated: 2026-05-13
Status: Draft planning artifact

## Purpose

This document converts Story 14.2 into a migration-level schema plan before runtime implementation begins.

It is intentionally concrete about table names, field types, ordering, and constraints so Epic 14 implementation can start with a disciplined data contract instead of re-deciding the schema during coding.

## Design Rules

1. Preserve existing append-only and immutable financial evidence patterns.
2. Keep statutory discount evidence separate from commercial discount fields.
3. Store invoice and machine metadata as repository evidence without claiming automated BIR registration workflows.
4. Prefer additive nullable fields for existing tables to reduce migration risk.
5. Match current sale and sale item money precision unless a nearby table already establishes a better local precedent.

Precision decision:

- use `decimal(19, 4)` for new `sales` and `sale_items` money fields to stay aligned with the existing schema
- use `decimal(19, 4)` for new statutory discount evidence fields for consistency with the source sale tables

## Proposed Migration Order

### Migration 1: create_sales_machine_profiles_table

Suggested name:

- `2026_05_13_000001_create_sales_machine_profiles_table.php`

Purpose:

- persist branch-scoped or tenant-scoped machine and permit evidence that later invoice and reporting surfaces can reference immutably from sales

Suggested schema:

```php
Schema::create('sales_machine_profiles', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    $table->string('profile_code');
    $table->string('machine_identification_number')->nullable();
    $table->string('machine_serial_number')->nullable();
    $table->string('software_license_number')->nullable();
    $table->string('permit_to_use_number')->nullable();
    $table->timestamp('permit_issued_at')->nullable();
    $table->string('authority_to_generate_control_number')->nullable();
    $table->string('supplier_name')->nullable();
    $table->string('supplier_tin')->nullable();
    $table->string('supplier_branch_code', 5)->nullable();
    $table->string('supplier_address')->nullable();
    $table->string('supplier_accreditation_number')->nullable();
    $table->timestamp('supplier_accreditation_issued_at')->nullable();
    $table->timestamp('supplier_accreditation_expires_at')->nullable();
    $table->string('status', 32)->default('active');
    $table->timestamps();

    $table->unique(['tenant_id', 'profile_code']);
    $table->index(['tenant_id', 'branch_id', 'status']);
});
```

Constraint notes:

- `profile_code` should be tenant-unique, not globally unique
- `branch_id` nullable allows tenant-wide defaults for branches without dedicated machine config
- keep permit and accreditation fields nullable because the repository may be used before those values are assigned

### Migration 2: add_epic14_compliance_fields_to_sales_table

Suggested name:

- `2026_05_13_000002_add_epic14_compliance_fields_to_sales_table.php`

Purpose:

- extend `sales` with immutable compliance header buckets and invoice evidence fields

Suggested schema:

```php
Schema::table('sales', function (Blueprint $table) {
    $table->foreignUuid('sales_machine_profile_id')->nullable()->after('checkout_request_id')
        ->constrained('sales_machine_profiles')->nullOnDelete();

    $table->string('principal_invoice_number')->nullable()->after('sale_number');
    $table->string('principal_invoice_type', 32)->nullable()->after('principal_invoice_number');
    $table->string('principal_invoice_label', 64)->nullable()->after('principal_invoice_type');
    $table->timestamp('invoice_issued_at')->nullable()->after('principal_invoice_label');
    $table->timestamp('reporting_basis_at')->nullable()->after('invoice_issued_at');

    $table->decimal('gross_sales_amount', 19, 4)->default(0)->after('total');
    $table->decimal('vatable_sales_amount', 19, 4)->default(0)->after('gross_sales_amount');
    $table->decimal('vat_exempt_sales_amount', 19, 4)->default(0)->after('vatable_sales_amount');
    $table->decimal('zero_rated_sales_amount', 19, 4)->default(0)->after('vat_exempt_sales_amount');
    $table->decimal('non_vat_sales_amount', 19, 4)->default(0)->after('zero_rated_sales_amount');
    $table->decimal('vat_amount', 19, 4)->default(0)->after('non_vat_sales_amount');
    $table->decimal('statutory_discount_total', 19, 4)->default(0)->after('vat_amount');
    $table->decimal('commercial_discount_total', 19, 4)->default(0)->after('statutory_discount_total');
    $table->decimal('other_adjustment_total', 19, 4)->default(0)->after('commercial_discount_total');
    $table->boolean('contains_statutory_discount')->default(false)->after('other_adjustment_total');
    $table->string('compliance_version', 32)->nullable()->after('contains_statutory_discount');

    $table->index(['tenant_id', 'branch_id', 'reporting_basis_at']);
    $table->index(['tenant_id', 'principal_invoice_number']);
    $table->index(['tenant_id', 'contains_statutory_discount']);
});
```

Constraint notes:

- avoid unique constraint on `principal_invoice_number` until the exact branch or tenant numbering rule is locked in implementation
- `gross_sales_amount` should be stored independently even if initially equal to `subtotal` so later rules do not force recomputation from mutable business logic

Backfill rule:

- existing historical rows should backfill zeros and nullable invoice fields, not infer PH buckets retroactively inside the migration

### Migration 3: add_epic14_compliance_fields_to_sale_items_table

Suggested name:

- `2026_05_13_000003_add_epic14_compliance_fields_to_sale_items_table.php`

Purpose:

- extend `sale_items` with immutable PH reporting bucket fields

Suggested schema:

```php
Schema::table('sale_items', function (Blueprint $table) {
    $table->string('tax_bucket_code', 32)->nullable()->after('tax_type');
    $table->decimal('vatable_amount', 19, 4)->default(0)->after('tax_amount');
    $table->decimal('vat_exempt_amount', 19, 4)->default(0)->after('vatable_amount');
    $table->decimal('zero_rated_amount', 19, 4)->default(0)->after('vat_exempt_amount');
    $table->decimal('non_vat_amount', 19, 4)->default(0)->after('zero_rated_amount');
    $table->decimal('vat_amount_for_reporting', 19, 4)->default(0)->after('non_vat_amount');
    $table->decimal('statutory_discount_amount', 19, 4)->default(0)->after('vat_amount_for_reporting');
    $table->decimal('commercial_discount_amount', 19, 4)->default(0)->after('statutory_discount_amount');
    $table->decimal('net_sale_amount_for_reporting', 19, 4)->default(0)->after('commercial_discount_amount');
    $table->string('discount_treatment_code', 32)->nullable()->after('net_sale_amount_for_reporting');
    $table->unsignedInteger('beneficiary_count')->default(0)->after('discount_treatment_code');

    $table->index(['sale_id', 'tax_bucket_code']);
    $table->index(['tenant_id', 'branch_id', 'tax_bucket_code']);
});
```

Naming note:

- use `vat_amount_for_reporting` instead of a second `vat_amount` column to avoid collision with the existing tax snapshot field

Backfill rule:

- historical rows default to zero and null values; application code, not the migration, computes the new buckets for new transactions

### Migration 4: create_sale_statutory_discounts_table

Suggested name:

- `2026_05_13_000004_create_sale_statutory_discounts_table.php`

Purpose:

- persist append-only statutory discount evidence separately from generic discount totals

Suggested schema:

```php
Schema::create('sale_statutory_discounts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
    $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
    $table->foreignUuid('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
    $table->string('discount_type', 32);
    $table->string('beneficiary_name');
    $table->string('beneficiary_id_number');
    $table->string('beneficiary_tin')->nullable();
    $table->string('beneficiary_reference_type', 32)->nullable();
    $table->decimal('discount_rate', 19, 4)->default(0);
    $table->decimal('discount_amount', 19, 4)->default(0);
    $table->decimal('vat_exempt_amount', 19, 4)->default(0);
    $table->json('metadata')->nullable();
    $table->timestamp('captured_at')->useCurrent();
    $table->timestamps();

    $table->index(['sale_id', 'discount_type']);
    $table->index(['tenant_id', 'branch_id', 'captured_at']);
});
```

Constraint notes:

- no uniqueness constraint across beneficiary fields because multiple qualified beneficiaries can exist in one sale
- `sale_item_id` nullable supports bill-level application while preserving a later path to line-level detail

### Migration 5: add_epic14_compliance_fields_to_sale_refunds_and_sale_voids

Suggested name:

- `2026_05_13_000005_add_epic14_compliance_fields_to_sale_refunds_and_sale_voids.php`

Purpose:

- add additive compliance-adjustment metadata that later reporting can use for current-period and prior-period disclosure logic

Suggested schema:

```php
Schema::table('sale_refunds', function (Blueprint $table) {
    $table->timestamp('original_sale_reporting_basis_at')->nullable()->after('refund_total');
    $table->timestamp('reversal_reporting_basis_at')->nullable()->after('original_sale_reporting_basis_at');
    $table->boolean('prior_period_impact_flag')->default(false)->after('reversal_reporting_basis_at');
    $table->boolean('reopened_period_disclosure_flag')->default(false)->after('prior_period_impact_flag');
    $table->foreignUuid('related_settlement_period_id')->nullable()->after('reopened_period_disclosure_flag')
        ->constrained('settlement_periods')->nullOnDelete();

    $table->index(['branch_id', 'refunded_at', 'prior_period_impact_flag']);
});

Schema::table('sale_voids', function (Blueprint $table) {
    $table->timestamp('original_sale_reporting_basis_at')->nullable()->after('reason_notes');
    $table->timestamp('reversal_reporting_basis_at')->nullable()->after('original_sale_reporting_basis_at');
    $table->boolean('prior_period_impact_flag')->default(false)->after('reversal_reporting_basis_at');
    $table->boolean('reopened_period_disclosure_flag')->default(false)->after('prior_period_impact_flag');
    $table->foreignUuid('related_settlement_period_id')->nullable()->after('reopened_period_disclosure_flag')
        ->constrained('settlement_periods')->nullOnDelete();

    $table->index(['branch_id', 'voided_at', 'prior_period_impact_flag']);
});
```

Rationale:

- put the period-impact fields on the reversal records, not the original sale, so original sale evidence remains untouched

## Optional Later Migration

### Migration 6: add_reprint_tracking_or_invoice_lineage_fields

Not required for Story 14.2.

Keep deferred unless Epic 14 or Epic 15 explicitly approves receipt or invoice lineage tracking.

Possible future fields:

- `sales.last_reprinted_at`
- `sales.reprint_count`
- separate `sale_document_events` table

## Implementation Notes

### Model impact

Expected model updates after the migrations land:

- `Sale` fillable and casts for compliance header fields
- `SaleItem` fillable and casts for PH bucket fields
- new `SalesMachineProfile` model
- new `SaleStatutoryDiscount` model

### Write-path impact

Expected service updates after the migrations land:

- `SaleCreationService` computes line-level PH buckets and header bucket rollups
- `PaymentRecordingService` finalizes `reporting_basis_at` and principal invoice evidence when the sale becomes payable or paid
- `ReceiptService` reads the machine-profile and principal invoice evidence instead of rendering from generic receipt-only data
- `RefundService` and `VoidService` populate additive prior-period impact metadata

### Backfill and rollout strategy

Recommended rollout:

1. ship migrations with nullable or zero-default additive fields
2. update write paths for new transactions only
3. avoid automatic historical recomputation during the first migration pass
4. if historical backfill is later required, perform it in a dedicated command or data migration with explicit attestation

## Decision Summary

Implement Story 14.2 with five migrations in this order:

1. `sales_machine_profiles`
2. `sales` compliance header fields
3. `sale_items` PH bucket fields
4. `sale_statutory_discounts`
5. reversal period-impact fields on `sale_refunds` and `sale_voids`

This is the smallest schema plan that supports immutable PH reporting reconstruction without pulling Epic 14 into UI, export, or accreditation workflow scope.