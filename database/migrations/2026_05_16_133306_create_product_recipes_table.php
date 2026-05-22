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
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            
            // The composite product (e.g. Burger)
            $table->uuid('product_id')->index();
            
            // The ingredient product (e.g. Bun)
            $table->uuid('ingredient_id')->index();
            
            $table->decimal('quantity', 19, 4);
            $table->string('unit')->comment('Recipe-specific unit of measure');
            
            $table->timestamps();

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('ingredient_id')->references('id')->on('products')->onDelete('cascade');
            
            // Prevent duplicate ingredients in a single product recipe
            $table->unique(['product_id', 'ingredient_id'], 'product_recipe_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
    }
};
