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
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->foreignUuid('sale_id')->constrained('sales');
            $table->foreignUuid('sale_payment_id')->constrained('sale_payments');
            $table->string('reversal_type'); // e.g. void_reversal, refund_reversal
            $table->decimal('amount', 20, 4);
            $table->string('reason_code');
            $table->text('reason_notes')->nullable();
            $table->foreignUuid('reversed_by')->constrained('users');
            $table->timestamp('reversed_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
