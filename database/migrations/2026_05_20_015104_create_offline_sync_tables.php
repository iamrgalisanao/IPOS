<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Offline Sync Batches ─────────────────────────────────────────────
        Schema::create('offline_sync_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->string('sales_machine_profile_id');
            $table->string('batch_reference');
            $table->string('status')->default('received');
            $table->unsignedInteger('submitted_import_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_completed_at')->nullable();
            $table->timestamps();

            // Prevent replay: same terminal cannot submit the same batch twice
            $table->unique(
                ['tenant_id', 'sales_machine_profile_id', 'batch_reference'],
                'osb_tenant_profile_batchref_unique'
            );
        });

        // 2. Offline Sales Imports ────────────────────────────────────────────
        Schema::create('offline_sales_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->string('sales_machine_profile_id');
            $table->uuid('batch_id');
            $table->string('offline_sequence_number');
            $table->string('payload_hash');
            $table->json('raw_payload');
            $table->string('status')->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->uuid('reconciled_sale_id')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            // Non-unique index for dedup lookup — duplicate rows are intentionally
            // preserved (marked status=duplicate) for full audit visibility.
            $table->index(
                ['tenant_id', 'sales_machine_profile_id', 'payload_hash'],
                'osi_tenant_profile_hash_idx'
            );

            $table->foreign('batch_id')
                  ->references('id')
                  ->on('offline_sync_batches')
                  ->restrictOnDelete();
        });

        // 3. Offline Terminal Journals ────────────────────────────────────────
        Schema::create('offline_terminal_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->string('sales_machine_profile_id');
            $table->date('journal_date');
            $table->string('status')->default('provisional');
            $table->decimal('provisional_gross_total', 15, 4)->default(0);
            $table->unsignedInteger('provisional_item_count')->default(0);
            $table->text('reconciliation_notes')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'sales_machine_profile_id', 'journal_date'],
                'otj_tenant_profile_date_idx'
            );
        });

        // 4. Offline Sequence Recoveries ─────────────────────────────────────
        Schema::create('offline_sequence_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('sales_machine_profile_id');
            $table->string('recovery_type');
            $table->string('affected_prefix');
            $table->unsignedBigInteger('affected_range_start')->nullable();
            $table->unsignedBigInteger('affected_range_end')->nullable();
            $table->string('resolution')->nullable();
            $table->uuid('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sales_machine_profile_id'], 'osr_tenant_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sequence_recoveries');
        Schema::dropIfExists('offline_terminal_journals');
        Schema::dropIfExists('offline_sales_imports');
        Schema::dropIfExists('offline_sync_batches');
    }
};
