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
        Schema::create('branch_payment_method_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_offline')->default(false);
            $table->unsignedBigInteger('offline_max_limit_centavos')->nullable();
            $table->boolean('requires_reference')->default(false);
            $table->integer('sort_order')->default(0);
            $table->text('offline_policy_note')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'payment_method_id'], 'branch_payment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_payment_method_settings');
    }
};
