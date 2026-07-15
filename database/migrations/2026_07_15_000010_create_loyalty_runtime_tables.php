<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'customer_financial_account_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreignUuid('customer_financial_account_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('customer_financial_accounts')
                    ->restrictOnDelete();

                $table->index(
                    ['tenant_id', 'customer_financial_account_id'],
                    'sales_tenant_customer_financial_account_index'
                );
            });
        }

        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('code');
            $table->string('rule_type');
            $table->unsignedInteger('rule_version')->default(1);
            $table->string('status')->default('active');
            $table->unsignedInteger('priority')->default(0);
            $table->json('configuration');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'code', 'rule_version'], 'loyalty_rules_tenant_branch_code_version_unique');
            $table->index(['tenant_id', 'rule_type', 'status'], 'loyalty_rules_tenant_type_status_index');
        });

        Schema::create('loyalty_ledger_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_financial_account_id')
                ->constrained('customer_financial_accounts')
                ->restrictOnDelete();
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_financial_account_id'], 'loyalty_sequences_tenant_account_unique');
        });

        Schema::create('loyalty_ledger_entries', function (Blueprint $table) {
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
            $table->unsignedBigInteger('points');
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

            $table->index(['tenant_id', 'customer_financial_account_id'], 'loyalty_entries_tenant_account_index');
            $table->index(['tenant_id', 'customer_financial_account_id', 'ledger_sequence'], 'loyalty_entries_tenant_account_sequence_index');
            $table->index(['tenant_id', 'business_date'], 'loyalty_entries_tenant_business_date_index');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'loyalty_entries_tenant_source_index');
            $table->unique(['customer_financial_account_id', 'ledger_sequence'], 'loyalty_entries_account_sequence_unique');
            $table->unique(['tenant_id', 'customer_financial_account_id', 'idempotency_key'], 'loyalty_entries_tenant_account_idempotency_unique');
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('customer_financial_account_id')
                ->constrained('customer_financial_accounts')
                ->restrictOnDelete();
            $table->foreignUuid('loyalty_rule_id')->constrained('loyalty_rules')->restrictOnDelete();
            $table->foreignUuid('loyalty_ledger_entry_id')
                ->nullable()
                ->unique('loyalty_redemptions_ledger_unique')
                ->constrained('loyalty_ledger_entries')
                ->restrictOnDelete();
            $table->unsignedBigInteger('points');
            $table->unsignedBigInteger('benefit_centavos');
            $table->unsignedBigInteger('authorized_balance_points');
            $table->string('status')->default('pending');
            $table->string('idempotency_key');
            $table->json('rule_snapshot');
            $table->json('source_snapshot');
            $table->uuid('redeemed_by')->nullable();
            $table->timestamp('authorized_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sale_id'], 'loyalty_redemptions_tenant_sale_unique');
            $table->unique(['tenant_id', 'customer_financial_account_id', 'idempotency_key'], 'loyalty_redemptions_tenant_account_idempotency_unique');
            $table->index(['tenant_id', 'customer_financial_account_id', 'status'], 'loyalty_redemptions_tenant_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_ledger_entries');
        Schema::dropIfExists('loyalty_ledger_sequences');
        Schema::dropIfExists('loyalty_rules');

        if (Schema::hasColumn('sales', 'customer_financial_account_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropForeign(['customer_financial_account_id']);
                $table->dropIndex('sales_tenant_customer_financial_account_index');
                $table->dropColumn('customer_financial_account_id');
            });
        }
    }
};
