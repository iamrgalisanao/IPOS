<?php

namespace Tests\Feature\StoreCredit;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\StoreCreditRefundIssuance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreCreditAdminReviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $owner;
    private User $viewOnlyUser;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(TenantContext::class)->setTenant($this->tenant);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner = $this->userWithRole('Owner/Admin');
        $this->cashier = $this->userWithRole('Cashier');

        $this->viewOnlyUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer Account Identity Viewer',
            'description' => 'Can view customer account identity only',
        ]);
        $viewRole->permissions()->attach(
            \App\Models\Permission::where('name', 'customer-accounts.view')->firstOrFail()->id
        );
        $this->viewOnlyUser->assignRole($viewRole);
    }

    protected function tearDown(): void
    {
        app(\App\Services\TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_admin_can_review_account_with_ledger_derived_balance_and_sequence_order(): void
    {
        $account = $this->createAccount();
        $credit = $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 10000,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'source_reference' => 'REF-100',
            'posted_at' => now()->subMinutes(10),
        ]);
        $debit = $this->ledgerEntry($account, [
            'ledger_sequence' => 2,
            'amount_centavos' => 3000,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'source_reference' => 'SALE-200',
            'posted_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.review', $account))
            ->assertOk()
            ->assertJsonPath('store_credit.available_balance_centavos', 7000)
            ->assertJsonPath('store_credit.balance_source', 'ledger')
            ->assertJsonPath('store_credit.ledger_entry_count', 2)
            ->assertJsonPath('store_credit.last_ledger_sequence', 2)
            ->assertJsonPath('recent_ledger_entries.0.id', $debit->id)
            ->assertJsonPath('recent_ledger_entries.0.ledger_sequence', 2)
            ->assertJsonPath('recent_ledger_entries.1.id', $credit->id)
            ->assertJsonPath('recent_ledger_entries.1.ledger_sequence', 1);
    }

    public function test_account_index_includes_store_credit_summary_only_for_review_permission(): void
    {
        $account = $this->createAccount();
        $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 2500,
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.index'))
            ->assertOk()
            ->assertJsonPath('customer_financial_accounts.0.id', $account->id)
            ->assertJsonPath('customer_financial_accounts.0.store_credit.available_balance_centavos', 2500);

        $this->actingAs($this->viewOnlyUser)
            ->getJson(route('admin.customer-accounts.index'))
            ->assertOk()
            ->assertJsonMissingPath('customer_financial_accounts.0.store_credit');
    }

    public function test_store_credit_review_requires_dedicated_review_permission(): void
    {
        $account = $this->createAccount();

        $this->actingAs($this->viewOnlyUser)
            ->getJson(route('admin.customer-accounts.review', $account))
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->getJson(route('admin.customer-accounts.review', $account))
            ->assertForbidden();
    }

    public function test_ledger_history_filters_and_exposes_refund_source_evidence(): void
    {
        $account = $this->createAccount();
        $refundCredit = $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'amount_centavos' => 5000,
            'source_type' => 'sale_refund',
            'source_reference' => 'REFUND-001',
            'business_date' => '2026-07-15',
        ]);
        $this->refundIssuance($account, $refundCredit);

        $this->ledgerEntry($account, [
            'ledger_sequence' => 2,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'amount_centavos' => 1200,
            'source_type' => 'sale_payment',
            'source_reference' => 'SALE-002',
            'business_date' => '2026-07-15',
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.ledger.index', [
                'customerFinancialAccount' => $account,
                'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            ]))
            ->assertOk()
            ->assertJsonPath('ledger_entries.meta.total', 1)
            ->assertJsonPath('ledger_entries.data.0.id', $refundCredit->id)
            ->assertJsonPath('ledger_entries.data.0.source.link_type', 'store_credit_refund_issuance')
            ->assertJsonPath('ledger_entries.data.0.source.link_available', true)
            ->assertJsonPath('ledger_entries.data.0.source.sale_refund_id', fn ($value) => filled($value));
    }

    public function test_ledger_detail_exposes_redemption_source_evidence(): void
    {
        $account = $this->createAccount();
        $redemptionDebit = $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'amount_centavos' => 2400,
            'source_type' => 'sale_payment',
            'source_reference' => 'SALE-REDEEM-001',
        ]);
        $redemption = $this->redemption($account, $redemptionDebit);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.ledger.show', [$account, $redemptionDebit]))
            ->assertOk()
            ->assertJsonPath('ledger_entry.id', $redemptionDebit->id)
            ->assertJsonPath('ledger_entry.source.link_type', 'store_credit_redemption')
            ->assertJsonPath('ledger_entry.source.store_credit_redemption_id', $redemption->id)
            ->assertJsonPath('ledger_entry.source.sale_payment_id', $redemption->sale_payment_id)
            ->assertJsonPath('ledger_entry.source.authorized_balance_centavos', 10000);
    }

    public function test_cross_tenant_account_and_ledger_review_are_hidden(): void
    {
        $otherTenant = Tenant::factory()->create(['currency' => 'PHP']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherAccount = $this->createAccount();
        $otherEntry = $this->ledgerEntry($otherAccount, [
            'branch_id' => $otherBranch->id,
            'ledger_sequence' => 1,
            'amount_centavos' => 1000,
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.review', $otherAccount))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.ledger.show', [$this->createAccount(), $otherEntry]))
            ->assertNotFound();
    }

    public function test_anonymized_customer_review_preserves_evidence_without_personal_fields(): void
    {
        $account = $this->createAccount([
            'display_name' => 'Original Name',
            'email' => 'privacy@example.test',
            'phone' => '+639171234567',
            'external_reference' => 'EXT-PRIVATE',
            'status' => Customer::STATUS_ANONYMIZED,
            'anonymized_at' => now(),
        ]);
        $account->customer->forceFill([
            'display_name' => 'Anonymized Customer 12345678',
            'email' => null,
            'phone' => null,
            'external_reference' => null,
        ])->save();

        $entry = $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 8000,
            'source_reference' => 'REF-PRIVATE',
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.review', $account))
            ->assertOk()
            ->assertJsonPath('customer_financial_account.customer_status', Customer::STATUS_ANONYMIZED)
            ->assertJsonPath('customer_financial_account.customer_email', null)
            ->assertJsonPath('customer_financial_account.customer_phone', null)
            ->assertJsonPath('customer_financial_account.customer_external_reference', null)
            ->assertJsonPath('recent_ledger_entries.0.id', $entry->id);
    }

    public function test_review_endpoints_do_not_mutate_ledger_or_accounting_state(): void
    {
        $account = $this->createAccount();
        $entry = $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 6400,
        ]);
        $before = [
            'ledger' => StoreCreditLedgerEntry::count(),
            'refund_issuances' => StoreCreditRefundIssuance::count(),
            'redemptions' => StoreCreditRedemption::count(),
            'accounting_outbox' => AccountingOutbox::count(),
        ];

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.review', $account))
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.ledger.index', $account))
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.ledger.show', [$account, $entry]))
            ->assertOk();

        $this->assertSame($before['ledger'], StoreCreditLedgerEntry::count());
        $this->assertSame($before['refund_issuances'], StoreCreditRefundIssuance::count());
        $this->assertSame($before['redemptions'], StoreCreditRedemption::count());
        $this->assertSame($before['accounting_outbox'], AccountingOutbox::count());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $role = Role::where('tenant_id', $this->tenant->id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user->assignRole($role);

        return $user;
    }

    private function createAccount(array $customerAttributes = []): CustomerFinancialAccount
    {
        $customer = Customer::factory()->create(array_merge([
            'tenant_id' => app(TenantContext::class)->getTenantId(),
        ], $customerAttributes));

        return CustomerFinancialAccount::factory()->create([
            'tenant_id' => app(TenantContext::class)->getTenantId(),
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ]);
    }

    private function ledgerEntry(CustomerFinancialAccount $account, array $overrides = []): StoreCreditLedgerEntry
    {
        return StoreCreditLedgerEntry::factory()->create(array_merge([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $overrides['branch_id'] ?? $this->branch->id,
            'customer_financial_account_id' => $account->id,
            'ledger_sequence' => 1,
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 1000,
            'currency_code' => 'PHP',
            'source_type' => 'review_test',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'REVIEW-TEST',
            'source_snapshot' => ['ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION],
            'idempotency_key' => 'review-test-' . Str::uuid(),
            'business_date' => '2026-07-15',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
        ], $overrides));
    }

    private function refundIssuance(
        CustomerFinancialAccount $account,
        StoreCreditLedgerEntry $entry
    ): StoreCreditRefundIssuance {
        $sale = Sale::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
        ]);
        $refund = SaleRefund::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
            'sale_id' => $sale->id,
        ]);

        return StoreCreditRefundIssuance::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
            'sale_id' => $sale->id,
            'sale_refund_id' => $refund->id,
            'customer_financial_account_id' => $account->id,
            'store_credit_ledger_entry_id' => $entry->id,
            'amount_centavos' => $entry->amount_centavos,
            'currency_code' => $entry->currency_code,
        ]);
    }

    private function redemption(CustomerFinancialAccount $account, StoreCreditLedgerEntry $entry): StoreCreditRedemption
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
        ]);
        $payment = SalePayment::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
            'sale_id' => $sale->id,
            'shift_id' => null,
            'amount' => $entry->amount_centavos / 100,
            'created_by' => $this->owner->id,
        ]);

        return StoreCreditRedemption::factory()->create([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $entry->branch_id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'customer_financial_account_id' => $account->id,
            'store_credit_ledger_entry_id' => $entry->id,
            'amount_centavos' => $entry->amount_centavos,
            'currency_code' => $entry->currency_code,
            'authorized_balance_centavos' => 10000,
            'redeemed_by' => $this->owner->id,
        ]);
    }
}
