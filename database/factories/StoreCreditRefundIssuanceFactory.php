<?php

namespace Database\Factories;

use App\Models\CustomerFinancialAccount;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRefundIssuance;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreCreditRefundIssuance> */
class StoreCreditRefundIssuanceFactory extends Factory
{
    protected $model = StoreCreditRefundIssuance::class;

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
            'sale_refund_id' => SaleRefund::factory()->state(['tenant_id' => $tenantId]),
            'customer_financial_account_id' => CustomerFinancialAccount::factory()->state(['tenant_id' => $tenantId]),
            'store_credit_ledger_entry_id' => StoreCreditLedgerEntry::factory()->state(['tenant_id' => $tenantId]),
            'amount_centavos' => 1000,
            'currency_code' => 'PHP',
            'idempotency_key' => 'refund-credit:' . Str::uuid(),
            'source_snapshot' => ['snapshot_version' => StoreCreditRefundIssuance::SNAPSHOT_VERSION],
            'issued_by' => null,
            'issued_at' => now(),
        ];
    }
}
