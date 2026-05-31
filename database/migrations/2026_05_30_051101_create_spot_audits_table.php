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
        Schema::create('spot_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('manager_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('expected_cash_amount', 19, 4);
            $table->decimal('counted_cash_amount', 19, 4);
            $table->decimal('variance_amount', 19, 4);
            $table->json('denominations');
            $table->text('audit_notes')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'shift_id']);
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_audits');
    }
};
