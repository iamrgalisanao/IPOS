<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_variance_logs', function (Blueprint $table) {
            $table->uuid('variance_uuid')->nullable()->after('id');
            $table->unsignedSmallInteger('variance_schema_version')->default(1)->after('variance_uuid');
            $table->string('variance_category')->default('negative_stock')->after('variance_schema_version');
            $table->string('current_status')->default('open')->after('variance_category');
            $table->foreignUuid('movement_id')->nullable()->after('current_status')->constrained('inventory_movements')->nullOnDelete();
            $table->string('movement_uuid')->nullable()->after('movement_id');
            $table->unsignedBigInteger('movement_sequence')->nullable()->after('movement_uuid');
            $table->foreignUuid('branch_inventory_id')->nullable()->after('movement_sequence')->constrained('branch_inventories')->nullOnDelete();
            $table->foreignUuid('sale_item_id')->nullable()->after('sale_id')->constrained('sale_items')->nullOnDelete();
            $table->foreignUuid('ingredient_product_id')->nullable()->after('ingredient_id')->constrained('products')->nullOnDelete();
            $table->string('source_type')->nullable()->after('ingredient_product_id');
            $table->string('source_id')->nullable()->after('source_type');
            $table->string('source_reference')->nullable()->after('source_id');
            $table->string('source_effect_key', 160)->nullable()->after('source_reference');
            $table->decimal('quantity_before', 19, 4)->nullable()->after('source_effect_key');
            $table->decimal('quantity_required', 19, 4)->nullable()->after('quantity_before');
            $table->decimal('quantity_delta', 19, 4)->nullable()->after('quantity_required');
            $table->decimal('quantity_after', 19, 4)->nullable()->after('quantity_delta');
            $table->decimal('incremental_shortage_quantity', 19, 4)->nullable()->after('quantity_after');
            $table->decimal('resulting_negative_quantity', 19, 4)->nullable()->after('incremental_shortage_quantity');
            $table->json('policy_snapshot')->nullable()->after('metadata');
            $table->json('unit_snapshot')->nullable()->after('policy_snapshot');
            $table->json('conversion_snapshot')->nullable()->after('unit_snapshot');
            $table->json('source_snapshot')->nullable()->after('conversion_snapshot');
            $table->foreignUuid('first_reviewed_by')->nullable()->after('source_snapshot')->constrained('users')->nullOnDelete();
            $table->timestamp('first_reviewed_at')->nullable()->after('first_reviewed_by');
            $table->timestamp('resolved_at')->nullable()->after('first_reviewed_at');
            $table->string('terminal_status_reason')->nullable()->after('resolved_at');
        });

        $this->backfillVarianceLogs();

        Schema::table('inventory_variance_logs', function (Blueprint $table) {
            $table->unique('variance_uuid', 'inventory_variance_logs_variance_uuid_unique');
            $table->index(['tenant_id', 'branch_id', 'variance_category', 'current_status'], 'inventory_variance_logs_category_status_idx');
            $table->index(['tenant_id', 'branch_id', 'source_type', 'source_id'], 'inventory_variance_logs_source_idx');
            $table->index(['tenant_id', 'branch_id', 'movement_id'], 'inventory_variance_logs_movement_idx');
            $table->index(['tenant_id', 'branch_id', 'ingredient_product_id'], 'inventory_variance_logs_ingredient_product_idx');
        });

        Schema::create('inventory_variance_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_uuid')->unique();
            $table->unsignedSmallInteger('event_schema_version')->default(1);
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->foreignUuid('inventory_variance_log_id')->constrained('inventory_variance_logs')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('event_type');
            $table->string('reason_code')->nullable();
            $table->text('notes')->nullable();
            $table->string('request_uuid')->nullable();
            $table->string('request_fingerprint')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('event_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['inventory_variance_log_id', 'event_type', 'request_uuid'], 'inventory_variance_status_request_unique');
            $table->index(['tenant_id', 'branch_id', 'inventory_variance_log_id'], 'inventory_variance_status_scope_idx');
        });

        Schema::create('inventory_variance_correction_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->index();
            $table->foreignUuid('inventory_variance_log_id')->constrained('inventory_variance_logs')->cascadeOnDelete();
            $table->foreignUuid('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->uuid('stocktake_session_id')->nullable();
            $table->uuid('stocktake_line_id')->nullable();
            $table->string('correction_type');
            $table->decimal('linked_quantity', 19, 4)->nullable();
            $table->string('relationship_type');
            $table->string('reason_code')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('link_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['tenant_id', 'branch_id', 'inventory_variance_log_id'], 'inventory_variance_links_scope_idx');
            $table->index(['tenant_id', 'branch_id', 'inventory_movement_id'], 'inventory_variance_links_movement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_variance_correction_links');
        Schema::dropIfExists('inventory_variance_status_events');

        Schema::table('inventory_variance_logs', function (Blueprint $table) {
            $table->dropIndex('inventory_variance_logs_category_status_idx');
            $table->dropIndex('inventory_variance_logs_source_idx');
            $table->dropIndex('inventory_variance_logs_movement_idx');
            $table->dropIndex('inventory_variance_logs_ingredient_product_idx');
            $table->dropUnique('inventory_variance_logs_variance_uuid_unique');
            $table->dropConstrainedForeignId('movement_id');
            $table->dropConstrainedForeignId('branch_inventory_id');
            $table->dropConstrainedForeignId('sale_item_id');
            $table->dropConstrainedForeignId('ingredient_product_id');
            $table->dropConstrainedForeignId('first_reviewed_by');
            $table->dropColumn([
                'variance_uuid',
                'variance_schema_version',
                'variance_category',
                'current_status',
                'movement_uuid',
                'movement_sequence',
                'source_type',
                'source_id',
                'source_reference',
                'source_effect_key',
                'quantity_before',
                'quantity_required',
                'quantity_delta',
                'quantity_after',
                'incremental_shortage_quantity',
                'resulting_negative_quantity',
                'policy_snapshot',
                'unit_snapshot',
                'conversion_snapshot',
                'source_snapshot',
                'first_reviewed_at',
                'resolved_at',
                'terminal_status_reason',
            ]);
        });
    }

    protected function backfillVarianceLogs(): void
    {
        DB::table('inventory_variance_logs')
            ->orderBy('id')
            ->chunkById(100, function ($logs) {
                foreach ($logs as $log) {
                    $quantityRequired = (float) $log->required_quantity;
                    $quantityBefore = (float) $log->available_quantity_before;
                    $quantityAfter = (float) $log->resulting_quantity;
                    $incrementalShortage = $quantityBefore < 0
                        ? $quantityRequired
                        : max(0, $quantityRequired - max($quantityBefore, 0));

                    DB::table('inventory_variance_logs')
                        ->where('id', $log->id)
                        ->update([
                            'variance_uuid' => (string) Str::orderedUuid(),
                            'variance_schema_version' => 1,
                            'variance_category' => 'negative_stock',
                            'current_status' => 'open',
                            'source_type' => 'sale',
                            'source_id' => $log->sale_id,
                            'quantity_before' => number_format($quantityBefore, 4, '.', ''),
                            'quantity_required' => number_format($quantityRequired, 4, '.', ''),
                            'quantity_delta' => number_format(-$quantityRequired, 4, '.', ''),
                            'quantity_after' => number_format($quantityAfter, 4, '.', ''),
                            'incremental_shortage_quantity' => number_format($incrementalShortage, 4, '.', ''),
                            'resulting_negative_quantity' => number_format(abs(min($quantityAfter, 0)), 4, '.', ''),
                            'ingredient_product_id' => $log->ingredient_id,
                        ]);
                }
            });
    }
};
