<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('manager_approvals', function (Blueprint $table) {
            $table->uuid('sales_machine_profile_id')->nullable()->index();
            $table->uuid('discount_type_id')->nullable()->index();
            $table->uuid('approval_rule_id')->nullable()->index();
            $table->string('context_version')->nullable();
            $table->string('context_hmac', 64)->nullable()->index();
            $table->enum('status', ['issued', 'consumed', 'expired', 'revoked'])->default('issued')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('consumed_by_sale_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('manager_approvals', function (Blueprint $table) {
            $table->dropColumn([
                'sales_machine_profile_id', 'discount_type_id', 'approval_rule_id',
                'context_version', 'context_hmac', 'status', 'expires_at',
                'consumed_at', 'consumed_by_sale_id',
            ]);
        });
    }
};
