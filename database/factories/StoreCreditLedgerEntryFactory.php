<?php

namespace Database\Factories;

use App\Models\CustomerFinancialAccount;
use App\Models\StoreCreditLedgerEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreCreditLedgerEntry> */
class StoreCreditLedgerEntryFactory extends Factory
{
    protected $model = StoreCreditLedgerEntry::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $tenantId = $tenantContext->hasTenant()
            ? $tenantContext->getTenantId()
            : Tenant::factory();

        return [
            'tenant_id' => $tenantId,
            'branch_id' => null,
            'customer_financial_account_id' => CustomerFinancialAccount::factory()->state(['tenant_id' => $tenantId]),
            'ledger_sequence' => 1,
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 1000,
            'currency_code' => 'PHP',
            'source_type' => 'factory',
            'source_id' => (string) Str::uuid(),
            'source_reference' => null,
            'source_snapshot' => ['ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION],
            'idempotency_key' => 'factory-' . Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'fingerprint_version' => StoreCreditLedgerEntry::FINGERPRINT_VERSION,
            'business_date' => now()->toDateString(),
            'posted_by' => null,
            'posted_at' => now(),
        ];
    }
}
