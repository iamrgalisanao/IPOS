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
            // Optional per-terminal layout override.
            // If set, this terminal uses this specific layout instead of the branch-active layout.
            // nullOnDelete: if the assigned layout is deleted, terminal falls back to branch layout.
            $table->foreignUuid('pos_layout_id')
                ->nullable()
                ->after('status')
                ->constrained('pos_layouts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropForeign(['pos_layout_id']);
            $table->dropColumn('pos_layout_id');
        });
    }
};
