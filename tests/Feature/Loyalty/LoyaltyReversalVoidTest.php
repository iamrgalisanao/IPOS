<?php

namespace Tests\Feature\Loyalty;

use App\Models\Branch;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRedemption;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Loyalty\LoyaltyBalanceService;
use App\Services\Loyalty\LoyaltyLedgerService;
use App\Services\POS\VoidService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyReversalVoidTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $actor;
    private LoyaltyLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($this->branch);

        $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->ledgerService = app(LoyaltyLedgerService::class);
    }

    public function test_full_void_reverses_earned_points_and_restores_redeemed_points(): void
    {
        $account = $this->createAccount();
        $sale = $this->createPaidSale($account, 100);

        $accrual = $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual:' . $sale->id,
            'entry_type' => LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => 100,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
        ]);

        $redemptionEntry = $this->postLoyalty($account, [
            'idempotency_key' => 'loyalty-redemption:' . $sale->id,
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 20,
            'source_type' => 'loyalty_redemption',
            'source_id' => (string) Str::uuid(),
            'source_reference' => $sale->sale_number,
        ]);

        $redemption = LoyaltyRedemption::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'customer_financial_account_id' => $account->id,
            'loyalty_rule_id' => $this->loyaltyRuleId(),
            'loyalty_ledger_entry_id' => $redemptionEntry->id,
            'points' => 20,
            'benefit_centavos' => 2000,
            'authorized_balance_points' => 100,
            'status' => LoyaltyRedemption::STATUS_REDEEMED,
            'idempotency_key' => 'loyalty-redemption:' . $sale->id,
            'rule_snapshot' => ['rule_version' => 1],
            'source_snapshot' => ['sale_id' => $sale->id],
            'authorized_at' => now(),
            'redeemed_at' => now(),
            'redeemed_by' => $this->actor->id,
        ]);

        $void = app(VoidService::class)->void($sale, 'customer_cancelled', 'Customer cancelled after payment');

        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'customer_financial_account_id' => $account->id,
            'entry_type' => LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 100,
        ]);
        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'customer_financial_account_id' => $account->id,
            'entry_type' => LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => 20,
        ]);

        $earnReversal = LoyaltyLedgerEntry::where('entry_type', LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL)->firstOrFail();
        $restore = LoyaltyLedgerEntry::where('entry_type', LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE)->firstOrFail();

        $this->assertSame($void->id, $earnReversal->source_snapshot['sale_void_id']);
        $this->assertSame($accrual->id, $earnReversal->source_snapshot['original_loyalty_ledger_entry_id']);
        $this->assertSame($redemption->id, $restore->source_snapshot['loyalty_redemption_id']);
        $this->assertSame(0, app(LoyaltyBalanceService::class)->availablePoints($account));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOYALTY_VOID_EARN_REVERSAL_POSTED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOYALTY_VOID_REDEMPTION_RESTORE_POSTED']);
    }

    private function createAccount(): CustomerFinancialAccount
    {
        return CustomerFinancialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function createPaidSale(CustomerFinancialAccount $account, int $total): Sale
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->actor->id,
            'customer_financial_account_id' => $account->id,
            'status' => 'paid',
            'total' => number_format($total, 4, '.', ''),
            'is_training_mode' => false,
        ]);

        $method = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'payment_type' => $method->type,
            'amount' => $total,
            'status' => 'recorded',
        ]);

        return $sale->refresh();
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

    private function loyaltyRuleId(): string
    {
        return \App\Models\LoyaltyRule::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TEST_REDEMPTION',
            'rule_type' => \App\Models\LoyaltyRule::TYPE_REDEMPTION,
            'rule_version' => 1,
            'status' => \App\Models\LoyaltyRule::STATUS_ACTIVE,
            'configuration' => ['points_per_centavo' => 1],
        ])->id;
    }
}
