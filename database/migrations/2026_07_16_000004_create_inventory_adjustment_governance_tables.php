<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustment_reasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reason_uuid')->index();
            $table->uuid('tenant_id')->index();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('reason_category', 50)->index();
            $table->string('direction_policy', 50)->index();
            $table->boolean('requires_notes')->default(false);
            $table->boolean('evidence_required')->default(false);
            $table->boolean('is_opening_balance')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->string('active_slot', 20)->nullable();
            $table->unsignedInteger('reason_version')->default(1);
            $table->unsignedInteger('reason_schema_version')->default(1);
            $table->foreignUuid('supersedes_reason_id')->nullable()->constrained('inventory_adjustment_reasons')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'active_slot'], 'uniq_active_adjustment_reason_code');
            $table->unique(['tenant_id', 'reason_uuid', 'reason_version'], 'uniq_adjustment_reason_version');
            $table->index(['tenant_id', 'reason_category']);
            $table->index(['tenant_id', 'direction_policy']);
        });

        Schema::create('inventory_adjustment_approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUuid('reason_id')->nullable()->constrained('inventory_adjustment_reasons')->nullOnDelete();
            $table->decimal('minimum_absolute_quantity', 18, 4)->nullable();
            $table->string('threshold_unit', 64)->nullable();
            $table->decimal('minimum_percentage_of_stock', 8, 4)->nullable();
            $table->unsignedBigInteger('minimum_value_centavos')->nullable();
            $table->string('required_permission')->default('inventory.adjustment.approve');
            $table->boolean('requires_distinct_approver')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('rule_version')->default(1);
            $table->unsignedInteger('rule_schema_version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'is_active'], 'idx_adj_rules_branch_active');
            $table->index(['tenant_id', 'reason_id', 'is_active'], 'idx_adj_rules_reason_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_approval_rules');
        Schema::dropIfExists('inventory_adjustment_reasons');
    }
};
