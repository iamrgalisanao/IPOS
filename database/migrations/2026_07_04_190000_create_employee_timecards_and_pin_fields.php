<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_timecards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('terminal_id')->nullable()->index();
            $table->uuid('user_id')->index();

            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();

            $table->string('clock_in_ip', 45)->nullable();
            $table->string('clock_out_ip', 45)->nullable();

            $table->string('clock_in_device_id', 100)->nullable();
            $table->string('clock_out_device_id', 100)->nullable();

            $table->string('clock_in_method', 50)->default('pin');
            $table->string('clock_out_method', 50)->default('pin');

            $table->string('clock_out_reason', 255)->nullable();
            $table->uuid('manually_adjusted_by')->nullable()->index();
            $table->timestamp('manually_adjusted_at')->nullable();
            $table->text('manual_adjustment_reason')->nullable();

            // is_active is tinyint (1 when active/clocked in, NULL when clocked out)
            // Combined with the unique index, this restricts only one active timecard per user/branch
            $table->tinyInteger('is_active')->nullable()->default(1);

            $table->timestamps();
        });

        // Add performance indexes
        Schema::table('employee_timecards', function (Blueprint $table) {
            $table->index(['tenant_id', 'branch_id', 'clocked_in_at'], 'idx_timecards_tenant_branch_clocked_in');
            $table->unique(['tenant_id', 'branch_id', 'user_id', 'is_active'], 'uniq_active_timecard_per_user_branch');
        });

        // Add POS PIN hash to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('pos_pin_hash')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pos_pin_hash');
        });

        Schema::dropIfExists('employee_timecards');
    }
};
