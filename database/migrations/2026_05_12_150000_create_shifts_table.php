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
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->cascadeOnDelete();
            
            $table->foreignUuid('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status'); // open, closing_submitted, approved, closed

            $table->decimal('opening_cash_amount', 19, 4);
            $table->decimal('counted_cash_amount', 19, 4)->nullable();
            $table->decimal('expected_cash_amount', 19, 4)->nullable();
            $table->decimal('variance_amount', 19, 4)->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closing_submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->text('closing_notes')->nullable();
            $table->text('manager_notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index('cashier_id');
            $table->index('opened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
