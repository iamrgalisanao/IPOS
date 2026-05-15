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
        Schema::create('sale_statutory_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->string('discount_type', 50);
            $table->string('discount_code', 50)->nullable();
            $table->decimal('discount_rate', 19, 4)->nullable();
            $table->decimal('discount_basis_amount', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('vat_adjustment_amount', 19, 4)->nullable();
            $table->decimal('vat_exempt_amount', 19, 4)->nullable();
            $table->string('beneficiary_reference')->nullable();
            $table->string('beneficiary_hash')->nullable();
            $table->string('source', 50)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['sale_id', 'discount_type']);
            $table->index(['sale_item_id', 'discount_type']);
            $table->index(['discount_type']);
            $table->index(['source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_statutory_discounts');
    }
};