<?php

namespace Tests\Feature\Accounting;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\AccountingOutbox;
use App\Models\Tenant;
use App\Models\User;
use App\Models\ProductCategory;
use App\Services\Accounting\AccountingMappingService;
use App\Services\BranchContext;
use App\Services\Accounting\NormalizedPayloadService;
use App\Services\TenantContext;
use App\Services\POS\PaymentRecordingService;
use App\Services\POS\VoidService;
use App\Services\POS\RefundService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingOutboxTest extends TestCase
{
    use RefreshDatabase, \Tests\Traits\InteractsWithShifts;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->user->assignToBranch($this->branch);
        
        (new \App\Services\RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->user->assignRole(\App\Models\Role::where('name', 'Cashier')->first());

        $this->actingAs($this->user);
        
        $this->openShiftFor($this->user, $this->branch);
    }

    protected function createPaidSale(float $total = 200): Sale
    {
        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id
        ]);
        
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'status' => 'active'
        ]);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => $total,
            'status' => 'created'
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'line_total' => $total
        ]);

        $method = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id]);
        app(PaymentRecordingService::class)->record($sale->id, [
            'payment_method_id' => $method->id,
            'amount' => $total
        ], $this->user);

        $this->seedMappings($product->id, $method->id);

        return $sale->refresh();
    }

    protected function seedMappings(string $productId, string $paymentMethodId): void
    {
        $service = app(AccountingMappingService::class);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => $productId,
            'external_id' => 'QB-ITEM-PROD',
        ]);

        $service->createOrUpdate([
            'branch_id' => $this->branch->id,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => $paymentMethodId,
            'external_id' => 'QB-PM-CASH',
        ]);
    }

    /** AC: Paid sale creates sale_paid outbox record with correct payload */
    public function test_paid_sale_creates_outbox_record(): void
    {
        $sale = $this->createPaidSale(112.50);

        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'sale_paid',
            'source_id' => $sale->id,
            'sync_status' => 'pending'
        ]);

        $outbox = AccountingOutbox::where('event_type', 'sale_paid')->first();
        $this->assertEquals('112.5000', $outbox->payload['total']);
        $this->assertIsString($outbox->payload['total']); // AC: Decimal strings
        $this->assertNotEmpty($outbox->payload['items']);
        $this->assertNotEmpty($outbox->payload['payments']);
        $this->assertEquals('sales_receipt', app(NormalizedPayloadService::class)->normalize($outbox)['header']['document_type']);

        // AC: Excludes secrets
        $this->assertArrayNotHasKey('api_key', $outbox->payload);
        $this->assertArrayNotHasKey('token', $outbox->payload);
    }

    /** AC: Void creates sale_voided outbox record */
    public function test_void_creates_outbox_record(): void
    {
        $sale = $this->createPaidSale(200);
        $void = app(VoidService::class)->void($sale, 'mistake');

        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'sale_voided',
            'source_id' => $void->id
        ]);

        $outbox = AccountingOutbox::where('event_type', 'sale_voided')->first();
        $this->assertEquals('200.0000', $outbox->payload['original_sale_total']);
        $this->assertNotEmpty($outbox->payload['reversals']);
        $this->assertNotEmpty($outbox->payload['payment_reversals']);
        $this->assertEquals('void_reversal', app(NormalizedPayloadService::class)->normalize($outbox)['header']['document_type']);
    }

    /** AC: Refund creates sale_refunded outbox record */
    public function test_refund_creates_outbox_record(): void
    {
        $sale = $this->createPaidSale(200);
        $item = $sale->items->first();

        $refund = app(RefundService::class)->refund($sale, [
            ['sale_item_id' => $item->id, 'quantity' => 0.5, 'restock_action' => 'restock']
        ], 'return');

        $this->assertDatabaseHas('accounting_outbox', [
            'event_type' => 'sale_refunded',
            'source_id' => $refund->id
        ]);

        $outbox = AccountingOutbox::where('event_type', 'sale_refunded')->first();
        $this->assertEquals('100.0000', number_format((float)$outbox->payload['refund_total'], 4, '.', ''));
        $this->assertCount(1, $outbox->payload['items']);
        $this->assertCount(1, $outbox->payload['refund_items']);
        $this->assertEquals('refund_credit', app(NormalizedPayloadService::class)->normalize($outbox)['header']['document_type']);
    }

    /** AC: Idempotency */
    public function test_outbox_is_idempotent(): void
    {
        $sale = $this->createPaidSale();
        
        // Try recording again (manual call to service)
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        app(\App\Services\Accounting\AccountingOutboxService::class)->recordEvent('sale_paid', $sale, []);
    }

    /** AC: Isolation */
    public function test_tenant_isolation(): void
    {
        $this->createPaidSale();
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);

        $this->assertCount(0, AccountingOutbox::all());
    }

    /** AC: Immutability */
    public function test_identity_fields_are_immutable(): void
    {
        $this->createPaidSale();
        $outbox = AccountingOutbox::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $outbox->update(['event_type' => 'other']);
    }

    /** AC: Failure Rollbacks (Payment, Void, Refund) */
    public function test_failure_rollbacks_no_outbox(): void
    {
        // 1. Payment
        $sale = Sale::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
        try { 
            DB::transaction(function() use ($sale) {
                app(PaymentRecordingService::class)->record($sale->id, ['amount' => 0], $this->user);
            });
        } catch (\Exception $e) {}
        $this->assertDatabaseEmpty('accounting_outbox');

        // 2. Void
        $salePaid = $this->createPaidSale();
        try { 
            DB::transaction(function() use ($salePaid) {
                app(VoidService::class)->void($salePaid, 'err');
                throw new \Exception('Forced fail');
            });
        } catch (\Exception $e) {}
        $this->assertCount(1, AccountingOutbox::where('event_type', 'sale_paid')->get()); // Only paid exists
        $this->assertCount(0, AccountingOutbox::where('event_type', 'sale_voided')->get());

        // 3. Refund
        $salePaid2 = $this->createPaidSale();
        $item = $salePaid2->items->first();
        try { 
            DB::transaction(function() use ($salePaid2, $item) {
                app(RefundService::class)->refund($salePaid2, [['sale_item_id' => $item->id, 'quantity' => 1]], 'err');
                throw new \Exception('Forced fail');
            });
        } catch (\Exception $e) {}
        $this->assertCount(0, AccountingOutbox::where('event_type', 'sale_refunded')->get());
    }

    /** AC: POS UI Accounting-Silent Boundary */
    public function test_pos_ui_is_accounting_silent(): void
    {
        $sale = $this->createPaidSale();
        
        // Simulate a typical JSON response for a sale
        $data = $sale->toArray();
        
        $this->assertArrayNotHasKey('accounting_outbox', $data);
        $this->assertArrayNotHasKey('sync_status', $data);
        $this->assertArrayNotHasKey('sync_error', $data);
        $this->assertArrayNotHasKey('qb_id', $data);
    }
}
