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
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->uuid('original_movement_id')->nullable()->after('branch_inventory_id');
            $table->foreign('original_movement_id')->references('id')->on('inventory_movements');
            $table->index('original_movement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['original_movement_id']);
            $table->dropColumn('original_movement_id');
        });
    }
};
