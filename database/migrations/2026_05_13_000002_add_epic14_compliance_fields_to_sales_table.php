<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('sales_machine_profile_id')
                ->nullable()
                ->after('checkout_request_id')
                ->constrained('sales_machine_profiles')
                ->nullOnDelete();

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
            $table->json('discount_policy_snapshot')->nullable()->after('other_adjustment_total');
            $table->boolean('contains_statutory_discount')->default(false)->after('other_adjustment_total');
            $table->string('compliance_version', 32)->nullable()->after('contains_statutory_discount');

            $table->index(['tenant_id', 'branch_id', 'reporting_basis_at']);
            $table->index(['tenant_id', 'principal_invoice_number']);
            $table->index(['tenant_id', 'contains_statutory_discount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'branch_id', 'reporting_basis_at']);
            $table->dropIndex(['tenant_id', 'principal_invoice_number']);
            $table->dropIndex(['tenant_id', 'contains_statutory_discount']);
            $table->dropForeign(['sales_machine_profile_id']);

            $table->dropColumn([
                'sales_machine_profile_id',
                'principal_invoice_number',
                'principal_invoice_type',
                'principal_invoice_label',
                'invoice_issued_at',
                'reporting_basis_at',
                'gross_sales_amount',
                'vatable_sales_amount',
                'vat_exempt_sales_amount',
                'zero_rated_sales_amount',
                'non_vat_sales_amount',
                'vat_amount',
                'statutory_discount_total',
                'commercial_discount_total',
                'other_adjustment_total',
                'discount_policy_snapshot',
                'contains_statutory_discount',
                'compliance_version',
            ]);
        });
    }
};
