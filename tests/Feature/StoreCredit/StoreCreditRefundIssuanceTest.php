<?php

namespace Tests\Feature\StoreCredit;

use App\Exceptions\StoreCredit\StoreCreditRefundAlreadyIssuedException;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRefundIssuance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\POS\RefundService;
use App\Services\StoreCredit\StoreCreditRefundIssuer;
use App\Services\TenantContext;
use App\Values\POS\RefundPayoutCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCreditRefundIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private RefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->cashier);

        $this->refundService = app(RefundService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_store_credit_refund_creates_refund_ledger_issuance_and_accounting_evidence(): void
    {
        $sale = $this->createPaidSale();
        $item = $sale->items()->firstOrFail();
        $account = $this->createAccount();

        $refund = $this->refundService->refund(
            $sale,
            [['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'do_not_restock']],
            'RETURN',
            'Customer prefers store credit',
            null,
            $this->storeCreditCommand($account, 'refund-credit-request-1')
        );

        $issuance = $refund->storeCreditIssuance;
        $ledger = $issuance->ledgerEntry;

        $this->assertSame('partially_refunded', $sale->refresh()->status);
        $this->assertSame(5000, $issuance->amount_centavos);
        $this->assertSame($account->id, $issuance->customer_financial_account_id);
        $this->assertSame(StoreCreditLedgerEntry::TYPE_REFUND_CREDIT, $ledger->entry_type);
        $this->assertSame(StoreCreditLedgerEntry::DIRECTION_CREDIT, $ledger->direction);
        $this->assertSame('sale_refund', $ledger->source_type);
        $this->assertSame($refund->id, $ledger->source_id);
        $this->assertDatabaseHas('payment_reversals', [
            'sale_id' => $sale->id,
            'amount' => 50,
            'reversal_type' => 'refund_reversal',
        ]);
        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'sale_refunded',
            'source_type' => 'sale_refund',
            'source_id' => $refund->id,
        ]);
        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'store_credit_issued',
            'source_type' => 'store_credit_ledger_entry',
            'source_id' => $ledger->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STORE_CREDIT_REFUND_ISSUED']);
        $this->assertSame(0, StoreCreditLedgerEntry::query()->where('direction', StoreCreditLedgerEntry::DIRECTION_DEBIT)->count());

        $outbox = AccountingOutbox::query()
            ->where('event_type', 'store_credit_issued')
            ->firstOrFail();

        $this->assertSame('RETURN', $outbox->payload['refund_reason_code']);
        $this->assertSame(1, $outbox->payload['event_version']);
        $this->assertSame(StoreCreditRefundIssuance::SNAPSHOT_VERSION, $outbox->payload['source_snapshot_version']);
    }

    public function test_closed_account_rejection_rolls_back_refund_and_ledger(): void
    {
        $sale = $this->createPaidSale();
        $item = $sale->items()->firstOrFail();
        $account = $this->createAccount(['status' => CustomerFinancialAccount::STATUS_CLOSED]);

        try {
            $this->refundService->refund(
                $sale,
                [['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'do_not_restock']],
                'RETURN',
                'Closed account',
                null,
                $this->storeCreditCommand($account, 'closed-account-refund')
            );
            $this->fail('Closed account refund-to-credit should fail.');
        } catch (\Throwable) {
            $this->assertDatabaseEmpty('sale_refunds');
            $this->assertDatabaseEmpty('store_credit_ledger_entries');
            $this->assertDatabaseEmpty('store_credit_refund_issuances');
            $this->assertDatabaseEmpty('accounting_outbox');
            $this->assertSame('paid', $sale->refresh()->status);
        }
    }

    public function test_suspended_account_can_receive_refund_credit(): void
    {
        $sale = $this->createPaidSale();
        $item = $sale->items()->firstOrFail();
        $account = $this->createAccount(['status' => CustomerFinancialAccount::STATUS_SUSPENDED]);

        $refund = $this->refundService->refund(
            $sale,
            [['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'do_not_restock']],
            'RETURN',
            'Suspended account recovery',
            null,
            $this->storeCreditCommand($account, 'suspended-account-refund')
        );

        $this->assertNotNull($refund->storeCreditIssuance);
        $this->assertSame(5000, $refund->storeCreditIssuance->amount_centavos);
    }

    public function test_duplicate_issuer_call_for_same_refund_is_rejected(): void
    {
        $sale = $this->createPaidSale();
        $item = $sale->items()->firstOrFail();
        $account = $this->createAccount();

        $refund = $this->refundService->refund(
            $sale,
            [['sale_item_id' => $item->id, 'quantity' => 1, 'restock_action' => 'do_not_restock']],
            'RETURN',
            'Initial issue',
            null,
            $this->storeCreditCommand($account, 'duplicate-refund')
        );

        $this->expectException(StoreCreditRefundAlreadyIssuedException::class);

        app(StoreCreditRefundIssuer::class)->issue(
            $refund,
            $this->storeCreditCommand($account, 'duplicate-refund-second-attempt')
        );
    }

    private function createPaidSale(): Sale
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'status' => 'paid',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_inventory_tracked' => false,
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Store Credit Refund Item',
            'quantity' => 2,
            'unit_price' => 50,
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
            'is_inventory_tracked' => false,
        ]);

        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'payment_type' => 'cash',
            'amount' => 100,
            'status' => 'recorded',
            'paid_at' => now(),
        ]);

        return $sale->refresh();
    }

    private function createAccount(array $attributes = []): CustomerFinancialAccount
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        return CustomerFinancialAccount::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ], $attributes));
    }

    private function storeCreditCommand(CustomerFinancialAccount $account, string $key): RefundPayoutCommand
    {
        return new RefundPayoutCommand(
            payoutMethod: RefundPayoutCommand::METHOD_STORE_CREDIT,
            customerFinancialAccountId: $account->id,
            idempotencyKey: $key,
            requestedBy: $this->cashier,
            approvalReference: 'test-supervisor',
            sourceChannel: 'pos',
        );
    }
}
