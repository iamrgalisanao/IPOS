<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->json('layout_metadata');
            $table->unsignedInteger('layout_revision')->default(1);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id', 'is_active']);
            $table->unique(['tenant_id', 'branch_id', 'normalized_name'], 'service_areas_branch_name_unique');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('service_area_id')->constrained('service_areas')->restrictOnDelete();
            $table->string('table_number');
            $table->unsignedSmallInteger('capacity');
            $table->string('operational_state')->default('available');
            $table->json('position_metadata');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['service_area_id', 'is_active']);
            $table->unique(['service_area_id', 'table_number'], 'dining_tables_area_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('service_areas');
    }
};
