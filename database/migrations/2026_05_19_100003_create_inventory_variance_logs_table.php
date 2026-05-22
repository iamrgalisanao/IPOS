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
        Schema::create('inventory_variance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('sale_id')->index();
            $table->uuid('product_id')->nullable()->index(); // composite/parent product (nullable if direct)
            $table->uuid('ingredient_id')->index(); // the raw ingredient or product that was short
            
            $table->decimal('required_quantity', 19, 4);
            $table->decimal('available_quantity_before', 19, 4);
            $table->decimal('shortage_quantity', 19, 4);
            $table->decimal('resulting_quantity', 19, 4);
            
            $table->string('unit');
            $table->string('policy');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable()->index();
            
            $table->timestamps();

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('ingredient_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_variance_logs');
    }
};
