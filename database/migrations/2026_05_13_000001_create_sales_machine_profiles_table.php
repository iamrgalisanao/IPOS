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
        Schema::create('sales_machine_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('profile_code');
            $table->string('machine_identification_number')->nullable();
            $table->string('machine_serial_number')->nullable();
            $table->string('software_license_number')->nullable();
            $table->string('permit_to_use_number')->nullable();
            $table->timestamp('permit_issued_at')->nullable();
            $table->string('authority_to_generate_control_number')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_tin')->nullable();
            $table->string('supplier_branch_code', 5)->nullable();
            $table->string('supplier_address')->nullable();
            $table->string('supplier_accreditation_number')->nullable();
            $table->timestamp('supplier_accreditation_issued_at')->nullable();
            $table->timestamp('supplier_accreditation_expires_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'profile_code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_machine_profiles');
    }
};