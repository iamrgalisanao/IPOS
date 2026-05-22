<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MasterPurchaseOrder;
use App\Models\MasterPurchaseOrderAllocation;
use App\Models\MasterPurchaseOrderLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Procurement\MasterPoSplitService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MasterPoSplitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchHQ;
    protected Branch $branchBR2;
    protected Supplier $supplier;
    protected Product $productA;
    protected Product $productB;
    protected User $adminUser;
    protected User $cashierUser;
    protected User $procurementUser;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();

        // 1. Establish tenant and seed RBAC
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        // 2. Setup branches and supplier
        $this->branchHQ = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'HQ']);
        $this->branchBR2 = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'BR2']);
        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CORP_SUP',
            'name' => 'Corporate Supplier Inc.',
        ]);

        // 3. Setup products
        $this->productA = Product::factory()->create(['tenant_id' => $this->tenant->id, 'cost_price' => 50.00]);
        $this->productB = Product::factory()->create(['tenant_id' => $this->tenant->id, 'cost_price' => 100.00]);

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
    public function test_happy_path_split_with_total_allocation_equals_ordered(): void
    {
        // 1. Arrange: Create Master PO in Approved status
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-HAPPY',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        $allocHQ = MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 60.0000,
        ]);

        $allocBR2 = MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchBR2->id,
            'allocated_quantity' => 40.0000,
        ]);

        // 2. Act: Trigger split
        $service = app(MasterPoSplitService::class);
        $result = $service->split($mpo, $this->adminUser);

        // 3. Assert: Master PO status & timestamps
        $this->assertTrue($result->isSplit());
        $this->assertNotNull($result->split_at);

        // Verify child POs are created (one for HQ, one for BR2)
        $childPos = PurchaseOrder::where('master_purchase_order_id', $mpo->id)->get();
        $this->assertCount(2, $childPos);

        foreach ($childPos as $po) {
            $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po->status);
            $this->assertEquals($this->supplier->id, $po->supplier_id);
            $this->assertCount(1, $po->lines);

            $line = $po->lines->first();
            $this->assertEquals($this->productA->id, $line->product_id);
            $this->assertEquals(45.0000, (float) $line->unit_cost);

            if ($po->branch_id === $this->branchHQ->id) {
                $this->assertEquals(60.0000, (float) $line->ordered_quantity);
                $this->assertEquals(2700.0000, (float) $line->line_total);
                $this->assertEquals(2700.0000, (float) $po->total_estimated_amount);
                $this->assertEquals($po->id, $allocHQ->refresh()->child_purchase_order_id);
            } else {
                $this->assertEquals(40.0000, (float) $line->ordered_quantity);
                $this->assertEquals(1800.0000, (float) $line->line_total);
                $this->assertEquals(1800.0000, (float) $po->total_estimated_amount);
                $this->assertEquals($po->id, $allocBR2->refresh()->child_purchase_order_id);
            }
        }

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'master_po_split',
            'auditable_type' => MasterPurchaseOrder::class,
            'auditable_id' => $mpo->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /** @test */
    public function test_split_succeeds_with_under_allocation(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-UNDER',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        // Allocate only 80 units total (under-allocation)
        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 80.0000,
        ]);

        $service = app(MasterPoSplitService::class);
        $result = $service->split($mpo, $this->adminUser);

        $this->assertTrue($result->isSplit());
        $childPos = PurchaseOrder::where('master_purchase_order_id', $mpo->id)->get();
        $this->assertCount(1, $childPos);
        $this->assertEquals(80.0000, (float) $childPos->first()->lines->first()->ordered_quantity);
    }

    /** @test */
    public function test_split_is_blocked_with_over_allocation(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-OVER',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        // Allocate 120 units total (over-allocation)
        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 60.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchBR2->id,
            'allocated_quantity' => 60.0000,
        ]);

        $service = app(MasterPoSplitService::class);

        $this->expectException(ValidationException::class);
        $service->split($mpo, $this->adminUser);
    }

    /** @test */
    public function test_split_is_blocked_with_zero_quantity_allocation(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-ZERO',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 0.0000, // zero allocation
        ]);

        $service = app(MasterPoSplitService::class);

        $this->expectException(ValidationException::class);
        $service->split($mpo, $this->adminUser);
    }

    /** @test */
    public function test_split_is_blocked_if_master_po_is_not_approved(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-DRAFT',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_DRAFT, // not approved
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 100.0000,
        ]);

        $service = app(MasterPoSplitService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only approved Master Purchase Orders can be split');

        $service->split($mpo, $this->adminUser);
    }

    /** @test */
    public function test_cross_tenant_allocation_is_strictly_forbidden(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);
        $branchTenantB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'branch_code' => 'TENB-HQ']);
        app(TenantContext::class)->setTenant($this->tenant);

        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-XTENANT',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        // Allocation to Tenant B's branch (Forbidden!)
        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $branchTenantB->id,
            'allocated_quantity' => 100.0000,
        ]);

        $service = app(MasterPoSplitService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant allocation or transfer is strictly forbidden');

        $service->split($mpo, $this->adminUser);
    }

    /** @test */
    public function test_split_idempotency(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-IDEMPOTENT',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 100.0000,
        ]);

        $service = app(MasterPoSplitService::class);

        // First split call
        $result1 = $service->split($mpo, $this->adminUser);
        $this->assertTrue($result1->isSplit());
        $this->assertCount(1, PurchaseOrder::where('master_purchase_order_id', $mpo->id)->get());

        // Second split call must exit cleanly and not duplicate child POs
        $result2 = $service->split($mpo, $this->adminUser);
        $this->assertTrue($result2->isSplit());
        $this->assertCount(1, PurchaseOrder::where('master_purchase_order_id', $mpo->id)->get());
    }

    /** @test */
    public function test_cashier_user_split_attempt_is_strictly_forbidden(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-CASHIER-BLOCKED',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->adminUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 100.0000,
        ]);

        $service = app(MasterPoSplitService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User is not authorized to split Master Purchase Orders');

        $service->split($mpo, $this->cashierUser);
    }

    /** @test */
    public function test_procurement_manager_role_can_split_successfully(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'master_po_number' => 'MPO-PROC-OK',
            'order_date' => '2026-05-18',
            'status' => MasterPurchaseOrder::STATUS_APPROVED,
            'created_by' => $this->procurementUser->id,
        ]);

        $mpoLine = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id' => $this->productA->id,
            'total_ordered_quantity' => 100.0000,
            'unit_cost' => 45.0000,
            'line_total' => 4500.0000,
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $mpoLine->id,
            'branch_id' => $this->branchHQ->id,
            'allocated_quantity' => 100.0000,
        ]);

        $service = app(MasterPoSplitService::class);
        $result = $service->split($mpo, $this->procurementUser);

        $this->assertTrue($result->isSplit());
        $this->assertCount(1, PurchaseOrder::where('master_purchase_order_id', $mpo->id)->get());
    }
}
