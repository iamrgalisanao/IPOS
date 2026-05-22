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
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_id')->nullable()->index();
            $table->string('from_unit');
            $table->string('to_unit');
            $table->decimal('conversion_factor', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // Enforce uniqueness depending on DB engine.
        // In SQLite and Postgres, we can write partial indexes.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX unit_conversions_tenant_global_unique ON unit_conversions (tenant_id, from_unit, to_unit) WHERE product_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX unit_conversions_product_specific_unique ON unit_conversions (tenant_id, product_id, from_unit, to_unit) WHERE product_id IS NOT NULL');
        } else {
            try {
                DB::statement('CREATE UNIQUE INDEX unit_conversions_tenant_global_unique ON unit_conversions (tenant_id, from_unit, to_unit) WHERE product_id IS NULL');
                DB::statement('CREATE UNIQUE INDEX unit_conversions_product_specific_unique ON unit_conversions (tenant_id, product_id, from_unit, to_unit) WHERE product_id IS NOT NULL');
            } catch (\Exception $e) {
                Schema::table('unit_conversions', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'product_id', 'from_unit', 'to_unit'], 'unit_conversions_fallback_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
    }
};
