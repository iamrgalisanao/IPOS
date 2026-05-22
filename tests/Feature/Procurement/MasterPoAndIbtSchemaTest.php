<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\InterBranchTransfer;
use App\Models\InterBranchTransferLine;
use App\Models\MasterPurchaseOrder;
use App\Models\MasterPurchaseOrderAllocation;
use App\Models\MasterPurchaseOrderLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPoAndIbtSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected Supplier $supplier;
    protected User $user;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();

        $this->tenant   = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA  = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'HQ']);
        $this->branchB  = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'branch_code' => 'BR2']);
        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CORP_SUP',
            'name' => 'Corporate Supplier Inc.',
        ]);
        $this->user    = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MasterPurchaseOrder Schema Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_master_purchase_order_can_be_created(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'       => $this->tenant->id,
            'supplier_id'     => $this->supplier->id,
            'master_po_number' => 'MPO-2026-0001',
            'order_date'      => '2026-05-18',
            'created_by'      => $this->user->id,
        ]);

        $this->assertDatabaseHas('master_purchase_orders', [
            'id'               => $mpo->id,
            'tenant_id'        => $this->tenant->id,
            'master_po_number' => 'MPO-2026-0001',
            'status'           => 'draft',
        ]);
    }

    /** @test */
    public function test_master_purchase_order_defaults_to_draft_status(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-DEFAULT',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $this->assertEquals(MasterPurchaseOrder::STATUS_DRAFT, $mpo->status);
        $this->assertTrue($mpo->isDraft());
        $this->assertFalse($mpo->isSplit());
        $this->assertFalse($mpo->isTerminal());
    }

    /** @test */
    public function test_master_purchase_order_unique_constraint_per_tenant(): void
    {
        MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-DUPE',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-DUPE',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);
    }

    /** @test */
    public function test_master_po_relationships_resolve(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-RELS',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $this->assertEquals($this->tenant->id, $mpo->tenant->id);
        $this->assertEquals($this->supplier->id, $mpo->supplier->id);
        $this->assertEquals($this->user->id, $mpo->createdBy->id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MasterPurchaseOrderLine / Allocation Schema Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_master_po_line_and_allocation_can_be_created(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-LINES',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $line = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id'               => $this->product->id,
            'total_ordered_quantity'   => '100.0000',
            'unit_cost'                => '50.0000',
            'line_total'               => '5000.0000',
        ]);

        $allocationA = MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $line->id,
            'branch_id'                     => $this->branchA->id,
            'allocated_quantity'            => '60.0000',
        ]);

        $allocationB = MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $line->id,
            'branch_id'                     => $this->branchB->id,
            'allocated_quantity'            => '40.0000',
        ]);

        $this->assertCount(2, $line->allocations);
        $this->assertEquals('60.0000', $allocationA->allocated_quantity);
        $this->assertEquals('40.0000', $allocationB->allocated_quantity);
        $this->assertEquals($this->product->id, $line->product->id);
        $this->assertEquals($mpo->id, $line->masterPurchaseOrder->id);
    }

    /** @test */
    public function test_allocation_unique_per_line_and_branch(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-ALLOC-DUPE',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $line = MasterPurchaseOrderLine::create([
            'master_purchase_order_id' => $mpo->id,
            'product_id'               => $this->product->id,
            'total_ordered_quantity'   => '100.0000',
            'unit_cost'                => '50.0000',
            'line_total'               => '5000.0000',
        ]);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $line->id,
            'branch_id'                     => $this->branchA->id,
            'allocated_quantity'            => '60.0000',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        MasterPurchaseOrderAllocation::create([
            'master_purchase_order_line_id' => $line->id,
            'branch_id'                     => $this->branchA->id,
            'allocated_quantity'            => '10.0000',
        ]);
    }

    /** @test */
    public function test_child_purchase_order_carries_master_po_id(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-CHILD',
            'order_date'       => '2026-05-18',
            'created_by'       => $this->user->id,
        ]);

        $childPo = PurchaseOrder::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branchA->id,
            'supplier_id'              => $this->supplier->id,
            'master_purchase_order_id' => $mpo->id,
            'po_number'                => 'PO-HQ-20260518-0001',
            'status'                   => 'draft',
            'order_date'               => '2026-05-18',
            'created_by'               => $this->user->id,
        ]);

        $this->assertEquals($mpo->id, $childPo->master_purchase_order_id);
        $this->assertEquals($mpo->id, $childPo->masterPurchaseOrder->id);
    }

    /** @test */
    public function test_master_po_immutability_guard_after_split(): void
    {
        $mpo = MasterPurchaseOrder::create([
            'tenant_id'        => $this->tenant->id,
            'supplier_id'      => $this->supplier->id,
            'master_po_number' => 'MPO-IMMUT',
            'order_date'       => '2026-05-18',
            'status'           => MasterPurchaseOrder::STATUS_SPLIT,
            'created_by'       => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable/');

        $mpo->update(['supplier_id' => $this->supplier->id . '-changed']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // InterBranchTransfer Schema Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_inter_branch_transfer_can_be_created(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id'              => $this->tenant->id,
            'source_branch_id'       => $this->branchA->id,
            'destination_branch_id'  => $this->branchB->id,
            'reference_number'       => 'IBT-2026-0001',
            'transfer_date'          => '2026-05-18',
            'created_by'             => $this->user->id,
        ]);

        $this->assertDatabaseHas('inter_branch_transfers', [
            'id'               => $ibt->id,
            'tenant_id'        => $this->tenant->id,
            'reference_number' => 'IBT-2026-0001',
            'status'           => 'draft',
        ]);
    }

    /** @test */
    public function test_ibt_defaults_to_draft_and_status_helpers_work(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-STATUS-001',
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);

        $this->assertEquals(InterBranchTransfer::STATUS_DRAFT, $ibt->status);
        $this->assertTrue($ibt->isDraft());
        $this->assertFalse($ibt->isInTransit());
        $this->assertFalse($ibt->isTerminal());
        $this->assertTrue($ibt->canBeCancelled());
    }

    /** @test */
    public function test_ibt_in_transit_cannot_be_cancelled(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-INTRANSIT-001',
            'status'                => InterBranchTransfer::STATUS_IN_TRANSIT,
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);

        $this->assertTrue($ibt->isInTransit());
        $this->assertFalse($ibt->canBeCancelled());
    }

    /** @test */
    public function test_ibt_unique_reference_per_tenant(): void
    {
        InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-DUPE',
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-DUPE',
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);
    }

    /** @test */
    public function test_ibt_line_can_be_created_with_product_and_quantity(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-LINES-001',
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);

        $line = InterBranchTransferLine::create([
            'inter_branch_transfer_id' => $ibt->id,
            'product_id'               => $this->product->id,
            'quantity_transferred'     => '25.0000',
            'unit_cost'                => '48.5000', // source WAC at dispatch (Q3)
            'line_total'               => '1212.5000',
        ]);

        $this->assertEquals('25.0000', $line->quantity_transferred);
        $this->assertEquals('48.5000', $line->unit_cost);
        $this->assertEquals($this->product->id, $line->product->id);
        $this->assertEquals($ibt->id, $line->interBranchTransfer->id);
    }

    /** @test */
    public function test_ibt_relationships_resolve(): void
    {
        $ibt = InterBranchTransfer::create([
            'tenant_id'             => $this->tenant->id,
            'source_branch_id'      => $this->branchA->id,
            'destination_branch_id' => $this->branchB->id,
            'reference_number'      => 'IBT-RELS-001',
            'transfer_date'         => '2026-05-18',
            'created_by'            => $this->user->id,
        ]);

        $this->assertEquals($this->tenant->id, $ibt->tenant->id);
        $this->assertEquals($this->branchA->id, $ibt->sourceBranch->id);
        $this->assertEquals($this->branchB->id, $ibt->destinationBranch->id);
        $this->assertEquals($this->user->id, $ibt->createdBy->id);
    }

    /** @test */
    public function test_master_po_belongs_to_tenant_scope_isolates_records(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantB);

        $supplierB = Supplier::create([
            'tenant_id' => $tenantB->id,
            'code' => 'SUP_B',
            'name' => 'Supplier B',
        ]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $mpoB = MasterPurchaseOrder::create([
            'tenant_id'        => $tenantB->id,
            'supplier_id'      => $supplierB->id,
            'master_po_number' => 'MPO-TENANT-B',
            'order_date'       => '2026-05-18',
            'created_by'       => $userB->id,
        ]);

        // Switch back to tenant A — tenant B's master PO must not be visible
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        MasterPurchaseOrder::findOrFail($mpoB->id);
    }
}
