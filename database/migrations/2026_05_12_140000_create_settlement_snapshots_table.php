<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->foreignUuid('settlement_period_id')->constrained('settlement_periods');
            $table->string('snapshot_type');
            $table->json('summary_payload');
            $table->json('variance_payload');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'settlement_period_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['snapshot_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_snapshots');
    }
};