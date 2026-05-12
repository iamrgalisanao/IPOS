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
        Schema::create('accounting_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->constrained('branches');
            
            $table->string('event_type'); // sale_paid, sale_voided, sale_refunded
            $table->string('source_type'); // sale, sale_void, sale_refund
            $table->uuid('source_id');
            
            $table->jsonb('payload');
            
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->integer('attempt_count')->default(0);
            
            $table->timestamp('available_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Idempotency Guard
            $table->unique(['event_type', 'source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_outbox');
    }
};
