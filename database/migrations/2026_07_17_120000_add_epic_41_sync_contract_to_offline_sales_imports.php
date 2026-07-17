<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->uuid('offline_transaction_uuid')->nullable()->after('offline_sequence_number');
            $table->string('terminal_binding_epoch')->nullable()->after('offline_transaction_uuid');
            $table->string('local_sequence')->nullable()->after('terminal_binding_epoch');
            $table->string('sync_contract_version')->nullable()->after('payload_hash');
            $table->string('server_payload_fingerprint')->nullable()->after('sync_contract_version');
            $table->string('fingerprint_algorithm')->nullable()->after('server_payload_fingerprint');
            $table->unsignedInteger('fingerprint_schema_version')->nullable()->after('fingerprint_algorithm');
            $table->string('server_sync_status')->nullable()->after('status');
            $table->string('original_sync_status')->nullable()->after('server_sync_status');
            $table->string('review_reason')->nullable()->after('original_sync_status');
            $table->string('retryable_error_code')->nullable()->after('review_reason');
            $table->string('cash_status')->nullable()->after('retryable_error_code');
            $table->string('resolution_status')->nullable()->after('cash_status');
            $table->string('official_invoice_number')->nullable()->after('reconciled_sale_id');
            $table->json('consequence_status_snapshot')->nullable()->after('server_recalculation');
            $table->json('acceptance_consequence_snapshot')->nullable()->after('consequence_status_snapshot');
            $table->json('current_consequence_status')->nullable()->after('acceptance_consequence_snapshot');
            $table->timestamp('first_seen_at')->nullable()->after('review_notes');
            $table->timestamp('last_replayed_at')->nullable()->after('first_seen_at');
            $table->timestamp('accepted_at')->nullable()->after('last_replayed_at');
            $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            $table->timestamp('review_required_at')->nullable()->after('rejected_at');

            $table->unique(['tenant_id', 'offline_transaction_uuid'], 'osi_tenant_offline_uuid_unique');
            $table->unique(
                ['tenant_id', 'sales_machine_profile_id', 'terminal_binding_epoch', 'local_sequence'],
                'osi_tenant_profile_epoch_sequence_unique'
            );
            $table->index(['server_sync_status'], 'osi_server_sync_status_idx');
            $table->index(['review_reason'], 'osi_review_reason_idx');
            $table->index(['server_payload_fingerprint'], 'osi_server_payload_fingerprint_idx');
        });

        Schema::create('offline_sync_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->uuid('offline_sales_import_id');
            $table->uuid('offline_transaction_uuid')->nullable();
            $table->uuid('sync_attempt_id')->nullable();
            $table->uuid('lease_id')->nullable();
            $table->unsignedInteger('attempt_generation')->nullable();
            $table->string('worker')->nullable();
            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('response_finished_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result_status')->nullable();
            $table->string('transient_error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('retryable')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('offline_sales_import_id')
                ->references('id')
                ->on('offline_sales_imports')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'offline_transaction_uuid'], 'osa_tenant_offline_uuid_idx');
            $table->index(['offline_sales_import_id', 'sync_attempt_id'], 'osa_import_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_attempts');

        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->dropUnique('osi_tenant_offline_uuid_unique');
            $table->dropUnique('osi_tenant_profile_epoch_sequence_unique');
            $table->dropIndex('osi_server_sync_status_idx');
            $table->dropIndex('osi_review_reason_idx');
            $table->dropIndex('osi_server_payload_fingerprint_idx');

            $table->dropColumn([
                'offline_transaction_uuid',
                'terminal_binding_epoch',
                'local_sequence',
                'sync_contract_version',
                'server_payload_fingerprint',
                'fingerprint_algorithm',
                'fingerprint_schema_version',
                'server_sync_status',
                'original_sync_status',
                'review_reason',
                'retryable_error_code',
                'cash_status',
                'resolution_status',
                'official_invoice_number',
                'consequence_status_snapshot',
                'acceptance_consequence_snapshot',
                'current_consequence_status',
                'first_seen_at',
                'last_replayed_at',
                'accepted_at',
                'rejected_at',
                'review_required_at',
            ]);
        });
    }
};
