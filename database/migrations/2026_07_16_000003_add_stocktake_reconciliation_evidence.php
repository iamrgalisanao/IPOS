<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_revision')->default(1)->after('current_stock');
        });

        Schema::table('stocktake_sessions', function (Blueprint $table) {
            $table->timestamp('count_started_at')->nullable()->after('notes');
            $table->unsignedBigInteger('count_start_movement_sequence')->nullable()->after('count_started_at');
            $table->string('stocktake_operation_mode')->default('movement_aware')->after('count_start_movement_sequence');
            $table->string('stocktake_scope_type')->default('selected_products')->after('stocktake_operation_mode');
            $table->unsignedInteger('session_revision')->default(1)->after('stocktake_scope_type');
            $table->timestamp('posting_preview_generated_at')->nullable()->after('session_revision');
            $table->unsignedBigInteger('posting_preview_latest_movement_sequence')->nullable()->after('posting_preview_generated_at');
            $table->unsignedBigInteger('posting_preview_inventory_revision')->nullable()->after('posting_preview_latest_movement_sequence');
            $table->unsignedBigInteger('posted_movement_sequence_min')->nullable()->after('posting_preview_inventory_revision');
            $table->unsignedBigInteger('posted_movement_sequence_max')->nullable()->after('posted_movement_sequence_min');
            $table->unsignedSmallInteger('posting_schema_version')->default(1)->after('posted_movement_sequence_max');
            $table->unsignedSmallInteger('projection_policy_version')->default(1)->after('posting_schema_version');
            $table->string('posting_evidence_quality')->default('legacy')->after('projection_policy_version');
            $table->json('posting_summary_snapshot')->nullable()->after('posting_evidence_quality');

            $table->index(['tenant_id', 'branch_id', 'stocktake_operation_mode'], 'idx_stocktake_sessions_mode');
            $table->index(['tenant_id', 'branch_id', 'stocktake_scope_type'], 'idx_stocktake_sessions_scope');
        });

        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->decimal('expected_quantity_at_count_start', 19, 4)->nullable()->after('expected_quantity');
            $table->unsignedBigInteger('count_start_movement_sequence')->nullable()->after('expected_quantity_at_count_start');
            $table->timestamp('count_start_stock_snapshot_at')->nullable()->after('count_start_movement_sequence');
            $table->decimal('raw_count_start_difference', 19, 4)->nullable()->after('variance_quantity');
            $table->uuid('count_snapshot_uuid')->nullable()->after('raw_count_start_difference');
            $table->unsignedSmallInteger('count_snapshot_schema_version')->default(1)->after('count_snapshot_uuid');
            $table->timestamp('physically_counted_at')->nullable()->after('counted_at');
            $table->timestamp('count_recorded_at')->nullable()->after('physically_counted_at');
            $table->unsignedBigInteger('counted_inventory_revision')->nullable()->after('count_recorded_at');
            $table->unsignedBigInteger('counted_movement_sequence')->nullable()->after('counted_inventory_revision');
            $table->decimal('expected_quantity_at_count_time', 19, 4)->nullable()->after('counted_movement_sequence');
            $table->decimal('physical_count_variance_quantity', 19, 4)->nullable()->after('expected_quantity_at_count_time');
            $table->decimal('movement_during_count_delta', 19, 4)->nullable()->after('physical_count_variance_quantity');
            $table->json('movement_during_count_summary')->nullable()->after('movement_during_count_delta');
            $table->unsignedBigInteger('movement_during_count_sequence_from')->nullable()->after('movement_during_count_summary');
            $table->unsignedBigInteger('movement_during_count_sequence_to')->nullable()->after('movement_during_count_sequence_from');
            $table->unsignedInteger('movement_during_count_count')->nullable()->after('movement_during_count_sequence_to');
            $table->decimal('movement_after_count_delta', 19, 4)->nullable()->after('movement_during_count_count');
            $table->json('movement_after_count_summary')->nullable()->after('movement_after_count_delta');
            $table->unsignedBigInteger('movement_after_count_sequence_from')->nullable()->after('movement_after_count_summary');
            $table->unsignedBigInteger('movement_after_count_sequence_to')->nullable()->after('movement_after_count_sequence_from');
            $table->unsignedInteger('movement_after_count_count')->nullable()->after('movement_after_count_sequence_to');
            $table->decimal('expected_quantity_at_posting', 19, 4)->nullable()->after('movement_after_count_count');
            $table->unsignedBigInteger('posting_inventory_revision_before')->nullable()->after('expected_quantity_at_posting');
            $table->unsignedBigInteger('posting_inventory_revision_after')->nullable()->after('posting_inventory_revision_before');
            $table->decimal('counted_quantity_projected_to_posting', 19, 4)->nullable()->after('posting_inventory_revision_after');
            $table->decimal('posted_variance_quantity', 19, 4)->nullable()->after('counted_quantity_projected_to_posting');
            $table->string('posting_outcome')->nullable()->after('posted_variance_quantity');
            $table->unsignedSmallInteger('projection_policy_version')->default(1)->after('posting_outcome');
            $table->unsignedSmallInteger('reason_schema_version')->default(1)->after('projection_policy_version');
            $table->uuid('posting_movement_id')->nullable()->after('reason_schema_version');
            $table->unsignedBigInteger('posting_movement_sequence')->nullable()->after('posting_movement_id');
            $table->string('posting_evidence_quality')->default('legacy')->after('posting_movement_sequence');
            $table->json('posting_snapshot')->nullable()->after('posting_evidence_quality');

            $table->index(['tenant_id', 'branch_id', 'stocktake_session_id', 'posting_movement_id'], 'idx_stocktake_lines_posting_movement');
            $table->index(['tenant_id', 'branch_id', 'count_start_movement_sequence'], 'idx_stocktake_lines_count_start_seq');
            $table->index(['tenant_id', 'branch_id', 'counted_movement_sequence'], 'idx_stocktake_lines_counted_seq');
            $table->index(['tenant_id', 'branch_id', 'posting_movement_sequence'], 'idx_stocktake_lines_posting_seq');
        });
    }

    public function down(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->dropIndex('idx_stocktake_lines_posting_movement');
            $table->dropIndex('idx_stocktake_lines_count_start_seq');
            $table->dropIndex('idx_stocktake_lines_counted_seq');
            $table->dropIndex('idx_stocktake_lines_posting_seq');

            $table->dropColumn([
                'expected_quantity_at_count_start',
                'count_start_movement_sequence',
                'count_start_stock_snapshot_at',
                'raw_count_start_difference',
                'count_snapshot_uuid',
                'count_snapshot_schema_version',
                'physically_counted_at',
                'count_recorded_at',
                'counted_inventory_revision',
                'counted_movement_sequence',
                'expected_quantity_at_count_time',
                'physical_count_variance_quantity',
                'movement_during_count_delta',
                'movement_during_count_summary',
                'movement_during_count_sequence_from',
                'movement_during_count_sequence_to',
                'movement_during_count_count',
                'movement_after_count_delta',
                'movement_after_count_summary',
                'movement_after_count_sequence_from',
                'movement_after_count_sequence_to',
                'movement_after_count_count',
                'expected_quantity_at_posting',
                'posting_inventory_revision_before',
                'posting_inventory_revision_after',
                'counted_quantity_projected_to_posting',
                'posted_variance_quantity',
                'posting_outcome',
                'projection_policy_version',
                'reason_schema_version',
                'posting_movement_id',
                'posting_movement_sequence',
                'posting_evidence_quality',
                'posting_snapshot',
            ]);
        });

        Schema::table('stocktake_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_stocktake_sessions_mode');
            $table->dropIndex('idx_stocktake_sessions_scope');

            $table->dropColumn([
                'count_started_at',
                'count_start_movement_sequence',
                'stocktake_operation_mode',
                'stocktake_scope_type',
                'session_revision',
                'posting_preview_generated_at',
                'posting_preview_latest_movement_sequence',
                'posting_preview_inventory_revision',
                'posted_movement_sequence_min',
                'posted_movement_sequence_max',
                'posting_schema_version',
                'projection_policy_version',
                'posting_evidence_quality',
                'posting_summary_snapshot',
            ]);
        });

        Schema::table('branch_inventories', function (Blueprint $table) {
            $table->dropColumn('inventory_revision');
        });
    }
};
