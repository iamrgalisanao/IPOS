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
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('cash_drawer_limit', 19, 4)->nullable()->after('offline_sales_enabled');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('default_cash_drawer_limit', 19, 4)->nullable()->after('offline_sales_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('cash_drawer_limit');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('default_cash_drawer_limit');
        });
    }
};
