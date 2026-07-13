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
        Schema::create('sale_promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('sales_machine_profiles')->nullOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignUuid('promotion_rule_id')->constrained('promotion_rules')->cascadeOnDelete();
            $table->string('promotion_name');
            $table->string('rule_type');
            $table->string('condition_type');
            $table->string('reward_type');
            $table->integer('priority');
            $table->boolean('stackable');
            $table->string('exclusive_group')->nullable();
            $table->bigInteger('base_amount_centavos')->default(0);
            $table->bigInteger('discount_amount_centavos')->default(0);
            $table->jsonb('rule_snapshot_json');
            $table->jsonb('calculation_snapshot_json');
            $table->string('promotion_rules_version_hash');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'sale_id'], 'sale_promotions_lookup_index');
        });

        Schema::create('sale_promotion_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_promotion_id')->constrained('sale_promotions')->cascadeOnDelete();
            $table->foreignUuid('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('role'); // trigger, reward, discounted, bundled
            $table->decimal('quantity_applied', 12, 3);
            $table->bigInteger('original_amount_centavos');
            $table->bigInteger('discount_amount_centavos')->default(0);
            $table->bigInteger('final_amount_centavos');
            $table->timestamps();

            $table->index(['sale_promotion_id', 'sale_item_id'], 'sale_promotion_lines_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_promotion_lines');
        Schema::dropIfExists('sale_promotions');
    }
};
