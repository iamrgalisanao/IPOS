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
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('offline_sales_enabled')->default(true);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('offline_sales_enabled')->default(true);
        });

        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->boolean('offline_sales_enabled')->nullable()->default(null);
            $table->string('offline_sequence_prefix')->nullable();
            $table->unsignedBigInteger('offline_sequence_next_value')->default(1);
            $table->string('offline_sequence_status')->default('active');
            $table->timestamp('last_offline_sync_at')->nullable();

            $table->unique(['tenant_id', 'offline_sequence_prefix'], 'sm_profile_tenant_prefix_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropUnique('sm_profile_tenant_prefix_unique');
            $table->dropColumn([
                'offline_sales_enabled',
                'offline_sequence_prefix',
                'offline_sequence_next_value',
                'offline_sequence_status',
                'last_offline_sync_at',
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('offline_sales_enabled');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('offline_sales_enabled');
        });
    }
};
