<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CustomerFinancialAccount;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreCreditRedemption> */
class StoreCreditRedemptionFactory extends Factory
{
    protected $model = StoreCreditRedemption::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $tenantId = $tenantContext->hasTenant()
            ? $tenantContext->getTenantId()
            : Tenant::factory();

        return [
            'tenant_id' => $tenantId,
            'branch_id' => Branch::factory()->state(['tenant_id' => $tenantId]),
            'sale_id' => Sale::factory()->state(['tenant_id' => $tenantId]),
            'sale_payment_id' => SalePayment::factory()->state(['tenant_id' => $tenantId]),
            'customer_financial_account_id' => CustomerFinancialAccount::factory()->state(['tenant_id' => $tenantId]),
            'store_credit_ledger_entry_id' => StoreCreditLedgerEntry::factory()->state(['tenant_id' => $tenantId]),
            'amount_centavos' => 1000,
            'currency_code' => 'PHP',
            'idempotency_key' => 'factory-redemption-' . Str::uuid(),
            'authorized_balance_centavos' => 2000,
            'source_snapshot' => [
                'snapshot_version' => StoreCreditRedemption::SNAPSHOT_VERSION,
                'authorization_schema_version' => StoreCreditRedemption::AUTHORIZATION_SCHEMA_VERSION,
                'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            ],
            'redeemed_by' => User::factory()->state(['tenant_id' => $tenantId]),
            'redeemed_at' => now(),
        ];
    }
}
