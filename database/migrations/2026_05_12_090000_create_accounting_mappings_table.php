<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('provider');
            $table->string('mapping_type');
            $table->string('pos_entity_type')->nullable();
            $table->uuid('pos_entity_id')->nullable();
            $table->string('pos_key')->nullable();
            $table->string('external_id');
            $table->string('external_name')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'provider', 'mapping_type', 'status'], 'acct_map_lookup_idx');
            $table->index(['tenant_id', 'branch_id', 'provider', 'mapping_type'], 'acct_map_branch_idx');
            $table->index(['tenant_id', 'provider', 'mapping_type', 'pos_entity_type', 'pos_entity_id'], 'acct_map_entity_idx');
            $table->index(['tenant_id', 'provider', 'mapping_type', 'pos_key'], 'acct_map_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_mappings');
    }
};