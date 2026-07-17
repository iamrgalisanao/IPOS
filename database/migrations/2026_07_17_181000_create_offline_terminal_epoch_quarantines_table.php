<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_terminal_epoch_quarantines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('branch_id');
            $table->string('sales_machine_profile_id');
            $table->string('terminal_binding_epoch');
            $table->string('quarantine_reason');
            $table->uuid('source_offline_import_id');
            $table->string('quarantine_status')->default('active');
            $table->timestamp('quarantined_at');
            $table->timestamp('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->string('release_reference')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'sales_machine_profile_id', 'terminal_binding_epoch', 'quarantine_status'],
                'otec_active_epoch_unique'
            );
            $table->index(['tenant_id', 'branch_id', 'quarantine_status'], 'otec_tenant_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_terminal_epoch_quarantines');
    }
};
