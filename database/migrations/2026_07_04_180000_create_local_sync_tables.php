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
        Schema::create('local_sync_brokers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('master_profile_id')->index();
            $table->string('local_ip_address');
            $table->integer('local_port')->default(8000);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            // Foreign keys if appropriate in production, but often loose for offline-first resilience.
        });

        Schema::create('local_table_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->string('table_id');
            $table->uuid('locked_by_profile_id')->index();
            $table->timestamp('locked_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['branch_id', 'table_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_table_locks');
        Schema::dropIfExists('local_sync_brokers');
    }
};
