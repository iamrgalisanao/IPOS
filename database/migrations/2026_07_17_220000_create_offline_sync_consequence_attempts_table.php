<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_consequence_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->uuid('offline_sales_import_id');
            $table->uuid('sale_id')->nullable();
            $table->string('consequence_type');
            $table->string('status')->default('pending');
            $table->string('idempotency_key');
            $table->unsignedInteger('attempt_no')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claim_owner')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_summary')->nullable();
            $table->string('result_reference_type')->nullable();
            $table->string('result_reference_id')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('offline_sales_import_id')
                ->references('id')
                ->on('offline_sales_imports')
                ->cascadeOnDelete();

            $table->unique(
                ['offline_sales_import_id', 'consequence_type', 'idempotency_key'],
                'osca_import_type_idempotency_unique'
            );
            $table->index(['tenant_id', 'branch_id', 'consequence_type', 'status'], 'osca_scope_type_status_idx');
            $table->index(['status', 'available_at'], 'osca_status_available_idx');
            $table->index(['sale_id'], 'osca_sale_id_idx');
        });

        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->integer('reported_sync_delay_seconds')->nullable()->after('business_date_review_reason');
            $table->integer('normalized_sync_delay_seconds')->nullable()->after('reported_sync_delay_seconds');
            $table->timestamp('offline_capture_timestamp')->nullable()->after('normalized_sync_delay_seconds');
            $table->timestamp('server_accepted_at')->nullable()->after('offline_capture_timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->dropColumn([
                'reported_sync_delay_seconds',
                'normalized_sync_delay_seconds',
                'offline_capture_timestamp',
                'server_accepted_at',
            ]);
        });

        Schema::dropIfExists('offline_sync_consequence_attempts');
    }
};
