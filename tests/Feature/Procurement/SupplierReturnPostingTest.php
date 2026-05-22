<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\Inventory\FefoAllocationService;
use App\Services\Procurement\SupplierReturnPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierReturnPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_happy_path_posting_standard_product_decrements_stock_and_recalculates_wac(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        // Create standard product
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        // Set initial inventory: 10 units at WAC 12.0000
        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '10.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        // Create approved supplier return line returning 4 units at unit cost of 10.0000
        // Expected inverse WAC:
        // Remaining Stock = 10 - 4 = 6
        // Current Value = 10 * 12.00 = 120.00
        // Returned Value = 4 * 10.00 = 40.00
        // Remaining Value = 120.00 - 40.00 = 80.00
        // New WAC = 80.00 / 6 = 13.3333
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-MNL-20260518-0001',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
            'total_amount' => '40.0000',
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
            'line_total' => '40.0000',
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        // Reload data from DB
        $this->setTenantContext($tenant);
        $sr->refresh();
        $inventory->refresh();

        // 1. Status is posted
        $this->assertEquals(SupplierReturn::STATUS_POSTED, $sr->status);
        $this->assertEquals($manager->id, $sr->posted_by);
        $this->assertNotNull($sr->posted_at);

        // 2. Branch Inventory updated
        $this->assertEquals('6.0000', $inventory->current_stock);
        $this->assertEquals('13.3333', $inventory->average_cost);

        // 3. Inventory movement is recorded
        $movement = InventoryMovement::where('source_type', SupplierReturn::class)
            ->where('source_id', $sr->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals('-4.0000', $movement->quantity_change);
        $this->assertEquals('10.0000', $movement->quantity_before);
        $this->assertEquals('6.0000', $movement->quantity_after);
        $this->assertEquals('supplier_return', $movement->movement_type);

        // 4. Audit Log is written
        $auditLog = AuditLog::where('action', 'supplier_return_posted')
            ->where('auditable_id', $sr->id)
            ->first();
        $this->assertNotNull($auditLog);
    }

    /** @test */
    public function test_posting_fails_due_to_stock_underflow(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        // Set stock: only 2 units
        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '2.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        // Attempting to return 5 units
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-UNDERFLOW',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
            'line_total' => '50.0000',
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Posting should fail with validation exception
        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertSessionHasErrors(['lines']);

        // Verify status remains approved, stock remains untouched
        $this->setTenantContext($tenant);
        $this->assertEquals(SupplierReturn::STATUS_APPROVED, $sr->refresh()->status);
    }

    /** @test */
    public function test_perishable_product_with_explicit_lot(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '15.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        // Explicit Lot with 10 units remaining
        $lot = ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_code' => 'LOT-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(20)->toDateString(),
            'status' => 'active',
        ]);

        // Return line specifies explicit lot and requests 4 units
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-EXPLICIT-LOT',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
            'line_total' => '40.0000',
            'expiry_lot_id' => $lot->id,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertStatus(302);

        $this->setTenantContext($tenant);
        $lot->refresh();

        // Verify lot is decremented to 6
        $this->assertEquals('6.0000', $lot->quantity_remaining);
        $this->assertEquals('active', $lot->status);
    }

    /** @test */
    public function test_perishable_product_with_fefo_fallback(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '20.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        // Lot A: expires in 5 days, has 10 units
        $lotA = ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_code' => 'LOT-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);

        // Lot B: expires in 15 days, has 10 units
        $lotB = ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_code' => 'LOT-B',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(15)->toDateString(),
            'status' => 'active',
        ]);

        // Return line has NO explicit lot_id and requests 12 units
        // Expected FEFO allocation:
        // Lot A is fully depleted (10 units deducted, status = depleted)
        // Lot B is partially depleted (2 units deducted, 8 remaining)
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-FEFO-FALLBACK',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '12.0000',
            'unit_cost' => '10.0000',
            'line_total' => '120.0000',
            'expiry_lot_id' => null, // triggers fallback
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertStatus(302);

        $this->setTenantContext($tenant);
        $lotA->refresh();
        $lotB->refresh();

        // Lot A depleted
        $this->assertEquals('0.0000', $lotA->quantity_remaining);
        $this->assertEquals('depleted', $lotA->status);

        // Lot B has 8 units remaining
        $this->assertEquals('8.0000', $lotB->quantity_remaining);
        $this->assertEquals('active', $lotB->status);
    }

    /** @test */
    public function test_rbac_cashier_is_forbidden_from_posting(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-CASHIER-BLOCK',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $cashier->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier);

        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertStatus(403);
    }

    /** @test */
    public function test_posted_supplier_return_creates_pending_accounting_outbox_row(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '10.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-MNL-20260518-0001',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
            'total_amount' => '40.0000',
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
            'line_total' => '40.0000',
        ]);

        app(TenantContext::class)->clear();
        $this->actingAs($manager);

        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertStatus(302);

        $this->setTenantContext($tenant);

        // Assert outbox row exists
        $outbox = \App\Models\AccountingOutbox::where('source_type', 'supplier_return')
            ->where('source_id', $sr->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('supplier_return_posted', $outbox->event_type);
        $this->assertEquals('pending', $outbox->sync_status);

        // Assert payload details
        $payload = $outbox->payload;
        $this->assertEquals($sr->id, $payload['supplier_return_id']);
        $this->assertEquals($sr->document_number, $payload['document_number']);
        $this->assertEquals($supplier->id, $payload['supplier_id']);
        $this->assertEquals('40.0000', $payload['total_amount']);
        $this->assertCount(1, $payload['lines']);
        $this->assertEquals($product->id, $payload['lines'][0]['product_id']);
        $this->assertEquals($product->name, $payload['lines'][0]['product_name']);
        $this->assertEquals('4.0000', $payload['lines'][0]['quantity']);
        $this->assertEquals('10.0000', $payload['lines'][0]['unit_cost']);
        $this->assertEquals('40.0000', $payload['lines'][0]['line_total']);
    }

    /** @test */
    public function test_outbox_payload_normalized_and_builds_qbo_vendor_credit(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-BUILD-TEST',
            'status' => SupplierReturn::STATUS_POSTED,
            'return_date' => now()->toDateString(),
            'total_amount' => '40.0000',
            'created_by' => $user->id,
        ]);

        // Standard event payload representation
        $rawPayload = [
            'supplier_return_id' => $sr->id,
            'document_number' => $sr->document_number,
            'supplier_id' => $supplier->id,
            'total_amount' => '40.0000',
            'posted_at' => now()->toIso8601String(),
            'notes' => 'Some return notes',
            'lines' => [
                [
                    'supplier_return_line_id' => 'some-uuid-line-1',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => '4.0000',
                    'unit_cost' => '10.0000',
                    'line_total' => '40.0000',
                ]
            ]
        ];

        $outbox = \App\Models\AccountingOutbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'supplier_return_posted',
            'source_type' => 'supplier_return',
            'source_id' => $sr->id,
            'payload' => $rawPayload,
            'sync_status' => 'pending',
            'available_at' => now(),
        ]);

        // Build with Static Mapper
        $staticMapper = new \App\Services\Accounting\StaticAccountingMapper();
        $normalizer = new \App\Services\Accounting\NormalizedPayloadService($staticMapper);
        $tenantContext = app(TenantContext::class);
        $branchContext = app(\App\Services\BranchContext::class);
        
        $payloadBuilder = new \App\Services\Accounting\QuickBooksPayloadBuilderService(
            $tenantContext,
            $branchContext,
            $normalizer
        );

        $tenantContext->setTenant($tenant);
        $qboPayload = $payloadBuilder->build($outbox);

        $this->assertEquals('quickbooks', $qboPayload['provider']);
        $this->assertEquals('VendorCredit', $qboPayload['entity']);
        $this->assertEquals('create', $qboPayload['operation']);
        $this->assertEquals($tenant->id, $qboPayload['tenant_id']);
        $this->assertEquals($branch->id, $qboPayload['branch_id']);
        
        $payload = $qboPayload['payload'];
        $this->assertEquals('RMA-BUILD-TEST', $payload['DocNumber']);
        $this->assertEquals(['value' => 'PHP'], $payload['CurrencyRef']);
        $this->assertEquals(40.0, $payload['TotalAmt']);
        $this->assertEquals(['value' => 'SUPPLIER_' . substr($supplier->id, 0, 8)], $payload['VendorRef']);
        $this->assertCount(1, $payload['Line']);
        
        $line = $payload['Line'][0];
        $this->assertEquals('ItemBasedExpenseLineDetail', $line['DetailType']);
        $this->assertEquals(40.0, $line['Amount']);
        $this->assertEquals(['value' => 'ITEM_' . substr($product->id, 0, 8)], $line['ItemBasedExpenseLineDetail']['ItemRef']);
        $this->assertEquals(4.0, $line['ItemBasedExpenseLineDetail']['Qty']);
        $this->assertEquals(10.0, $line['ItemBasedExpenseLineDetail']['UnitPrice']);
    }

    /** @test */
    public function test_payload_builder_fails_if_supplier_or_product_unmapped(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-UNMAPPED',
            'status' => SupplierReturn::STATUS_POSTED,
            'return_date' => now()->toDateString(),
            'total_amount' => '40.0000',
            'created_by' => $user->id,
        ]);

        $rawPayload = [
            'supplier_return_id' => $sr->id,
            'document_number' => $sr->document_number,
            'supplier_id' => $supplier->id,
            'total_amount' => '40.0000',
            'posted_at' => now()->toIso8601String(),
            'notes' => 'Some return notes',
            'lines' => [
                [
                    'supplier_return_line_id' => 'some-uuid-line-1',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => '4.0000',
                    'unit_cost' => '10.0000',
                    'line_total' => '40.0000',
                ]
            ]
        ];

        $outbox = \App\Models\AccountingOutbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'supplier_return_posted',
            'source_type' => 'supplier_return',
            'source_id' => $sr->id,
            'payload' => $rawPayload,
            'sync_status' => 'pending',
            'available_at' => now(),
        ]);

        // Using real database-backed mapping mapper but with no mappings seeded
        $tenantContext = app(TenantContext::class);
        $branchContext = app(\App\Services\BranchContext::class);
        $mappingService = new \App\Services\Accounting\AccountingMappingService($tenantContext, $branchContext);
        $normalizer = new \App\Services\Accounting\NormalizedPayloadService($mappingService);
        
        $payloadBuilder = new \App\Services\Accounting\QuickBooksPayloadBuilderService(
            $tenantContext,
            $branchContext,
            $normalizer
        );

        $tenantContext->setTenant($tenant);

        // Expect Exception because supplier or product is not mapped
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing QuickBooks mapping');

        $payloadBuilder->build($outbox);
    }

    /** @test */
    public function test_duplicate_posting_prevented_and_no_duplicate_outbox_rows(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => '10.0000',
            'average_cost' => '12.0000',
            'status' => 'active',
        ]);

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-MNL-20260518-0001',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
            'total_amount' => '40.0000',
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
            'line_total' => '40.0000',
        ]);

        app(TenantContext::class)->clear();
        $this->actingAs($manager);

        // First Post (Happy Path)
        $response1 = $this->post(route('procurement.returns.post', $sr->id));
        $response1->assertStatus(302);

        $this->setTenantContext($tenant);
        $this->assertEquals(1, \App\Models\AccountingOutbox::count());

        // Second Post (Already Posted - State Guard prevents duplicate work)
        app(TenantContext::class)->clear();
        $response2 = $this->post(route('procurement.returns.post', $sr->id));
        $response2->assertStatus(302);

        $this->setTenantContext($tenant);
        $this->assertEquals(1, \App\Models\AccountingOutbox::count());
    }

    /** @test */
    public function test_posting_rollback_prevents_orphan_outbox_rows(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => false,
            'is_inventory_tracked' => true,
        ]);

        // Branch inventory lacks active record, which triggers a ValidationException during post!
        // This ensures the transactional rollback works cleanly.

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-ROLLBACK-TEST',
            'status' => SupplierReturn::STATUS_APPROVED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
            'total_amount' => '40.0000',
        ]);

        SupplierReturnLine::create([
            'tenant_id' => $tenant->id,
            'supplier_return_id' => $sr->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
            'line_total' => '40.0000',
        ]);

        app(TenantContext::class)->clear();
        $this->actingAs($manager);

        // Attempt Post
        $response = $this->post(route('procurement.returns.post', $sr->id));
        $response->assertSessionHasErrors(['lines']);

        $this->setTenantContext($tenant);

        // Assert outbox row WAS NOT created due to rollback
        $this->assertEquals(0, \App\Models\AccountingOutbox::count());
        $this->assertEquals(SupplierReturn::STATUS_APPROVED, $sr->refresh()->status);
    }
}
