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
        Schema::table('sale_items', function (Blueprint $table) {
            $table->bigInteger('original_unit_price_centavos')->default(0);
            $table->bigInteger('modifier_adjusted_unit_price_centavos')->default(0);
            $table->bigInteger('promotion_discount_centavos')->default(0);
            $table->bigInteger('promotion_adjusted_unit_price_centavos')->default(0);
            $table->bigInteger('statutory_discount_centavos')->default(0);
            $table->bigInteger('final_unit_price_centavos')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn([
                'original_unit_price_centavos',
                'modifier_adjusted_unit_price_centavos',
                'promotion_discount_centavos',
                'promotion_adjusted_unit_price_centavos',
                'statutory_discount_centavos',
                'final_unit_price_centavos'
            ]);
        });
    }
};
