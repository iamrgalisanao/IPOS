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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_inventory_id')->nullable()->constrained()->nullOnDelete();
            
            $table->string('movement_type'); // in, out, adjustment, sale, return, etc.
            $table->decimal('quantity_change', 19, 4);
            $table->decimal('quantity_before', 19, 4);
            $table->decimal('quantity_after', 19, 4);
            
            $table->string('source_type')->nullable(); // e.g. SaleItem, StockAdjustment
            $table->string('source_id')->nullable(); // Flexible ID for external refs
            $table->string('reference_number')->nullable(); // e.g. Invoice #
            
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason_code')->nullable();
            $table->text('remarks')->nullable();
            
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
