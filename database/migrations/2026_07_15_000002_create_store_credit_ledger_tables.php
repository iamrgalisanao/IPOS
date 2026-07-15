<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_ledger_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_financial_account_id')
                ->constrained('customer_financial_accounts')
                ->restrictOnDelete();
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'customer_financial_account_id'],
                'store_credit_sequences_tenant_account_unique'
            );
        });

        Schema::create('store_credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('customer_financial_account_id')
                ->constrained('customer_financial_accounts')
                ->restrictOnDelete();
            $table->unsignedBigInteger('ledger_sequence');
            $table->unsignedSmallInteger('ledger_schema_version')->default(1);
            $table->string('ledger_category');
            $table->string('entry_type');
            $table->string('direction');
            $table->unsignedBigInteger('amount_centavos');
            $table->char('currency_code', 3);
            $table->string('source_type');
            $table->uuid('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('source_snapshot');
            $table->string('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->unsignedSmallInteger('fingerprint_version')->default(1);
            $table->date('business_date');
            $table->uuid('posted_by')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(
                ['tenant_id', 'customer_financial_account_id'],
                'store_credit_entries_tenant_account_index'
            );
            $table->index(
                ['tenant_id', 'customer_financial_account_id', 'ledger_sequence'],
                'store_credit_entries_tenant_account_sequence_index'
            );
            $table->index(
                ['tenant_id', 'customer_financial_account_id', 'posted_at'],
                'store_credit_entries_tenant_account_posted_index'
            );
            $table->index(['tenant_id', 'business_date'], 'store_credit_entries_tenant_business_date_index');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'store_credit_entries_tenant_source_index');
            $table->unique(
                ['customer_financial_account_id', 'ledger_sequence'],
                'store_credit_entries_account_sequence_unique'
            );
            $table->unique(
                ['tenant_id', 'customer_financial_account_id', 'idempotency_key'],
                'store_credit_entries_tenant_account_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_ledger_entries');
        Schema::dropIfExists('store_credit_ledger_sequences');
    }
};
