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
            $table->decimal('average_cost', 19, 4)->default(0)->after('current_stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_inventories', function (Blueprint $table) {
            $table->dropColumn('average_cost');
        });
    }
};
