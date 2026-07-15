<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('normalized_display_name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'normalized_display_name']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'external_reference'], 'customers_tenant_external_reference_unique');
        });

        Schema::create('customer_financial_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->char('currency_code', 3);
            $table->timestamp('opened_at');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->unique(['tenant_id', 'customer_id'], 'customer_financial_accounts_tenant_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_financial_accounts');
        Schema::dropIfExists('customers');
    }
};
