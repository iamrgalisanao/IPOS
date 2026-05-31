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
        Schema::create('reconciliation_discrepancy_logs', function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id')->index();
            $blueprint->uuid('branch_id')->index();
            $blueprint->uuid('sales_machine_profile_id')->index();
            
            $blueprint->decimal('reported_gct', 15, 4);
            $blueprint->decimal('calculated_gct', 15, 4);
            $blueprint->decimal('discrepancy_amount', 15, 4);
            
            $blueprint->string('context_type')->default('sync');
            $blueprint->timestamp('resolved_at')->nullable();
            $blueprint->uuid('resolved_by')->nullable()->index();
            $blueprint->text('resolution_notes')->nullable();
            
            $blueprint->timestamps();

            // Setup foreign keys safely
            $blueprint->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $blueprint->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $blueprint->foreign('sales_machine_profile_id')->references('id')->on('sales_machine_profiles')->onDelete('cascade');
            $blueprint->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_discrepancy_logs');
    }
};
