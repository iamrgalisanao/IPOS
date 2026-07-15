<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_reversal_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('reverse_earned_on_void')->default(true);
            $table->boolean('reverse_earned_on_refund')->default(true);
            $table->boolean('restore_redeemed_on_void')->default(true);
            $table->boolean('restore_redeemed_on_refund')->default(true);
            $table->boolean('allow_negative_balance')->default(true);
            $table->boolean('require_approval_for_negative_balance')->default(true);
            $table->unsignedBigInteger('negative_balance_approval_threshold_points')->default(0);
            $table->string('restore_redeemed_on_partial_refund_policy')->default('proportional');
            $table->string('refund_earn_reversal_policy')->default('item_linked_then_proportional');
            $table->unsignedSmallInteger('settings_schema_version')->default(1);
            $table->timestamps();

            $table->unique('tenant_id', 'loyalty_reversal_settings_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reversal_settings');
    }
};
