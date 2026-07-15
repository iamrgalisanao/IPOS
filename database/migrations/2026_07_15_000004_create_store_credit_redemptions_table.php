<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('sale_payment_id')->unique()->constrained('sale_payments')->restrictOnDelete();
            $table->foreignUuid('customer_financial_account_id')->constrained('customer_financial_accounts')->restrictOnDelete();
            $table->foreignUuid('store_credit_ledger_entry_id')->unique()->constrained('store_credit_ledger_entries')->restrictOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->char('currency_code', 3);
            $table->string('idempotency_key');
            $table->unsignedBigInteger('authorized_balance_centavos');
            $table->json('source_snapshot');
            $table->foreignUuid('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_financial_account_id', 'idempotency_key'], 'sc_redemptions_account_idempotency_unique');
            $table->index(['tenant_id', 'customer_financial_account_id'], 'sc_redemptions_account_index');
            $table->index(['tenant_id', 'sale_id'], 'sc_redemptions_sale_index');
            $table->index(['tenant_id', 'redeemed_at'], 'sc_redemptions_redeemed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_redemptions');
    }
};
