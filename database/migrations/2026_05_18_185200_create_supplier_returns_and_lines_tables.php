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
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('purchase_receiving_id')->nullable()->constrained('purchase_receivings')->nullOnDelete();
            
            $table->string('document_number');
            $table->string('status')->default('draft'); // draft, pending_approval, approved, posted, cancelled
            $table->date('return_date');
            $table->decimal('total_amount', 19, 4)->default(0.0000);
            $table->text('notes')->nullable();
            
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Constraints & Indexes
            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index('supplier_id');
            $table->index('purchase_receiving_id');
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_return_id')->constrained('supplier_returns')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('expiry_lot_id')->nullable()->constrained('expiry_lots')->nullOnDelete();
            
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('line_total', 19, 4);
            
            $table->string('batch_code')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('supplier_return_id');
            $table->index('product_id');
            $table->index('expiry_lot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
    }
};
