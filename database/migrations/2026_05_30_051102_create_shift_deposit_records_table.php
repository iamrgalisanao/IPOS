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
        Schema::create('shift_deposit_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->unique()->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('manager_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('deposit_amount', 19, 4);
            $table->decimal('expected_cash_amount', 19, 4);
            $table->decimal('counted_cash_amount', 19, 4);
            $table->decimal('cash_drop_total', 19, 4);
            $table->decimal('variance_amount', 19, 4);
            $table->text('variance_explanation')->nullable();
            
            $table->string('bank_name')->nullable();
            $table->string('reference_number')->nullable();
            
            $table->timestamp('deposited_at');
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_deposit_records');
    }
};
