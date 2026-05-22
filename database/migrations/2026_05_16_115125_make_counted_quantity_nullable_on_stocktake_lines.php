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
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->decimal('counted_quantity', 19, 4)->nullable()->default(null)->change();
            $table->decimal('variance_quantity', 19, 4)->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->decimal('counted_quantity', 19, 4)->nullable(false)->default(0)->change();
            $table->decimal('variance_quantity', 19, 4)->nullable(false)->default(0)->change();
        });
    }
};
