<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_request_uuid')->index();
            $table->string('status', 32)->default('validated');
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Idempotency boundary: one UUID per tenant/branch/user context
            $table->unique(
                ['tenant_id', 'branch_id', 'user_id', 'client_request_uuid'],
                'checkout_requests_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_requests');
    }
};
