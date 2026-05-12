<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_sync_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->foreignUuid('accounting_outbox_id')->constrained('accounting_outbox')->cascadeOnDelete();
            $table->integer('attempt_number');
            $table->string('status');
            $table->string('error_category')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['accounting_outbox_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_sync_attempts');
    }
};
