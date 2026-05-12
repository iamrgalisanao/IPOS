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
        Schema::create('cash_drawer_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->cascadeOnDelete();

            $table->string('event_type'); // opening_cash, cash_drop, cash_top_up, cash_in, cash_out
            $table->decimal('amount', 19, 4);
            $table->string('reason_code');
            $table->text('reason_notes')->nullable();

            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'shift_id']);
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_events');
    }
};
