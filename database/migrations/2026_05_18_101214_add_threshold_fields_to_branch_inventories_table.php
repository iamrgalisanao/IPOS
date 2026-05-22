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
        Schema::table('branch_inventories', function (Blueprint $table) {
            $table->decimal('par_level', 19, 4)->default(0)->after('reorder_level');
            $table->integer('lead_time_days')->default(0)->after('par_level');
            $table->decimal('safety_stock_buffer', 19, 4)->default(0)->after('lead_time_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_inventories', function (Blueprint $table) {
            $table->dropColumn(['par_level', 'lead_time_days', 'safety_stock_buffer']);
        });
    }
};

