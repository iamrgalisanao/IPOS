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
        Schema::create('sale_voids', function (Blueprint $バランス) {
            $バランス->uuid('id')->primary();
            $バランス->foreignUuid('tenant_id')->constrained('tenants');
            $バランス->foreignUuid('branch_id')->constrained('branches');
            $バランス->foreignUuid('sale_id')->unique()->constrained('sales');
            $バランス->string('reason_code');
            $バランス->text('reason_notes')->nullable();
            $バランス->foreignUuid('voided_by')->constrained('users');
            $バランス->timestamp('voided_at')->useCurrent();
            $バランス->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_voids');
    }
};
