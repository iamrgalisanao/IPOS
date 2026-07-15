<?php

namespace Tests\Feature\Loyalty;

use App\Events\SalePaid;
use App\Exceptions\Loyalty\LoyaltyLedgerIdempotencyDriftException;
use App\Exceptions\Loyalty\LoyaltyLedgerInsufficientBalanceException;
use App\Models\Branch;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyRule;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Loyalty\LoyaltyAccrualService;
use App\Services\Loyalty\LoyaltyBalanceService;
use App\Services\Loyalty\LoyaltyCheckoutRedemptionCoordinator;
use App\Services\Loyalty\LoyaltyLedgerService;
use App\Services\Reports\Epic39ReportingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $actor;
    private LoyaltyLedgerService $ledgerService;
    private LoyaltyBalanceService $balanceService;

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
        $this->actor->assignToBranch($this->branch);

        $this->ledgerService = app(LoyaltyLedgerService::class);
        $this->balanceService = app(LoyaltyBalanceService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_loyalty_runtime_schema_is_points_only_and_has_no_balance_column(): void
    {
        $this->assertTrue(Schema::hasTable('loyalty_ledger_entries'));
        $this->assertTrue(Schema::hasTable('loyalty_redemptions'));
        $this->assertTrue(Schema::hasColumn('loyalty_ledger_entries', 'points'));
        $this->assertFalse(Schema::hasColumn('loyalty_ledger_entries', 'amount_centavos'));
        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'points_balance'));
    }

    public function test_loyalty_ledger_replays_same_idempotency_key_and_rejects_drift(): void
    {
        $account = $this->createAccount();

        $entry = $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual-1',
            'points' => 25,
            'source_id' => (string) Str::uuid(),
        ]);

        $replay = $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual-1',
            'points' => 25,
            'source_id' => $entry->source_id,
        ]);

        $this->assertTrue($entry->is($replay));
        $this->assertSame(1, LoyaltyLedgerEntry::count());

        $this->expectException(LoyaltyLedgerIdempotencyDriftException::class);

        $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual-1',
            'points' => 26,
            'source_id' => $entry->source_id,
        ]);
    }

    public function test_loyalty_debit_cannot_create_negative_balance(): void
    {
        $this->expectException(LoyaltyLedgerInsufficientBalanceException::class);

        $this->postLoyalty($this->createAccount(), [
            'idempotency_key' => 'too-many-points',
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 1,
        ]);
    }

    public function test_sale_paid_accrual_posts_once_for_customer_account(): void
    {
        $account = $this->createAccount();
        $rule = $this->createEarningRule();
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->actor->id,
            'customer_financial_account_id' => $account->id,
            'status' => 'paid',
            'total' => '125.0000',
            'is_training_mode' => false,
        ]);

        $event = SalePaid::fromSale($sale);
        $first = app(LoyaltyAccrualService::class)->accrueFromSalePaid($event);
        $second = app(LoyaltyAccrualService::class)->accrueFromSalePaid($event);

        $this->assertNotNull($first);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, LoyaltyLedgerEntry::where('entry_type', LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL)->count());
        $this->assertSame(125, $this->balanceService->availablePoints($account));
        $this->assertSame($rule->id, $first->source_snapshot['rule_snapshot']['rule_id']);
    }

    public function test_redemption_creates_pending_evidence_then_debits_points_at_finalization(): void
    {
        $account = $this->createAccount();
        $this->createRedemptionRule();

        $this->postLoyalty($account, [
            'idempotency_key' => 'seed-points',
            'points' => 500,
            'source_id' => (string) Str::uuid(),
        ]);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->actor->id,
            'customer_financial_account_id' => $account->id,
            'status' => 'created',
            'subtotal' => '10.0000',
            'discount_total' => '2.0000',
            'total' => '8.0000',
        ]);

        $coordinator = app(LoyaltyCheckoutRedemptionCoordinator::class);
        $prepared = $coordinator->prepareForSaleCreation(
            $this->tenant->id,
            $this->branch->id,
            [
                'customer_financial_account_id' => $account->id,
                'points_to_redeem' => 200,
                'client_request_uuid' => (string) Str::uuid(),
            ],
            800,
            false,
            false
        );

        $pending = $coordinator->persistPending($sale, $prepared, $this->actor);

        $this->assertSame(LoyaltyRedemption::STATUS_PENDING, $pending->status);
        $this->assertNull($pending->loyalty_ledger_entry_id);
        $this->assertSame(0, $sale->payments()->count());

        $finalized = $coordinator->finalizeForSale($sale, $this->actor);

        $this->assertSame(LoyaltyRedemption::STATUS_REDEEMED, $finalized->status);
        $this->assertNotNull($finalized->loyalty_ledger_entry_id);
        $this->assertSame(300, $this->balanceService->availablePoints($account));
    }

    public function test_redemption_rejects_unknown_rule_code_without_default_fallback(): void
    {
        $account = $this->createAccount();
        $this->createRedemptionRule();

        $this->postLoyalty($account, [
            'idempotency_key' => 'seed-unknown-rule-points',
            'points' => 500,
            'source_id' => (string) Str::uuid(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The requested loyalty rule is not active');

        app(LoyaltyCheckoutRedemptionCoordinator::class)->prepareForSaleCreation(
            $this->tenant->id,
            $this->branch->id,
            [
                'customer_financial_account_id' => $account->id,
                'points_to_redeem' => 100,
                'redemption_rule_code' => 'NOT-A-REAL-RULE',
                'client_request_uuid' => (string) Str::uuid(),
            ],
            500,
            false,
            false
        );
    }

    public function test_loyalty_activity_report_uses_real_ledger_rows(): void
    {
        $account = $this->createAccount();

        $this->postLoyalty($account, [
            'idempotency_key' => 'report-earned',
            'points' => 120,
            'source_id' => (string) Str::uuid(),
        ]);
        $this->postLoyalty($account, [
            'idempotency_key' => 'report-redeemed',
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 20,
            'source_id' => (string) Str::uuid(),
        ]);

        $report = app(Epic39ReportingService::class)->loyaltyActivity($this->actor, []);

        $this->assertSame(120, $report['totals']['points_earned']);
        $this->assertSame(20, $report['totals']['points_redeemed']);
        $this->assertSame(100, $report['totals']['points_balance']);
        $this->assertSame(2, $report['rows']['meta']['total']);
    }

    private function createAccount(): CustomerFinancialAccount
    {
        return CustomerFinancialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function createEarningRule(): LoyaltyRule
    {
        return LoyaltyRule::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'DEFAULT_EARNING_V1',
            'rule_type' => LoyaltyRule::TYPE_EARNING,
            'rule_version' => 1,
            'status' => LoyaltyRule::STATUS_ACTIVE,
            'configuration' => ['points_per_currency_unit' => 1, 'currency_unit_centavos' => 100],
        ]);
    }

    private function createRedemptionRule(): LoyaltyRule
    {
        return LoyaltyRule::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'POINTS_FOR_DISCOUNT_V1',
            'rule_type' => LoyaltyRule::TYPE_REDEMPTION,
            'rule_version' => 1,
            'status' => LoyaltyRule::STATUS_ACTIVE,
            'configuration' => ['points_per_centavo' => 1],
        ]);
    }

    private function postLoyalty(CustomerFinancialAccount $account, array $overrides): LoyaltyLedgerEntry
    {
        return $this->ledgerService->post($account, array_merge([
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'loyalty-entry-' . Str::uuid(),
            'entry_type' => LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => 100,
            'source_type' => 'test',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'TEST',
            'source_snapshot' => ['source' => 'test'],
            'business_date' => now()->toDateString(),
        ], $overrides), $this->actor);
    }
}
