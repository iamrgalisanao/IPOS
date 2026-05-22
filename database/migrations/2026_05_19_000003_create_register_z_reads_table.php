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
        Schema::create('register_z_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('sales_machine_profile_id')->constrained('sales_machine_profiles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->integer('z_read_sequence');
            $table->date('z_read_date');
            
            $table->decimal('grand_cumulative_total_before', 19, 4)->default(0);
            $table->decimal('grand_cumulative_total_after', 19, 4)->default(0);
            
            $table->decimal('gross_sales_amount', 19, 4)->default(0);
            $table->decimal('vatable_sales_amount', 19, 4)->default(0);
            $table->decimal('vat_exempt_sales_amount', 19, 4)->default(0);
            $table->decimal('zero_rated_sales_amount', 19, 4)->default(0);
            $table->decimal('non_vat_sales_amount', 19, 4)->default(0);
            $table->decimal('vat_amount', 19, 4)->default(0);
            
            $table->decimal('statutory_discount_total', 19, 4)->default(0);
            $table->decimal('commercial_discount_total', 19, 4)->default(0);
            $table->decimal('other_adjustment_total', 19, 4)->default(0);
            
            $table->decimal('void_sales_amount', 19, 4)->default(0);
            $table->decimal('refund_sales_amount', 19, 4)->default(0);
            
            $table->integer('transaction_count')->default(0);
            $table->integer('reset_counter')->default(0);
            
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            
            $table->timestamp('reporting_basis_at');
            $table->boolean('is_training_mode')->default(false);
            
            $table->longText('raw_journal_payload')->nullable();
            $table->string('tamper_evident_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['sales_machine_profile_id', 'z_read_sequence']);
            $table->index(['tenant_id', 'branch_id', 'z_read_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('register_z_reads');
    }
};
