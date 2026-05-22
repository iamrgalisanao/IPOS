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
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->decimal('grand_cumulative_total', 19, 4)->default(0.00)->after('status');
            $table->integer('reset_counter')->default(0)->after('grand_cumulative_total');
            $table->integer('z_read_counter')->default(0)->after('reset_counter');
            $table->string('terminal_identifier')->nullable()->after('z_read_counter');

            $table->index(['tenant_id', 'terminal_identifier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'terminal_identifier']);
            $table->dropColumn([
                'grand_cumulative_total',
                'reset_counter',
                'z_read_counter',
                'terminal_identifier',
            ]);
        });
    }
};
