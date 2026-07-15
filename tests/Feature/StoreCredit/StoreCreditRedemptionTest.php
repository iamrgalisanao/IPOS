<?php

namespace Tests\Feature\StoreCredit;

use App\Jobs\Inventory\ProcessSaleInventoryDeductionJob;
use App\Models\Branch;
use App\Models\BranchPaymentMethodSetting;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\StoreCredit\StoreCreditBalanceService;
use App\Services\StoreCredit\StoreCreditLedgerService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;

class StoreCreditRedemptionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithShifts;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $storeCreditMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSaleInventoryDeductionJob::class]);

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->cashier->assignToBranch($this->branch);
        $this->giveUserPermission($this->cashier, 'create_sale');
        $this->giveUserPermission($this->cashier, 'open_shift');

        $this->openShiftFor($this->cashier, $this->branch);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => false,
            'status' => 'active',
        ]);

        $this->storeCreditMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STORE_CREDIT',
            'name' => 'Store Credit',
            'type' => 'store_credit',
            'reference_required' => false,
            'settlement_tracking_enabled' => true,
            'status' => 'active',
        ]);

        $this->actingAs($this->cashier);
    }

    public function test_full_store_credit_payment_records_payment_and_debits_ledger(): void
    {
        $sale = $this->sale('125.0000');
        $account = $this->accountWithCredit(20000);
        $key = (string) Str::uuid();

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '125.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => [
                    'verification_method' => 'cashier_confirmed_customer',
                    'verification_reference' => 'customer-present',
                ],
            ]],
        ], $key);

        $response->assertOk()
            ->assertJsonPath('status', 'recorded')
            ->assertJsonPath('payments.0.store_credit.customer_financial_account_id', $account->id)
            ->assertJsonPath('payments.0.store_credit.amount_centavos', 12500)
            ->assertJsonPath('payments.0.store_credit.authorized_balance_centavos', 20000);

        $payment = SalePayment::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('store_credit', $payment->payment_type);

        $this->assertDatabaseHas('store_credit_ledger_entries', [
            'customer_financial_account_id' => $account->id,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'amount_centavos' => 12500,
        ]);
        $this->assertDatabaseHas('store_credit_redemptions', [
            'sale_payment_id' => $payment->id,
            'customer_financial_account_id' => $account->id,
            'amount_centavos' => 12500,
            'authorized_balance_centavos' => 20000,
        ]);
        $this->assertDatabaseHas('accounting_outbox', ['event_type' => 'sale_paid']);
        $this->assertDatabaseHas('accounting_outbox', ['event_type' => 'store_credit_redeemed']);
        $this->assertSame(7500, app(StoreCreditBalanceService::class)->availableBalanceCentavos($account));
    }

    public function test_split_payment_with_store_credit_and_cash_succeeds(): void
    {
        $sale = $this->sale('150.0000');
        $account = $this->accountWithCredit(8000);

        $response = $this->postSplitPayment($sale, [
            'payments' => [
                [
                    'payment_method_id' => $this->storeCreditMethod->id,
                    'amount' => '50.0000',
                    'customer_financial_account_id' => $account->id,
                    'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
                ],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '100.0000'],
            ],
        ], (string) Str::uuid());

        $response->assertOk()
            ->assertJsonPath('payment_count', 2)
            ->assertJsonPath('amount_paid', '150.0000');

        $this->assertSame(2, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(3000, app(StoreCreditBalanceService::class)->availableBalanceCentavos($account));
    }

    public function test_single_store_credit_payment_endpoint_succeeds(): void
    {
        $sale = $this->sale('40.0000');
        $account = $this->accountWithCredit(5000);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson(route('pos.sales.payments', ['sale_id' => $sale->id]), [
            'payment_method_id' => $this->storeCreditMethod->id,
            'amount' => '40.0000',
            'customer_financial_account_id' => $account->id,
            'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
        ]);

        $response->assertOk()
            ->assertJsonPath('store_credit.customer_financial_account_id', $account->id)
            ->assertJsonPath('store_credit.amount_centavos', 4000);

        $this->assertSame('paid', $sale->refresh()->status);
        $this->assertSame(1000, app(StoreCreditBalanceService::class)->availableBalanceCentavos($account));
    }

    public function test_store_credit_payment_replay_does_not_duplicate_payment_or_debit(): void
    {
        $sale = $this->sale('75.0000');
        $account = $this->accountWithCredit(10000);
        $key = (string) Str::uuid();
        $payload = [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '75.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ];

        $first = $this->postSplitPayment($sale, $payload, $key);
        $first->assertOk();

        $second = $this->postSplitPayment($sale, $payload, $key);
        $second->assertOk()
            ->assertHeader('X-Cache-Lookup', 'HIT - Idempotent response')
            ->assertJsonPath('payments.0.payment_id', $first->json('payments.0.payment_id'))
            ->assertJsonPath('payments.0.store_credit.ledger_entry_id', $first->json('payments.0.store_credit.ledger_entry_id'));

        $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(1, StoreCreditRedemption::where('customer_financial_account_id', $account->id)->count());
        $this->assertSame(2, StoreCreditLedgerEntry::where('customer_financial_account_id', $account->id)->count());
        $this->assertDatabaseCount('accounting_outbox', 2);
    }

    public function test_store_credit_payment_drift_rejected_before_mutation(): void
    {
        $sale = $this->sale('75.0000');
        $account = $this->accountWithCredit(10000);
        $key = (string) Str::uuid();

        $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '75.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], $key)->assertOk();

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '70.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], $key);

        $response->assertStatus(409)
            ->assertJsonFragment(['code' => 'IDEMPOTENCY_KEY_REUSE']);
        $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(1, StoreCreditRedemption::where('customer_financial_account_id', $account->id)->count());
    }

    public function test_insufficient_balance_rolls_back_payment(): void
    {
        $sale = $this->sale('100.0000');
        $account = $this->accountWithCredit(5000);

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '100.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], (string) Str::uuid());

        $response->assertStatus(409)
            ->assertJsonFragment(['code' => 'INSUFFICIENT_STORE_CREDIT_BALANCE']);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame('created', $sale->refresh()->status);
        $this->assertSame(0, StoreCreditRedemption::count());
        $this->assertSame(1, StoreCreditLedgerEntry::where('customer_financial_account_id', $account->id)->count());
    }

    public function test_suspended_account_cannot_redeem(): void
    {
        $sale = $this->sale('50.0000');
        $account = $this->accountWithCredit(10000);
        $account->forceFill(['status' => CustomerFinancialAccount::STATUS_SUSPENDED])->save();

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '50.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], (string) Str::uuid());

        $response->assertStatus(409)
            ->assertJsonFragment(['code' => 'STORE_CREDIT_ACCOUNT_NOT_REDEEMABLE']);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_closed_account_cannot_redeem(): void
    {
        $sale = $this->sale('50.0000');
        $account = $this->accountWithCredit(10000);
        $account->forceFill(['status' => CustomerFinancialAccount::STATUS_CLOSED])->save();

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '50.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], (string) Str::uuid());

        $response->assertStatus(409)
            ->assertJsonFragment(['code' => 'STORE_CREDIT_ACCOUNT_NOT_REDEEMABLE']);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_cross_tenant_account_cannot_redeem(): void
    {
        $sale = $this->sale('50.0000');
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherAccount = CustomerFinancialAccount::factory()->create([
            'tenant_id' => $otherTenant->id,
            'customer_id' => $otherCustomer->id,
            'currency_code' => 'PHP',
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '50.0000',
                'customer_financial_account_id' => $otherAccount->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], (string) Str::uuid());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.customer_financial_account_id']);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(0, StoreCreditRedemption::count());
    }

    public function test_currency_mismatch_rolls_back_payment(): void
    {
        $sale = $this->sale('50.0000');
        $account = $this->accountWithCredit(10000);
        $account->forceFill(['currency_code' => 'USD'])->saveQuietly();

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '50.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ], (string) Str::uuid());

        $response->assertStatus(409)
            ->assertJsonFragment(['code' => 'STORE_CREDIT_REDEMPTION_FAILED']);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_missing_idempotency_key_required_for_store_credit_payment_only(): void
    {
        $sale = $this->sale('50.0000');
        $account = $this->accountWithCredit(10000);

        $response = $this->postSplitPayment($sale, [
            'payments' => [[
                'payment_method_id' => $this->storeCreditMethod->id,
                'amount' => '50.0000',
                'customer_financial_account_id' => $account->id,
                'store_credit_authorization' => ['verification_method' => 'cashier_confirmed_customer'],
            ]],
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['code' => 'MISSING_IDEMPOTENCY_KEY']);

        $cashSale = $this->sale('50.0000');
        $this->postSplitPayment($cashSale, [
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '50.0000',
            ]],
        ])->assertOk();
    }

    public function test_duplicate_active_store_credit_payment_method_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only one active Store Credit payment method may exist per tenant.');

        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STORE_CREDIT_ALT',
            'name' => 'Store Credit Duplicate',
            'type' => 'store_credit',
            'reference_required' => false,
            'status' => 'active',
        ]);
    }

    public function test_offline_payment_policy_rejects_store_credit_even_if_misconfigured_for_offline(): void
    {
        BranchPaymentMethodSetting::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->storeCreditMethod->id,
            'enabled' => true,
            'allow_offline' => true,
            'requires_reference' => false,
            'sort_order' => 1,
        ]);

        $profile = SalesMachineProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
        ]);
        $batch = OfflineSyncBatch::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $profile->id,
            'batch_reference' => 'BATCH-' . Str::uuid(),
            'status' => OfflineSyncBatch::STATUS_PROCESSING,
            'submitted_import_count' => 1,
            'sync_started_at' => now(),
        ]);
        $import = OfflineSalesImport::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $profile->id,
            'batch_id' => $batch->id,
            'offline_sequence_number' => 'OFF-' . $profile->profile_code . '-1-1',
            'payload_hash' => '',
            'status' => OfflineSalesImport::STATUS_PENDING,
            'submitted_at' => now(),
            'raw_payload' => [
                'payments' => [[
                    'payment_method_id' => $this->storeCreditMethod->id,
                    'amount' => 50,
                ]],
                'payment_methods_version_hash' => app(\App\Services\POS\OfflineReadiness\CacheBootstrapService::class)
                    ->calculatePaymentMethodsVersionHash($this->tenant->id, $this->branch->id),
            ],
        ]);
        $reason = null;
        $context = null;

        $result = app(OfflineReconciliationService::class)->validatePaymentPolicy($import, $reason, $context);

        $this->assertFalse($result);
        $this->assertSame('STORE_CREDIT_OFFLINE_REDEMPTION_NOT_ALLOWED', $reason);
        $this->assertSame('STORE_CREDIT', $context['payment_method_code']);
    }

    private function sale(string $total): Sale
    {
        return Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'sale_number' => 'SALE-' . Str::upper(Str::random(6)),
            'subtotal' => $total,
            'total' => $total,
            'status' => 'created',
        ]);
    }

    private function accountWithCredit(int $amountCentavos): CustomerFinancialAccount
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = CustomerFinancialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ]);

        app(StoreCreditLedgerService::class)->post($account, [
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'seed-credit:' . Str::uuid(),
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => $amountCentavos,
            'currency_code' => 'PHP',
            'source_type' => 'test_seed_credit',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'TEST-SEED',
            'source_snapshot' => ['ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION],
            'business_date' => now()->toDateString(),
        ], $this->cashier);

        return $account->refresh();
    }

    private function postSplitPayment(Sale $sale, array $payload, ?string $idempotencyKey = null): \Illuminate\Testing\TestResponse
    {
        $headers = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ];

        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withHeaders($headers)
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $sale->id]), $payload);
    }

    private function giveUserPermission(User $user, string $permissionName): void
    {
        $permission = \App\Models\Permission::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => $permissionName,
        ]);

        $role = \App\Models\Role::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => 'Story 39.4 ' . $permissionName,
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->assignRole($role);
    }
}
