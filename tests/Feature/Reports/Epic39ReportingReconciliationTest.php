<?php

namespace Tests\Feature\Reports;

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

class Epic39ReportingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branchA;
    private Branch $branchB;
    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch B']);
        $this->owner = $this->userWithRole('Owner/Admin');
        $this->cashier = $this->userWithRole('Cashier');
        $this->cashier->assignToBranch($this->branchA);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_store_credit_liability_report_is_derived_from_ledger_and_separates_currency_totals(): void
    {
        $phpAccount = $this->createAccount('PHP');
        $usdAccount = $this->createAccount('USD');

        $this->ledgerEntry($phpAccount, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 1,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'amount_centavos' => 10000,
            'currency_code' => 'PHP',
        ]);
        $this->ledgerEntry($phpAccount, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 2,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'amount_centavos' => 2500,
            'currency_code' => 'PHP',
        ]);
        $this->ledgerEntry($usdAccount, [
            'branch_id' => $this->branchB->id,
            'ledger_sequence' => 1,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'amount_centavos' => 5000,
            'currency_code' => 'USD',
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.store-credit.liability'))
            ->assertOk()
            ->assertJsonPath('report_name', 'store_credit_liability')
            ->assertJsonPath('report_schema_version', 1)
            ->assertJsonPath('basis', 'business_date')
            ->assertJsonPath('totals_by_currency.PHP.issued_centavos', 10000)
            ->assertJsonPath('totals_by_currency.PHP.redeemed_centavos', 2500)
            ->assertJsonPath('totals_by_currency.PHP.outstanding_liability_centavos', 7500)
            ->assertJsonPath('totals_by_currency.USD.issued_centavos', 5000)
            ->assertJsonPath('totals_by_currency.USD.outstanding_liability_centavos', 5000)
            ->assertJsonPath('rows.meta.total', 3)
            ->assertJsonMissingPath('totals.grand_total_centavos');
    }

    public function test_liability_date_window_reports_period_activity_with_closing_liability_from_prior_history(): void
    {
        $account = $this->createAccount('PHP');
        $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 8000,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'business_date' => '2026-07-01',
        ]);
        $this->ledgerEntry($account, [
            'ledger_sequence' => 2,
            'amount_centavos' => 3000,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'business_date' => '2026-07-15',
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.store-credit.liability', [
                'business_date_from' => '2026-07-10',
                'business_date_to' => '2026-07-20',
            ]))
            ->assertOk()
            ->assertJsonPath('totals_by_currency.PHP.issued_centavos', 0)
            ->assertJsonPath('totals_by_currency.PHP.redeemed_centavos', 3000)
            ->assertJsonPath('totals_by_currency.PHP.total_credits_centavos', 0)
            ->assertJsonPath('totals_by_currency.PHP.total_debits_centavos', 3000)
            ->assertJsonPath('totals_by_currency.PHP.outstanding_liability_centavos', 5000)
            ->assertJsonPath('rows.meta.total', 1);
    }

    public function test_reconciliation_report_surfaces_pending_failed_and_missing_accounting_evidence_without_mutation(): void
    {
        $account = $this->createAccount('PHP');
        $pendingCredit = $this->ledgerEntry($account, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 1,
            'amount_centavos' => 4000,
            'source_type' => 'sale_refund',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
        ]);
        $failedDebit = $this->ledgerEntry($account, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 2,
            'amount_centavos' => 1500,
            'source_type' => 'sale_payment',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
        ]);
        $missingCredit = $this->ledgerEntry($account, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 3,
            'amount_centavos' => 2000,
            'source_type' => 'sale_refund',
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
        ]);
        $this->refundIssuance($account, $pendingCredit);
        $this->redemption($account, $failedDebit);
        $this->refundIssuance($account, $missingCredit);
        $this->accountingOutbox($pendingCredit, 'store_credit_issued', 'pending');
        $this->accountingOutbox($failedDebit, 'store_credit_redeemed', 'failed');

        $before = [
            'ledger' => StoreCreditLedgerEntry::count(),
            'issuance' => StoreCreditRefundIssuance::count(),
            'redemption' => StoreCreditRedemption::count(),
            'outbox' => AccountingOutbox::count(),
        ];

        $response = $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.store-credit.reconciliation'));

        $response->assertOk()
            ->assertJsonPath('health.status', 'critical')
            ->assertJsonPath('warning_count', 1)
            ->assertJsonPath('critical_count', 2)
            ->assertJsonPath('exception_counts.pending_accounting_event', 1)
            ->assertJsonPath('exception_counts.failed_accounting_event', 1)
            ->assertJsonPath('exception_counts.missing_accounting_event', 1)
            ->assertJsonPath('exception_counts.missing_source_evidence', 0)
            ->assertJsonFragment(['ledger_entry_id' => $pendingCredit->id])
            ->assertJsonFragment(['ledger_entry_id' => $failedDebit->id])
            ->assertJsonFragment(['ledger_entry_id' => $missingCredit->id])
            ->assertJsonFragment(['expected_accounting_event_type' => 'store_credit_issued'])
            ->assertJsonFragment(['expected_accounting_event_type' => 'store_credit_redeemed']);

        $this->assertSame($before['ledger'], StoreCreditLedgerEntry::count());
        $this->assertSame($before['issuance'], StoreCreditRefundIssuance::count());
        $this->assertSame($before['redemption'], StoreCreditRedemption::count());
        $this->assertSame($before['outbox'], AccountingOutbox::count());
    }

    public function test_customer_statement_uses_deterministic_ledger_order_and_opening_balance(): void
    {
        $account = $this->createAccount('PHP');
        $this->ledgerEntry($account, [
            'ledger_sequence' => 1,
            'amount_centavos' => 3000,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'business_date' => '2026-07-01',
        ]);
        $newer = $this->ledgerEntry($account, [
            'ledger_sequence' => 2,
            'amount_centavos' => 1200,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_DEBIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'business_date' => '2026-07-15',
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.customer-accounts.statement', [
                'account' => $account,
                'business_date_from' => '2026-07-10',
                'business_date_to' => '2026-07-20',
            ]))
            ->assertOk()
            ->assertJsonPath('opening_balance.store_credit.PHP.centavos', 3000)
            ->assertJsonPath('closing_balance.store_credit.PHP.centavos', 1800)
            ->assertJsonPath('sections.store_credit.rows.0.ledger_entry_id', $newer->id)
            ->assertJsonPath('sections.store_credit.rows.0.ledger_sequence', 2)
            ->assertJsonPath('sections.loyalty.totals.points', 0)
            ->assertJsonPath('sections.loyalty.rows', [])
            ->assertJsonPath('totals.store_credit_row_count', 1)
            ->assertJsonPath('rows.store_credit.0.ledger_entry_id', $newer->id);
    }

    public function test_report_authorization_and_branch_scope_are_enforced(): void
    {
        $account = $this->createAccount('PHP');
        $visible = $this->ledgerEntry($account, [
            'branch_id' => $this->branchA->id,
            'ledger_sequence' => 1,
            'amount_centavos' => 2000,
        ]);
        $this->ledgerEntry($account, [
            'branch_id' => $this->branchB->id,
            'ledger_sequence' => 2,
            'amount_centavos' => 9000,
        ]);

        $this->actingAs($this->cashier)
            ->getJson(route('reports.epic39.store-credit.liability'))
            ->assertForbidden();

        $branchViewer = $this->branchScopedViewer();
        $this->actingAs($branchViewer)
            ->getJson(route('reports.epic39.store-credit.movements'))
            ->assertOk()
            ->assertJsonPath('rows.data.0.ledger_entry_id', $visible->id)
            ->assertJsonPath('rows.meta.total', 1);
    }

    public function test_branch_scoped_statement_hides_accounts_without_visible_branch_evidence(): void
    {
        $account = $this->createAccount('PHP');
        $this->ledgerEntry($account, [
            'branch_id' => $this->branchB->id,
            'ledger_sequence' => 1,
            'amount_centavos' => 9000,
        ]);

        $this->actingAs($this->branchScopedViewer())
            ->getJson(route('reports.epic39.customer-accounts.statement', ['account' => $account]))
            ->assertNotFound();
    }

    public function test_report_date_range_is_bounded(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.store-credit.movements', [
                'business_date_from' => '2025-01-01',
                'business_date_to' => '2026-07-15',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_date_to');
    }

    public function test_loyalty_activity_report_is_points_only_without_monetary_liability(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('reports.epic39.loyalty.activity'))
            ->assertOk()
            ->assertJsonPath('report_name', 'loyalty_activity')
            ->assertJsonPath('totals.points_earned', 0)
            ->assertJsonPath('totals.points_redeemed', 0)
            ->assertJsonPath('totals.points_balance', 0)
            ->assertJsonMissingPath('totals.currency_code')
            ->assertJsonMissingPath('totals.liability_centavos')
            ->assertJsonPath('rows.data', []);
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

    private function branchScopedViewer(): User
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Epic 39 Branch Report Viewer',
            'description' => 'Can view branch-scoped Epic 39 reports',
        ]);
        $role->permissions()->attach(
            \App\Models\Permission::whereIn('name', [
                'reports.customer_accounts.view',
                'reports.store_credit.view',
            ])->pluck('id')->all()
        );
        $viewer->assignRole($role);
        $viewer->assignToBranch($this->branchA);

        return $viewer;
    }

    private function createAccount(string $currency): CustomerFinancialAccount
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        return CustomerFinancialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'currency_code' => $currency,
        ]);
    }

    private function ledgerEntry(CustomerFinancialAccount $account, array $overrides = []): StoreCreditLedgerEntry
    {
        return StoreCreditLedgerEntry::factory()->create(array_merge([
            'tenant_id' => $account->tenant_id,
            'branch_id' => $this->branchA->id,
            'customer_financial_account_id' => $account->id,
            'ledger_sequence' => 1,
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'ledger_category' => StoreCreditLedgerEntry::CATEGORY_CREDIT,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => 1000,
            'currency_code' => $account->currency_code,
            'source_type' => 'epic39_report_test',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'EPIC39-REPORT-' . Str::random(8),
            'source_snapshot' => ['ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION],
            'idempotency_key' => 'epic39-report-' . Str::uuid(),
            'business_date' => '2026-07-15',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
        ], $overrides));
    }

    private function refundIssuance(CustomerFinancialAccount $account, StoreCreditLedgerEntry $entry): StoreCreditRefundIssuance
    {
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

    private function accountingOutbox(
        StoreCreditLedgerEntry $entry,
        string $eventType,
        string $status
    ): AccountingOutbox {
        return AccountingOutbox::create([
            'tenant_id' => $entry->tenant_id,
            'branch_id' => $entry->branch_id,
            'event_type' => $eventType,
            'source_type' => 'store_credit_ledger_entry',
            'source_id' => $entry->id,
            'payload' => [
                'ledger_entry_id' => $entry->id,
                'amount_centavos' => $entry->amount_centavos,
                'currency_code' => $entry->currency_code,
                'event_version' => 1,
            ],
            'sync_status' => $status,
            'sync_error' => $status === 'failed' ? 'Test sync failure' : null,
            'available_at' => now(),
        ]);
    }
}
