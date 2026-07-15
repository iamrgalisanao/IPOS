<?php

namespace Tests\Feature\Loyalty;

use App\Models\Branch;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Loyalty\LoyaltyLedgerService;
use App\Services\Reports\Epic39ReportingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyReversalReportingTest extends TestCase
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
        $this->actor->assignToBranch($this->branch);
        $this->ledgerService = app(LoyaltyLedgerService::class);
    }

    public function test_loyalty_activity_reports_reversed_and_restored_totals(): void
    {
        $account = CustomerFinancialAccount::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postLedgerEntry($account, [
            'entry_type' => LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => 150,
        ]);
        $this->postLedgerEntry($account, [
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 30,
        ]);
        $this->postLedgerEntry($account, [
            'entry_type' => LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 40,
        ]);
        $this->postLedgerEntry($account, [
            'entry_type' => LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => 10,
        ]);

        $report = app(Epic39ReportingService::class)->loyaltyActivity($this->actor, []);

        $this->assertSame(150, $report['totals']['points_earned']);
        $this->assertSame(30, $report['totals']['points_redeemed']);
        $this->assertSame(40, $report['totals']['points_reversed']);
        $this->assertSame(10, $report['totals']['points_restored']);
        $this->assertSame(90, $report['totals']['points_balance']);
    }

    private function postLedgerEntry(CustomerFinancialAccount $account, array $overrides): LoyaltyLedgerEntry
    {
        return $this->ledgerService->post($account, array_merge([
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'loyalty-report-' . Str::uuid(),
            'source_type' => 'report_test',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'REPORT',
            'source_snapshot' => ['source' => 'report_test'],
            'business_date' => now()->toDateString(),
        ], $overrides), $this->actor);
    }
}
