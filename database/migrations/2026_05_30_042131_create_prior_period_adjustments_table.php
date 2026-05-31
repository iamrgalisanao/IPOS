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
        Schema::create('prior_period_adjustments', function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id')->index();
            $blueprint->uuid('branch_id')->index();
            $blueprint->uuid('sales_machine_profile_id')->index();
            $blueprint->uuid('sale_id')->nullable()->index();
            $blueprint->uuid('offline_sales_import_id')->nullable()->index();
            
            $blueprint->timestamp('original_transaction_at');
            $blueprint->date('original_business_date')->index();
            $blueprint->uuid('original_register_z_read_id')->nullable()->index();
            $blueprint->uuid('adjusted_into_settlement_period_id')->nullable()->index();
            
            $blueprint->timestamp('reporting_basis_at');
            $blueprint->timestamp('reconciled_at');
            
            $blueprint->decimal('gross_amount', 15, 4);
            $blueprint->decimal('net_amount', 15, 4);
            $blueprint->decimal('vat_amount', 15, 4);
            
            $blueprint->string('adjustment_reason')->nullable();
            $blueprint->string('status')->default('logged');
            
            $blueprint->timestamps();

            // Setup foreign keys safely
            $blueprint->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $blueprint->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $blueprint->foreign('sales_machine_profile_id')->references('id')->on('sales_machine_profiles')->onDelete('cascade');
            $blueprint->foreign('sale_id')->references('id')->on('sales')->onDelete('set null');
            $blueprint->foreign('offline_sales_import_id')->references('id')->on('offline_sales_imports')->onDelete('set null');
            $blueprint->foreign('original_register_z_read_id')->references('id')->on('register_z_reads')->onDelete('set null');
            $blueprint->foreign('adjusted_into_settlement_period_id')->references('id')->on('settlement_periods')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prior_period_adjustments');
    }
};
