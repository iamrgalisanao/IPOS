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
        Schema::create('sale_refund_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->foreignUuid('sale_refund_id')->constrained('sale_refunds');
            $table->foreignUuid('sale_item_id')->constrained('sale_items');
            $table->foreignUuid('product_id')->constrained('products');
            $table->decimal('quantity_refunded', 20, 4);
            $table->decimal('unit_price_snapshot', 20, 4);
            $table->decimal('tax_amount_snapshot', 20, 4);
            $table->decimal('line_refund_total', 20, 4);
            $table->string('restock_action'); // restock, damaged, disposed, do_not_restock
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_refund_items');
    }
};
