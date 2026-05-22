<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\CheckoutRequest;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Models\PaymentMethod;
use App\Models\AuditLog;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\POS\PaymentRecordingService;
use App\Services\POS\ZReadGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTrainingModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private Product $product;
    private ProductCategory $category;
    private SalesMachineProfile $machineProfile;
    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        $this->category = ProductCategory::create(['name' => 'General', 'code' => 'GEN']);
        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Generic Product',
            'sku'                  => 'GEN-001',
            'barcode'              => '1111111111',
            'unit_of_measure'      => 'pc',
            'selling_price'        => 50.00,
            'cost_price'           => 20.00,
            'status'               => 'active',
            'is_inventory_tracked' => true,
        ]);

        $this->machineProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM01',
            'machine_identification_number' => 'MIN-123456789',
            'machine_serial_number' => 'SN-ABC123XYZ',
            'software_license_number' => 'LIC-EOPT-2026',
            'permit_to_use_number' => 'PTU-2026-999',
            'permit_issued_at' => now(),
            'status' => 'active',
            'last_invoice_sequence' => 0,
            'grand_cumulative_total' => 0.00,
            'reset_counter' => 0,
            'z_read_counter' => 0,
        ]);

        \App\Models\BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 10.00,
            'status' => 'active',
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'code' => 'CASH',
            'active' => true,
            'reference_required' => false,
            'strict_reference_mode' => false,
        ]);

        // Grant open_shift permission to the cashier role
        $role = Role::where('name', 'Cashier')->first();
        $openShiftPermission = \App\Models\Permission::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'open_shift']);
        $role->permissions()->syncWithoutDetaching([$openShiftPermission->id]);

        // Create shift for Cashier
        $shiftService = app(\App\Services\Shift\ShiftService::class);
        $shiftService->openShift($this->cashier, $this->branch, '100.0000', $this->cashier);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    private function postValidate(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload);
    }

    private function postCreateSale(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);
    }

    /**
     * Test full isolated training mode checkout flow.
     */
    public function test_isolated_training_mode_checkout_lifecycle(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'is_training_mode' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ];

        // 1. Validate Draft in training mode
        $response = $this->postValidate($payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'validated']);

        $checkoutReq = CheckoutRequest::where('client_request_uuid', $uuid)->firstOrFail();
        $this->assertTrue($checkoutReq->is_training_mode);

        // 2. Create Sale in training mode
        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'created']);

        $sale = Sale::where('client_request_uuid', $uuid)->firstOrFail();
        $this->assertTrue($sale->is_training_mode);

        // Assert invoice sequence on SalesMachineProfile was NOT consumed
        $this->machineProfile->refresh();
        $this->assertEquals(0, $this->machineProfile->last_invoice_sequence);

        // Assert principal_invoice_number prefix is isolated
        $this->assertStringStartsWith('TRAIN-INV-TERM01-', $sale->principal_invoice_number);

        // Assert training_sale_created audit trail was recorded
        $this->assertTrue(
            AuditLog::where('action', 'training_sale_created')
                ->where('auditable_id', $sale->id)
                ->exists()
        );

        // 3. Record Payment for training sale
        $paymentData = [
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.0000',
        ];

        $paymentRecordingService = app(PaymentRecordingService::class);

        // Track initial inventory count
        // Note: is_inventory_tracked is true, so a production flow would deduct stock.
        // We want to assert that no permanent inventory deduction or outbox state transition is made.
        $initialInventoryCount = \DB::table('inventory_movements')->count();
        $initialOutboxCount = \DB::table('accounting_outbox')->count();

        $payments = $paymentRecordingService->record($sale->id, $paymentData, $this->cashier);

        $this->assertNotNull($payments);
        $sale->refresh();
        $this->assertEquals('paid', $sale->status);
        $this->assertTrue($sale->is_training_mode);

        // Assert NO inventory deductions were made
        $this->assertEquals($initialInventoryCount, \DB::table('inventory_movements')->count());

        // Assert NO accounting outbox record was written
        $this->assertEquals($initialOutboxCount, \DB::table('accounting_outbox')->count());

        // Assert training_payment_recorded audit trail was recorded
        $this->assertTrue(
            AuditLog::where('action', 'training_payment_recorded')
                ->where('auditable_id', $sale->id)
                ->exists()
        );

        // 4. Receipt Formatting & Watermarking
        $receiptResponse = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('pos.sales.receipt', ['sale_id' => $sale->id]));

        $receiptResponse->assertStatus(200);
        $receiptResponse->assertJson([
            'is_training_mode' => true,
            'training_watermark' => '*** TRAINING MODE - NOT A VALID INVOICE ***'
        ]);

        // Assert training_receipt_printed audit trail was recorded
        $this->assertTrue(
            AuditLog::where('action', 'training_receipt_printed')
                ->where('auditable_id', $sale->id)
                ->exists()
        );

        // 5. Reprint of training receipt
        $reprintResponse = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('pos.sales.receipt', ['sale_id' => $sale->id]) . '?reprint_reason=Testing%20reprint');

        $reprintResponse->assertStatus(200);

        // Assert training_receipt_reprint audit trail was recorded
        $this->assertTrue(
            AuditLog::where('action', 'training_receipt_reprint')
                ->where('auditable_id', $sale->id)
                ->exists()
        );

        // 6. Z-report generation exclusion
        // Let's create a production sale alongside the training sale to show the production Z-report excludes training.
        $prodUuid = (string) Str::uuid();
        $prodPayload = [
            'client_request_uuid' => $prodUuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        
        $this->postValidate($prodPayload);
        $prodSaleResponse = $this->postCreateSale($prodPayload);
        $prodSale = Sale::where('client_request_uuid', $prodUuid)->firstOrFail();

        $paymentRecordingService->record($prodSale->id, [
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '50.0000',
        ], $this->cashier);

        // Assert production sale has INV- prefix and sequence 1
        $prodSale->refresh();
        $this->assertEquals('INV-TERM01-0000000001', $prodSale->principal_invoice_number);

        // Now run Z-read generation
        $zReadService = app(ZReadGenerationService::class);
        $activeShift = app(\App\Services\Shift\ShiftService::class)->requireActiveShift($this->cashier, $this->branch);
        
        $zRead = $zReadService->generateZRead($this->machineProfile, $this->cashier, now()->toDateString(), $activeShift->id);

        // Z-read should only include the 1 production sale, NOT the training sale!
        $this->assertEquals(1, $zRead->transaction_count);
        $this->assertEquals(50.0000, (float)$zRead->gross_sales_amount);

        // GCT should be updated only with production sale total (50), NOT including training sale (100)
        $this->machineProfile->refresh();
        $this->assertEquals(50.0000, (float)$this->machineProfile->grand_cumulative_total);
    }

    /**
     * Test 1: Training mode cannot be included in official Z-read even when mixed with multiple production sales.
     */
    public function test_training_mode_strictly_excluded_from_z_read_with_multiple_production_sales(): void
    {
        $paymentRecordingService = app(PaymentRecordingService::class);
        $zReadService = app(ZReadGenerationService::class);
        $activeShift = app(\App\Services\Shift\ShiftService::class)->requireActiveShift($this->cashier, $this->branch);

        // Create 2 production sales
        $sales = [];
        for ($i = 1; $i <= 2; $i++) {
            $uuid = (string) Str::uuid();
            $payload = [
                'client_request_uuid' => $uuid,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ];
            $this->postValidate($payload);
            $this->postCreateSale($payload);
            $sale = Sale::where('client_request_uuid', $uuid)->firstOrFail();
            $paymentRecordingService->record($sale->id, [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '50.0000',
            ], $this->cashier);
            $sales[] = $sale;
        }

        // Create 1 training sale
        $trainUuid = (string) Str::uuid();
        $trainPayload = [
            'client_request_uuid' => $trainUuid,
            'is_training_mode' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        $this->postValidate($trainPayload);
        $this->postCreateSale($trainPayload);
        $trainSale = Sale::where('client_request_uuid', $trainUuid)->firstOrFail();
        $paymentRecordingService->record($trainSale->id, [
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '50.0000',
        ], $this->cashier);

        // Run Z-read generation
        $zRead = $zReadService->generateZRead($this->machineProfile, $this->cashier, now()->toDateString(), $activeShift->id);

        // Should include exactly the 2 production sales (not 3!)
        $this->assertEquals(2, $zRead->transaction_count);
        $this->assertEquals(100.0000, (float)$zRead->gross_sales_amount);

        // GCT should increase by 100, not 150
        $this->machineProfile->refresh();
        $this->assertEquals(100.0000, (float)$this->machineProfile->grand_cumulative_total);
    }

    /**
     * Test 2: Production checkout, inventory deduction, invoice sequence, and accounting outbox behavior remain unchanged.
     */
    public function test_production_checkout_remains_completely_unchanged(): void
    {
        $paymentRecordingService = app(PaymentRecordingService::class);

        // Track initial counts
        $initialInventoryCount = \DB::table('inventory_movements')->count();
        $initialOutboxCount = \DB::table('accounting_outbox')->count();
        $initialInvoiceSeq = $this->machineProfile->last_invoice_sequence;

        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]], // Perishable product, expiry_tracking_enabled=true
        ];

        // 1. Standard Production Validate Draft
        $response = $this->postValidate($payload);
        $response->assertStatus(200);
        $checkoutReq = CheckoutRequest::where('client_request_uuid', $uuid)->firstOrFail();
        $this->assertFalse((bool)$checkoutReq->is_training_mode);

        // 2. Standard Production Create Sale
        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);
        $sale = Sale::where('client_request_uuid', $uuid)->firstOrFail();
        $this->assertFalse((bool)$sale->is_training_mode);

        // Assert invoice sequence is incremented
        $this->machineProfile->refresh();
        $this->assertEquals($initialInvoiceSeq + 1, $this->machineProfile->last_invoice_sequence);
        $this->assertEquals('INV-TERM01-0000000001', $sale->principal_invoice_number);

        // 3. Record Payment
        $paymentRecordingService->record($sale->id, [
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.0000',
        ], $this->cashier);

        // Assert inventory deduction movement WAS made (FEFO allocation triggered for perishable)
        $this->assertGreaterThan($initialInventoryCount, \DB::table('inventory_movements')->count());

        // Assert accounting outbox record WAS written for sync
        $this->assertGreaterThan($initialOutboxCount, \DB::table('accounting_outbox')->count());
    }
}

