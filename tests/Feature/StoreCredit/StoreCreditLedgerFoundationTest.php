<?php

namespace Tests\Feature\StoreCredit;

use App\Exceptions\StoreCredit\StoreCreditLedgerAccountStateException;
use App\Exceptions\StoreCredit\StoreCreditLedgerCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditLedgerIdempotencyDriftException;
use App\Exceptions\StoreCredit\StoreCreditLedgerInsufficientBalanceException;
use App\Exceptions\StoreCredit\StoreCreditLedgerSourceConflictException;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\StoreCreditLedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\StoreCredit\StoreCreditBalanceService;
use App\Services\StoreCredit\StoreCreditLedgerService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StoreCreditLedgerFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $actor;
    private StoreCreditLedgerService $ledgerService;
    private StoreCreditBalanceService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->ledgerService = app(StoreCreditLedgerService::class);
        $this->balanceService = app(StoreCreditBalanceService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_ledger_schema_uses_integer_centavos_and_has_no_account_balance_columns(): void
    {
        $this->assertTrue(Schema::hasTable('store_credit_ledger_entries'));
        $this->assertTrue(Schema::hasTable('store_credit_ledger_sequences'));
        $this->assertTrue(Schema::hasColumn('store_credit_ledger_entries', 'amount_centavos'));
        $this->assertTrue(Schema::hasColumn('store_credit_ledger_entries', 'fingerprint_version'));
        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'store_credit_balance'));
        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'points_balance'));
    }

    public function test_credit_and_debit_entries_derive_expected_balance(): void
    {
        $account = $this->createAccount();

        $credit = $this->postEntry($account, [
            'idempotency_key' => 'credit-100',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 10000,
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $debit = $this->postEntry($account, [
            'idempotency_key' => 'debit-35',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'amount_centavos' => 3500,
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->assertSame(1, $credit->ledger_sequence);
        $this->assertSame(2, $debit->ledger_sequence);
        $this->assertSame(6500, $this->balanceService->availableBalanceCentavos($account));
    }

    public function test_ledger_rows_are_append_only(): void
    {
        $entry = $this->postEntry($this->createAccount(), [
            'idempotency_key' => 'immutable-row',
            'amount_centavos' => 1200,
        ]);

        $this->expectException(RuntimeException::class);
        $entry->amount_centavos = 9999;
        $entry->save();
    }

    public function test_ledger_rows_cannot_be_deleted(): void
    {
        $entry = $this->postEntry($this->createAccount(), [
            'idempotency_key' => 'immutable-delete',
            'amount_centavos' => 1200,
        ]);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_negative_balance_is_blocked(): void
    {
        $this->expectException(StoreCreditLedgerInsufficientBalanceException::class);

        $this->postEntry($this->createAccount(), [
            'idempotency_key' => 'overdrawn',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'amount_centavos' => 1,
        ]);
    }

    public function test_idempotent_replay_returns_original_entry_without_duplicate_value(): void
    {
        $account = $this->createAccount();
        $payload = [
            'idempotency_key' => 'same-request',
            'amount_centavos' => 2500,
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
        ];

        $first = $this->postEntry($account, $payload);
        $second = $this->postEntry($account, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StoreCreditLedgerEntry::query()->count());
        $this->assertSame(2500, $this->balanceService->availableBalanceCentavos($account));
        $this->assertDatabaseHas('audit_logs', ['action' => 'STORE_CREDIT_LEDGER_IDEMPOTENCY_REPLAYED']);
    }

    public function test_idempotency_drift_is_rejected(): void
    {
        $account = $this->createAccount();

        $this->postEntry($account, [
            'idempotency_key' => 'drift-key',
            'amount_centavos' => 2500,
        ]);

        $this->expectException(StoreCreditLedgerIdempotencyDriftException::class);

        $this->postEntry($account, [
            'idempotency_key' => 'drift-key',
            'amount_centavos' => 2600,
        ]);
    }

    public function test_blank_idempotency_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->postEntry($this->createAccount(), [
            'idempotency_key' => '',
            'amount_centavos' => 1000,
        ]);
    }

    public function test_currency_mismatch_and_closed_account_movements_are_rejected(): void
    {
        $account = $this->createAccount();

        try {
            $this->postEntry($account, [
                'idempotency_key' => 'bad-currency',
                'currency_code' => 'USD',
                'amount_centavos' => 1000,
            ]);

            $this->fail('Currency mismatch should be rejected.');
        } catch (StoreCreditLedgerCurrencyMismatchException) {
            $this->assertTrue(true);
        }

        $account->forceFill(['status' => CustomerFinancialAccount::STATUS_CLOSED])->save();

        $this->expectException(StoreCreditLedgerAccountStateException::class);
        $this->postEntry($account->fresh(), [
            'idempotency_key' => 'closed-account',
            'amount_centavos' => 1000,
        ]);
    }

    public function test_suspended_account_allows_recovery_credit_but_blocks_debit(): void
    {
        $account = $this->createAccount(['status' => CustomerFinancialAccount::STATUS_SUSPENDED]);

        $credit = $this->postEntry($account, [
            'idempotency_key' => 'suspended-credit',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REVERSAL_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 1000,
        ]);

        $this->assertSame(1000, $credit->amount_centavos);

        $this->expectException(StoreCreditLedgerAccountStateException::class);
        $this->postEntry($account, [
            'idempotency_key' => 'suspended-debit',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'amount_centavos' => 100,
        ]);
    }

    public function test_source_uniqueness_applies_only_when_source_id_is_present(): void
    {
        $account = $this->createAccount();
        $sourceId = (string) \Illuminate\Support\Str::uuid();

        $this->postEntry($account, [
            'idempotency_key' => 'source-1',
            'amount_centavos' => 1000,
            'source_type' => 'refund',
            'source_id' => $sourceId,
        ]);

        $this->expectException(StoreCreditLedgerSourceConflictException::class);
        $this->postEntry($account, [
            'idempotency_key' => 'source-2',
            'amount_centavos' => 1000,
            'source_type' => 'refund',
            'source_id' => $sourceId,
        ]);
    }

    public function test_null_source_id_does_not_provide_source_uniqueness_guarantee(): void
    {
        $account = $this->createAccount();

        $this->postEntry($account, [
            'idempotency_key' => 'null-source-1',
            'amount_centavos' => 1000,
            'source_type' => 'manual_foundation_test',
            'source_id' => null,
        ]);

        $this->postEntry($account, [
            'idempotency_key' => 'null-source-2',
            'amount_centavos' => 1000,
            'source_type' => 'manual_foundation_test',
            'source_id' => null,
        ]);

        $this->assertSame(2, StoreCreditLedgerEntry::query()->count());
    }

    public function test_liability_payload_builder_does_not_persist_accounting_outbox_for_foundation_postings(): void
    {
        $account = $this->createAccount();
        $entry = $this->postEntry($account, [
            'idempotency_key' => 'payload-only',
            'amount_centavos' => 7700,
        ]);

        $payload = $this->ledgerService->buildAccountingLiabilityPayload($entry);

        $this->assertSame(1, $payload['event_version']);
        $this->assertSame($entry->id, $payload['ledger_entry_id']);
        $this->assertSame('PHP', $payload['account_currency']);
        $this->assertSame('PHP', $payload['currency_code']);
        $this->assertSame(1, $payload['ledger_schema_version']);
        $this->assertSame(0, AccountingOutbox::query()->count());
    }

    public function test_tenant_isolation_is_enforced_for_ledger_lookup_and_posting(): void
    {
        $otherTenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(TenantContext::class)->setTenant($otherTenant);
        $otherAccount = $this->createAccount();

        app(TenantContext::class)->setTenant($this->tenant);

        $this->assertSame(0, StoreCreditLedgerEntry::query()->count());
        $this->expectException(RuntimeException::class);
        $this->postEntry($otherAccount, [
            'idempotency_key' => 'cross-tenant',
            'amount_centavos' => 1000,
        ]);
    }

    public function test_ledger_posting_has_no_sale_payment_refund_loyalty_or_outbox_side_effects(): void
    {
        $before = [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'sale_refunds' => SaleRefund::count(),
            'accounting_outbox' => AccountingOutbox::count(),
        ];

        $this->postEntry($this->createAccount(), [
            'idempotency_key' => 'no-side-effects',
            'amount_centavos' => 1000,
        ]);

        $this->assertSame($before['sales'], Sale::count());
        $this->assertSame($before['sale_payments'], SalePayment::count());
        $this->assertSame($before['sale_refunds'], SaleRefund::count());
        $this->assertSame($before['accounting_outbox'], AccountingOutbox::count());
    }

    private function createAccount(array $attributes = []): CustomerFinancialAccount
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        return CustomerFinancialAccount::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ], $attributes));
    }

    private function postEntry(CustomerFinancialAccount $account, array $overrides): StoreCreditLedgerEntry
    {
        return $this->ledgerService->post($account, array_merge([
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'ledger-' . \Illuminate\Support\Str::uuid(),
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 1000,
            'currency_code' => 'PHP',
            'source_type' => 'manual_foundation_test',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'source_reference' => 'FOUNDATION',
            'source_snapshot' => ['ledger_schema_version' => 1, 'reason' => 'foundation test'],
            'business_date' => '2026-07-15',
        ], $overrides), $this->actor);
    }
}
