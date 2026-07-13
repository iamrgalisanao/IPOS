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
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('rule_type'); // bogo, discount_tier, combo_package
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->char('currency', 3)->default('PHP');
            $table->string('timezone')->default('Asia/Manila');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'starts_at', 'ends_at'], 'promotions_active_date_index');
        });

        Schema::create('promotion_branches', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['promotion_id', 'branch_id']);
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('schema_version')->default('v1');
            $table->string('condition_type');
            $table->string('reward_type');
            $table->jsonb('conditions');
            $table->jsonb('rewards');
            $table->boolean('stackable')->default(false);
            $table->integer('min_spend_centavos')->default(0);
            $table->integer('max_applications_per_sale')->nullable();
            $table->bigInteger('max_discount_centavos')->nullable();
            $table->string('exclusive_group')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['promotion_id', 'is_active'], 'promotion_rules_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotion_branches');
        Schema::dropIfExists('promotions');
    }
};
