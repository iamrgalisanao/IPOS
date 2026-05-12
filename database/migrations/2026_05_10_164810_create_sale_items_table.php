<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();

            // Immutable product snapshot fields — copied at time of sale creation
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('unit_of_measure')->nullable();

            // Quantity and pricing snapshot
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('subtotal', 19, 4);
            $table->decimal('discount_amount', 19, 4)->default(0);

            // Tax snapshot
            $table->uuid('tax_category_id')->nullable();
            $table->string('tax_type')->nullable();
            $table->decimal('tax_rate', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);

            // Line total = subtotal - discount + tax
            $table->decimal('line_total', 19, 4);

            // Inventory flag snapshot
            $table->boolean('is_inventory_tracked')->default(false);

            // Immutable — no updated_at, no soft deletes
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
