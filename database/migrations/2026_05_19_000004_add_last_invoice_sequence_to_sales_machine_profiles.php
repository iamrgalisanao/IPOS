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
            $table->integer('last_invoice_sequence')->default(0)->after('z_read_counter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropColumn('last_invoice_sequence');
        });
    }
};
