<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_refund_issuances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('sale_refund_id')->constrained('sale_refunds')->restrictOnDelete();
            $table->foreignUuid('customer_financial_account_id')
                ->constrained('customer_financial_accounts')
                ->restrictOnDelete();
            $table->foreignUuid('store_credit_ledger_entry_id')
                ->constrained('store_credit_ledger_entries')
                ->restrictOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->char('currency_code', 3);
            $table->string('idempotency_key');
            $table->json('source_snapshot');
            $table->uuid('issued_by')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('sale_refund_id', 'store_credit_refund_issuances_refund_unique');
            $table->unique('store_credit_ledger_entry_id', 'store_credit_refund_issuances_ledger_unique');
            $table->unique(
                ['tenant_id', 'customer_financial_account_id', 'idempotency_key'],
                'store_credit_refund_issuances_tenant_account_idempotency_unique'
            );
            $table->index(
                ['tenant_id', 'customer_financial_account_id'],
                'store_credit_refund_issuances_tenant_account_index'
            );
            $table->index(['tenant_id', 'sale_id'], 'store_credit_refund_issuances_tenant_sale_index');
            $table->index(['tenant_id', 'issued_at'], 'store_credit_refund_issuances_tenant_issued_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_refund_issuances');
    }
};
