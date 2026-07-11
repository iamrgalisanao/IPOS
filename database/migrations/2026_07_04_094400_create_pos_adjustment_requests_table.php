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
        Schema::create('pos_adjustment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('idempotency_key')->unique();
            $table->string('action_type'); // void, refund, manual_refund_request, cash_exception_refund
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_hash');
            $table->json('response_snapshot')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_adjustment_requests');
    }
};
