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
        Schema::create('terminal_config_heartbeats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('sales_machine_profile_id')->unique()->constrained('sales_machine_profiles')->cascadeOnDelete();
            $table->string('app_version')->nullable();
            $table->string('device_id')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->timestamp('last_snapshot_downloaded_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->unsignedInteger('queue_count')->default(0);
            $table->string('connection_state')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminal_config_heartbeats');
    }
};
