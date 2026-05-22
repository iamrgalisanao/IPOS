<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InterBranchTransfer;
use App\Models\InterBranchTransferLine;
use App\Models\Product;
use App\Models\ExpiryLot;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Procurement\IbtStockMovementService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IbtStockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchHQ;
    protected Branch $branchBR2;
    protected Product $productGeneral;
    protected Product $productPerishable;
    protected User $adminUser;
    protected User $cashierUser;
    protected User $procurementUser;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        // 1. Establish tenant and seed RBAC
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        // 2. Setup branches
        $this->branchHQ = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'HQ']);
        $this->branchBR2 = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'BR2']);

        // Set default branch context
        app(BranchContext::class)->setBranch($this->branchHQ);

        // 3. Setup products
        $this->productGeneral = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Product',
            'cost_price' => 50.00,
            'expiry_tracking_enabled' => false
        ]);

        $this->productPerishable = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Perishable Product',
            'cost_price' => 100.00,
            'expiry_tracking_enabled' => true
        ]);

        // 4. Setup users with roles
        $this->adminUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->adminUser->assignRole(Role::where('name', 'Owner/Admin')->first());

        $this->cashierUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashierUser->assignRole(Role::where('name', 'Cashier')->first());

        $procRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Procurement Manager',
            'description' => 'Procurement Manager Role',
        ]);
        $this->procurementUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->procurementUser->assignRole($procRole);
    }

    /** @test */
    public function test_happy_path_dispatch_and_receive_general_product(): void
    {
        // Setup initial source branch inventory and WAC
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productGeneral->id,
            'current_stock' => 100.0000,
            'average_cost' => 45.0000,
            'status' => 'active'
        ]);

        // Setup initial destination branch inventory with existing WAC
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchBR2->id,
            'product_id' => $this->productGeneral->id,
            'current_stock' => 20.0000,
            'average_cost' => 55.0000,
            'status' => 'active'
        ]);

        // Create an approved IBT
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-HAPPY-01',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        $line = InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productGeneral->id,
            'quantity_transferred' => 10.0000,
            'unit_cost' => 0.0000, // will be frozen at dispatch
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        // --- 1. DISPATCH STAGE ---
        $ibt = $service->dispatch($ibt, $this->adminUser);

        // Assert status and audit log
        $this->assertEquals(InterBranchTransfer::STATUS_IN_TRANSIT, $ibt->status);
        $this->assertEquals($this->adminUser->id, $ibt->dispatched_by);
        $this->assertNotNull($ibt->dispatched_at);

        // Assert WAC is frozen as transfer unit cost
        $line->refresh();
        $this->assertEquals('45.0000', $line->unit_cost);
        $this->assertEquals('450.0000', $line->line_total);

        // Assert source inventory is decremented
        $sourceInv = BranchInventory::where('branch_id', $this->branchHQ->id)
            ->where('product_id', $this->productGeneral->id)
            ->first();
        $this->assertEquals('90.0000', $sourceInv->current_stock);

        // Assert source movement record exists
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productGeneral->id,
            'movement_type' => 'ibt_dispatch',
            'quantity_change' => '-10.0000',
            'quantity_before' => '100.0000',
            'quantity_after' => '90.0000',
            'source_type' => InterBranchTransfer::class,
            'source_id' => $ibt->id
        ]);

        // Assert AuditLog dispatch entry exists
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'ibt_dispatched',
            'auditable_type' => InterBranchTransfer::class,
            'auditable_id' => $ibt->id
        ]);

        // --- 2. RECEIVE STAGE ---
        $ibt = $service->receive($ibt, $this->adminUser);

        // Assert status
        $this->assertEquals(InterBranchTransfer::STATUS_RECEIVED, $ibt->status);
        $this->assertEquals($this->adminUser->id, $ibt->received_by);
        $this->assertNotNull($ibt->received_at);

        // Assert destination inventory is incremented
        $destInv = BranchInventory::where('branch_id', $this->branchBR2->id)
            ->where('product_id', $this->productGeneral->id)
            ->first();
        $this->assertEquals('30.0000', $destInv->current_stock);

        // Assert destination WAC is recalculated
        // Expected new WAC: ((20 * 55) + (10 * 45)) / 30 = (1100 + 450) / 30 = 1550 / 30 = 51.6666
        $this->assertEquals('51.6666', $destInv->average_cost);

        // Assert destination movement record exists
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchBR2->id,
            'product_id' => $this->productGeneral->id,
            'movement_type' => 'ibt_receipt',
            'quantity_change' => '10.0000',
            'quantity_before' => '20.0000',
            'quantity_after' => '30.0000',
            'source_type' => InterBranchTransfer::class,
            'source_id' => $ibt->id
        ]);

        // Assert AuditLog receipt entry exists
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'ibt_received',
            'auditable_type' => InterBranchTransfer::class,
            'auditable_id' => $ibt->id
        ]);
    }

    /** @test */
    public function test_underflow_insufficient_stock_fails_atomically(): void
    {
        // Setup initial source branch inventory with LESS stock than transfer qty
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productGeneral->id,
            'current_stock' => 5.0000,
            'average_cost' => 45.0000,
            'status' => 'active'
        ]);

        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-UNDERFLOW',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productGeneral->id,
            'quantity_transferred' => 10.0000, // exceeds 5.0000
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds current source branch stock');

        try {
            $service->dispatch($ibt, $this->adminUser);
        } finally {
            // Assert atomic rollback: stock remains 5.0000, status remains approved
            $ibt->refresh();
            $this->assertEquals(InterBranchTransfer::STATUS_APPROVED, $ibt->status);
            $sourceInv = BranchInventory::where('branch_id', $this->branchHQ->id)->where('product_id', $this->productGeneral->id)->first();
            $this->assertEquals('5.0000', $sourceInv->current_stock);
        }
    }

    /** @test */
    public function test_cross_tenant_transfer_is_strictly_forbidden(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);
        $branchTenantB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'branch_code' => 'TENB-BR']);
        app(TenantContext::class)->setTenant($this->tenant);

        // Try to transfer to Tenant B's branch
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $branchTenantB->id, // Tenant B branch!
            'reference_number' => 'IBT-XTENANT',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productGeneral->id,
            'quantity_transferred' => 10.0000,
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant allocation or transfer is strictly forbidden');

        $service->dispatch($ibt, $this->adminUser);
    }

    /** @test */
    public function test_dispatch_is_blocked_if_ibt_is_not_approved(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-DRAFT',
            'status' => InterBranchTransfer::STATUS_DRAFT, // draft, not approved
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        $service = app(IbtStockMovementService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only approved Inter-Branch Transfers can be dispatched');

        $service->dispatch($ibt, $this->adminUser);
    }

    /** @test */
    public function test_dispatch_and_receive_idempotency(): void
    {
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productGeneral->id,
            'current_stock' => 100.0000,
            'average_cost' => 45.0000,
            'status' => 'active'
        ]);

        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-IDEMPOTENT',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productGeneral->id,
            'quantity_transferred' => 10.0000,
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        // First dispatch
        $ibt = $service->dispatch($ibt, $this->adminUser);
        $this->assertEquals(InterBranchTransfer::STATUS_IN_TRANSIT, $ibt->status);

        // Second dispatch (should exit early with no stock double deduction)
        $ibt = $service->dispatch($ibt, $this->adminUser);
        $this->assertEquals(InterBranchTransfer::STATUS_IN_TRANSIT, $ibt->status);

        $sourceInv = BranchInventory::where('branch_id', $this->branchHQ->id)->where('product_id', $this->productGeneral->id)->first();
        $this->assertEquals('90.0000', $sourceInv->current_stock); // exact deduction, not double deducted!

        // First receive
        $ibt = $service->receive($ibt, $this->adminUser);
        $this->assertEquals(InterBranchTransfer::STATUS_RECEIVED, $ibt->status);

        // Second receive (should exit early with no stock double increment)
        $ibt = $service->receive($ibt, $this->adminUser);
        $this->assertEquals(InterBranchTransfer::STATUS_RECEIVED, $ibt->status);

        $destInv = BranchInventory::where('branch_id', $this->branchBR2->id)->where('product_id', $this->productGeneral->id)->first();
        $this->assertEquals('10.0000', $destInv->current_stock); // exact increment, not double incremented!
    }

    /** @test */
    public function test_cashier_user_cannot_perform_dispatch_or_receive(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-CASHIER',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        $service = app(IbtStockMovementService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User is not authorized');

        $service->dispatch($ibt, $this->cashierUser);
    }

    /** @test */
    public function test_procurement_manager_role_can_perform_operations_successfully(): void
    {
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productGeneral->id,
            'current_stock' => 100.0000,
            'average_cost' => 45.0000,
            'status' => 'active'
        ]);

        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-PROC-MGR',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productGeneral->id,
            'quantity_transferred' => 10.0000,
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        // Procurement Manager can dispatch
        $ibt = $service->dispatch($ibt, $this->procurementUser);
        $this->assertEquals(InterBranchTransfer::STATUS_IN_TRANSIT, $ibt->status);

        // Procurement Manager can receive
        $ibt = $service->receive($ibt, $this->procurementUser);
        $this->assertEquals(InterBranchTransfer::STATUS_RECEIVED, $ibt->status);
    }

    /** @test */
    public function test_expiry_lots_handling_with_explicit_lot_selection(): void
    {
        // 1. Setup source branch inventory
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productPerishable->id,
            'current_stock' => 50.0000,
            'average_cost' => 85.0000,
            'status' => 'active'
        ]);

        // Create explicit expiry lot at source branch
        $sourceLot = ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productPerishable->id,
            'batch_code' => 'LOT-PERISH-001',
            'quantity_received' => 50.0000,
            'quantity_remaining' => 50.0000,
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'status' => 'active'
        ]);

        // Create approved IBT with explicit lot selected
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-LOT-EXP',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        $line = InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productPerishable->id,
            'expiry_lot_id' => $sourceLot->id, // explicit lot selection
            'quantity_transferred' => 20.0000,
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        // Dispatch
        $ibt = $service->dispatch($ibt, $this->adminUser);

        // Assert source lot decremented
        $sourceLot->refresh();
        $this->assertEquals('30.0000', $sourceLot->quantity_remaining);

        // Receive
        $ibt = $service->receive($ibt, $this->adminUser);

        // Assert destination lot with the same batch_code and expiry_date was created successfully
        $destLot = ExpiryLot::where('branch_id', $this->branchBR2->id)
            ->where('product_id', $this->productPerishable->id)
            ->where('batch_code', 'LOT-PERISH-001')
            ->first();

        $this->assertNotNull($destLot);
        $this->assertEquals('20.0000', $destLot->quantity_remaining);
        $this->assertEquals('20.0000', $destLot->quantity_received);
        $this->assertEquals($sourceLot->expiry_date, $destLot->expiry_date);
    }

    /** @test */
    public function test_expiry_lots_handling_with_fefo_fallback_allocation(): void
    {
        // 1. Setup source branch inventory
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productPerishable->id,
            'current_stock' => 50.0000,
            'average_cost' => 85.0000,
            'status' => 'active'
        ]);

        // Create two unexpired source lots
        // Lot A expires in 2 months (earliest - should be picked first by FEFO)
        $lotA = ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productPerishable->id,
            'batch_code' => 'LOT-FEFO-A',
            'quantity_received' => 30.0000,
            'quantity_remaining' => 30.0000,
            'expiry_date' => now()->addMonths(2)->toDateString(),
            'status' => 'active'
        ]);

        // Lot B expires in 12 months (latest)
        $lotB = ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchHQ->id,
            'product_id' => $this->productPerishable->id,
            'batch_code' => 'LOT-FEFO-B',
            'quantity_received' => 20.0000,
            'quantity_remaining' => 20.0000,
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'status' => 'active'
        ]);

        // Create approved IBT with NO lot selected (triggering FEFO fallback)
        $ibt = InterBranchTransfer::create([
            'tenant_id' => $this->tenant->id,
            'source_branch_id' => $this->branchHQ->id,
            'destination_branch_id' => $this->branchBR2->id,
            'reference_number' => 'IBT-FEFO-FALLBACK',
            'status' => InterBranchTransfer::STATUS_APPROVED,
            'transfer_date' => '2026-05-18',
            'created_by' => $this->adminUser->id
        ]);

        $line = InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id' => $this->productPerishable->id,
            'expiry_lot_id' => null, // FEFO fallback
            'quantity_transferred' => 20.0000,
            'unit_cost' => 0.0000,
            'line_total' => 0.0000
        ]);

        $service = app(IbtStockMovementService::class);

        // Dispatch
        $ibt = $service->dispatch($ibt, $this->adminUser);

        // FEFO should select Lot A (earliest expiry) and allocate 20.0000 from it
        $lotA->refresh();
        $this->assertEquals('10.0000', $lotA->quantity_remaining);
        
        $lotB->refresh();
        $this->assertEquals('20.0000', $lotB->quantity_remaining); // untouched

        // Line expiry_lot_id should be backfilled with Lot A's ID
        $line->refresh();
        $this->assertEquals($lotA->id, $line->expiry_lot_id);

        // Receive
        $ibt = $service->receive($ibt, $this->adminUser);

        // Destination lot should be created for Lot A's batch details at destination branch
        $destLot = ExpiryLot::where('branch_id', $this->branchBR2->id)
            ->where('product_id', $this->productPerishable->id)
            ->where('batch_code', 'LOT-FEFO-A')
            ->first();

        $this->assertNotNull($destLot);
        $this->assertEquals('20.0000', $destLot->quantity_remaining);
        $this->assertEquals($lotA->expiry_date, $destLot->expiry_date);
    }
}
