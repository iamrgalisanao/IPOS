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
        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->foreignUuid('sale_id')->constrained('sales');
            $table->string('refund_number')->nullable();
            $table->string('reason_code');
            $table->text('reason_notes')->nullable();
            $table->decimal('refund_total', 20, 4);
            $table->foreignUuid('refunded_by')->constrained('users');
            $table->timestamp('refunded_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_refunds');
    }
};
