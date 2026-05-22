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
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignUuid('purchase_receiving_id')->nullable()->constrained('purchase_receivings')->nullOnDelete();
            
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            
            $table->decimal('subtotal', 19, 4)->default(0.0000);
            $table->decimal('tax_total', 19, 4)->default(0.0000);
            $table->decimal('total_amount', 19, 4)->default(0.0000);
            
            $table->string('match_status')->default('pending'); // pending, matched, discrepant, posted
            $table->text('notes')->nullable();
            
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            
            $table->timestamps();

            // Constraints & Indexes
            $table->unique(['tenant_id', 'supplier_id', 'invoice_number'], 'uq_tenant_supplier_invoice');
            $table->index(['tenant_id', 'branch_id', 'match_status']);
            $table->index('supplier_id');
            $table->index('purchase_order_id');
            $table->index('purchase_receiving_id');
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignUuid('purchase_receiving_line_id')->nullable()->constrained('purchase_receiving_lines')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            
            $table->decimal('quantity_billed', 19, 4);
            $table->decimal('unit_cost_billed', 19, 4);
            $table->decimal('line_total', 19, 4);
            
            $table->timestamps();

            // Indexes
            $table->index('supplier_invoice_id');
            $table->index('purchase_receiving_line_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
    }
};
