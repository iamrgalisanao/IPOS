<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_split_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('split_request_uuid');
            $table->string('request_fingerprint', 64);
            $table->foreignUuid('parent_ticket_id')->constrained('dining_tickets')->restrictOnDelete();
            $table->foreignUuid('child_ticket_id')->constrained('dining_tickets')->restrictOnDelete();
            $table->foreignUuid('child_ticket_item_id')->constrained('dining_ticket_items')->restrictOnDelete();
            $table->foreignUuid('source_ticket_item_id')->constrained('dining_ticket_items')->restrictOnDelete();
            $table->string('allocation_method');
            $table->unsignedInteger('allocation_sequence');
            $table->decimal('allocated_quantity', 12, 3);
            $table->unsignedBigInteger('allocated_amount_centavos');
            $table->unsignedBigInteger('promotion_discount_centavos')->default(0);
            $table->bigInteger('rounding_adjustment_centavos')->default(0);
            $table->json('promotion_allocation_snapshot')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'branch_id'], 'bill_split_allocations_tenant_branch_index');
            $table->index(['tenant_id', 'branch_id', 'parent_ticket_id'], 'bill_split_allocations_parent_index');
            $table->index(['tenant_id', 'branch_id', 'child_ticket_id'], 'bill_split_allocations_child_index');
            $table->index(['tenant_id', 'branch_id', 'source_ticket_item_id'], 'bill_split_allocations_source_item_index');
            $table->index(['tenant_id', 'branch_id', 'parent_ticket_id', 'split_request_uuid'], 'bill_split_allocations_request_index');
            $table->unique(['tenant_id', 'branch_id', 'child_ticket_item_id'], 'bill_split_allocations_child_item_unique');
            $table->unique(['parent_ticket_id', 'allocation_sequence'], 'bill_split_allocations_parent_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_split_allocations');
    }
};
