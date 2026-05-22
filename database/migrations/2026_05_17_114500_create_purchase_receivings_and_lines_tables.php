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
        Schema::create('purchase_receivings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('receiving_number');
            $table->string('status'); // draft, posted, cancelled
            $table->string('delivery_ref_number')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->decimal('total_received_amount', 19, 4)->default(0.0000);
            $table->text('notes')->nullable();

            $table->foreignUuid('received_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Constraints
            $table->unique(['tenant_id', 'receiving_number']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index('supplier_id');
            $table->index('purchase_order_id');
        });

        Schema::create('purchase_receiving_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_receiving_id')->constrained('purchase_receivings')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('ordered_quantity', 19, 4)->default(0.0000);
            $table->decimal('received_quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_receiving_lines');
        Schema::dropIfExists('purchase_receivings');
    }
};
