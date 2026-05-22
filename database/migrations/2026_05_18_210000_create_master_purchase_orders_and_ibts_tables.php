<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. master_purchase_orders ────────────────────────────────────────
        Schema::create('master_purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            $table->string('master_po_number');
            $table->string('status')->default('draft'); // draft, pending_approval, approved, split, completed, cancelled
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->decimal('total_estimated_amount', 19, 4)->default(0.0000);
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('split_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'master_po_number']);
            $table->index(['tenant_id', 'status']);
            $table->index('supplier_id');
        });

        // ─── 2. master_purchase_order_lines ───────────────────────────────────
        Schema::create('master_purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('master_purchase_order_id')->constrained('master_purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();

            $table->decimal('total_ordered_quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();

            $table->unique(['master_purchase_order_id', 'product_id']);
            $table->index('master_purchase_order_id');
            $table->index('product_id');
        });

        // ─── 3. master_purchase_order_allocations ─────────────────────────────
        Schema::create('master_purchase_order_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('master_purchase_order_line_id')
                ->constrained('master_purchase_order_lines')
                ->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('child_purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->nullOnDelete();

            $table->decimal('allocated_quantity', 19, 4);
            $table->timestamps();

            // Explicit short name — PostgreSQL has a 63-char identifier limit
            $table->unique(['master_purchase_order_line_id', 'branch_id'], 'uq_mpo_alloc_line_branch');
            $table->index('master_purchase_order_line_id', 'idx_mpo_alloc_line');
            $table->index('branch_id', 'idx_mpo_alloc_branch');
            $table->index('child_purchase_order_id', 'idx_mpo_alloc_child_po');
        });

        // ─── 4. inter_branch_transfers ────────────────────────────────────────
        Schema::create('inter_branch_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('source_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('destination_branch_id')->constrained('branches')->cascadeOnDelete();

            $table->string('reference_number');
            $table->string('status')->default('draft'); // draft, pending_approval, approved, in_transit, received, cancelled
            $table->date('transfer_date');
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_number']);
            $table->index(['tenant_id', 'status']);
            $table->index('source_branch_id');
            $table->index('destination_branch_id');
        });

        // ─── 5. inter_branch_transfer_lines ───────────────────────────────────
        Schema::create('inter_branch_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inter_branch_transfer_id')
                ->constrained('inter_branch_transfers')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('expiry_lot_id')->nullable()->constrained('expiry_lots')->nullOnDelete();

            $table->decimal('quantity_transferred', 19, 4);
            // Unit cost = source branch WAC frozen at dispatch time (locked per Q3 decision)
            $table->decimal('unit_cost', 19, 4)->default(0.0000);
            $table->decimal('line_total', 19, 4)->default(0.0000);
            $table->timestamps();

            $table->index('inter_branch_transfer_id');
            $table->index('product_id');
            $table->index('expiry_lot_id');
        });

        // ─── 6. Alter purchase_orders: add nullable master_purchase_order_id ──
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignUuid('master_purchase_order_id')
                ->nullable()
                ->after('id')
                ->constrained('master_purchase_orders')
                ->nullOnDelete();
            $table->index('master_purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['master_purchase_order_id']);
            $table->dropIndex(['master_purchase_order_id']);
            $table->dropColumn('master_purchase_order_id');
        });

        Schema::dropIfExists('inter_branch_transfer_lines');
        Schema::dropIfExists('inter_branch_transfers');
        Schema::dropIfExists('master_purchase_order_allocations');
        Schema::dropIfExists('master_purchase_order_lines');
        Schema::dropIfExists('master_purchase_orders');
    }
};
