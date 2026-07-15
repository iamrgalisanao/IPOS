<?php

namespace Tests\Feature\Loyalty;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\InventoryService;
use App\Services\Loyalty\LoyaltyBalanceService;
use App\Services\Loyalty\LoyaltyLedgerService;
use App\Services\POS\RefundService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyReversalRefundTest extends TestCase
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

    public function test_repeated_partial_refunds_reverse_earned_points_by_cumulative_delta(): void
    {
        $account = $this->createAccount();
        [$sale, $item] = $this->createPaidSaleWithItem($account, 101);

        $accrual = $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual:' . $sale->id,
            'points' => 101,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
        ]);

        app(RefundService::class)->refund($sale, [
            ['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'restock'],
        ], 'partial_refund_1', 'First third');

        app(RefundService::class)->refund($sale->refresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'restock'],
        ], 'partial_refund_2', 'Second third');

        app(RefundService::class)->refund($sale->refresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'restock'],
        ], 'partial_refund_3', 'Final third');

        $reversals = LoyaltyLedgerEntry::where('entry_type', LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL)
            ->orderBy('ledger_sequence')
            ->get();

        $this->assertSame([34, 33, 34], $reversals->pluck('points')->all());
        $this->assertSame(101, $reversals->sum('points'));
        $this->assertSame($accrual->id, $reversals->last()->source_snapshot['original_loyalty_ledger_entry_id']);
        $this->assertSame(0, app(LoyaltyBalanceService::class)->availablePoints($account));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOYALTY_REFUND_EARN_REVERSAL_POSTED']);
    }

    public function test_mandatory_refund_reversal_rolls_back_when_negative_balance_requires_approval(): void
    {
        $account = $this->createAccount();
        [$sale, $item] = $this->createPaidSaleWithItem($account, 100);

        $this->postLoyalty($account, [
            'idempotency_key' => 'sale-accrual:' . $sale->id,
            'points' => 100,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
        ]);

        $this->postLoyalty($account, [
            'idempotency_key' => 'external-redemption:' . Str::uuid(),
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => 100,
            'source_type' => 'external_redemption',
            'source_id' => (string) Str::uuid(),
            'source_reference' => 'external-redemption',
        ]);

        try {
            app(RefundService::class)->refund($sale, [
                ['sale_item_id' => $item->id, 'quantity' => 3, 'restock_action' => 'restock'],
            ], 'full_refund_requires_approval', 'Full refund');

            $this->fail('The refund should roll back when mandatory loyalty reversal requires approval.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Mandatory loyalty reversal', $exception->getMessage());
        }

        $this->assertSame(0, SaleRefund::where('sale_id', $sale->id)->count());
        $this->assertSame(0, LoyaltyLedgerEntry::where('entry_type', LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL)->count());
        $this->assertSame('paid', $sale->refresh()->status);
    }

    private function createAccount(): CustomerFinancialAccount
    {
        return CustomerFinancialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @return array{0:Sale,1:SaleItem}
     */
    private function createPaidSaleWithItem(CustomerFinancialAccount $account, int $total): array
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

        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'status' => 'active',
        ]);

        $item = SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Refunded Product',
            'quantity' => 3,
            'unit_price' => $total / 3,
            'subtotal' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => $total,
            'is_inventory_tracked' => true,
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

        app(InventoryService::class)->deductFromSale($sale);

        return [$sale->refresh(), $item->refresh()];
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
