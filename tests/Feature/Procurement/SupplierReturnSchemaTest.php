<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseReceiving;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierReturnSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_supplier_returns_tables_have_expected_schema_columns(): void
    {
        // 1. Assert supplier_returns table exists and contains expected columns
        $this->assertTrue(Schema::hasTable('supplier_returns'));
        $this->assertTrue(Schema::hasColumns('supplier_returns', [
            'id',
            'tenant_id',
            'branch_id',
            'supplier_id',
            'purchase_receiving_id',
            'document_number',
            'status',
            'return_date',
            'total_amount',
            'notes',
            'created_by',
            'approved_by',
            'posted_by',
            'cancelled_by',
            'approved_at',
            'posted_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]));

        // 2. Assert supplier_return_lines table exists and contains expected columns
        $this->assertTrue(Schema::hasTable('supplier_return_lines'));
        $this->assertTrue(Schema::hasColumns('supplier_return_lines', [
            'id',
            'supplier_return_id',
            'product_id',
            'expiry_lot_id',
            'quantity',
            'unit_cost',
            'line_total',
            'batch_code',
            'expiry_date',
            'created_at',
            'updated_at',
        ]));
    }

    /** @test */
    public function test_supplier_return_model_attributes_and_defaults(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-01', 'name' => 'Test Supplier']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $return = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-TEST-0001',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        // Assert defaults
        $this->assertEquals(SupplierReturn::STATUS_DRAFT, $return->status);
        $this->assertEquals('0.0000', (string) $return->total_amount);
        $this->assertTrue($return->isDraft());
        $this->assertFalse($return->isTerminal());

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_supplier_return_relationships_work_correctly(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-01', 'name' => 'Test Supplier']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        
        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'receiving_number' => 'GRV-001',
            'status' => 'draft',
            'received_by' => $user->id,
        ]);

        $category = ProductCategory::create(['tenant_id' => $tenant->id, 'name' => 'Pastry', 'code' => 'PAS']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'product_category_id' => $category->id]);
        $lot = ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_code' => 'LOT-01',
            'quantity_received' => 10,
            'quantity_remaining' => 10,
            'status' => 'active',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $return = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_receiving_id' => $receiving->id,
            'document_number' => 'RMA-TEST-0001',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'posted_by' => $user->id,
            'cancelled_by' => $user->id,
        ]);

        $line = SupplierReturnLine::create([
            'supplier_return_id' => $return->id,
            'product_id' => $product->id,
            'expiry_lot_id' => $lot->id,
            'quantity' => 5.0000,
            'unit_cost' => 10.0000,
            'line_total' => 50.0000,
            'batch_code' => 'LOT-01',
        ]);

        // Assert relations on SupplierReturn
        $this->assertEquals($tenant->id, $return->tenant->id);
        $this->assertEquals($branch->id, $return->branch->id);
        $this->assertEquals($supplier->id, $return->supplier->id);
        $this->assertEquals($receiving->id, $return->purchaseReceiving->id);
        $this->assertEquals($user->id, $return->createdBy->id);
        $this->assertEquals($user->id, $return->approvedBy->id);
        $this->assertEquals($user->id, $return->postedBy->id);
        $this->assertEquals($user->id, $return->cancelledBy->id);
        $this->assertEquals(1, $return->lines()->count());

        // Assert relations on SupplierReturnLine
        $this->assertEquals($return->id, $line->supplierReturn->id);
        $this->assertEquals($product->id, $line->product->id);
        $this->assertEquals($lot->id, $line->expiryLot->id);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_supplier_return_state_transition_helpers(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-01', 'name' => 'Test Supplier']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $return = new SupplierReturn([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-TEST-0001',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        // Test Draft State
        $return->status = SupplierReturn::STATUS_DRAFT;
        $this->assertTrue($return->isDraft());
        $this->assertTrue($return->canBeEdited());
        $this->assertTrue($return->canBeSubmitted());
        $this->assertTrue($return->canBeCancelled());
        $this->assertFalse($return->isTerminal());
        $this->assertFalse($return->canBeApproved());
        $this->assertFalse($return->canBePosted());

        // Test Pending Approval State
        $return->status = SupplierReturn::STATUS_PENDING_APPROVAL;
        $this->assertTrue($return->isPendingApproval());
        $this->assertTrue($return->canBeApproved());
        $this->assertTrue($return->canBeCancelled());
        $this->assertFalse($return->canBeEdited());
        $this->assertFalse($return->canBeSubmitted());
        $this->assertFalse($return->canBePosted());

        // Test Approved State
        $return->status = SupplierReturn::STATUS_APPROVED;
        $this->assertTrue($return->isApproved());
        $this->assertTrue($return->canBePosted());
        $this->assertTrue($return->canBeCancelled());
        $this->assertFalse($return->canBeEdited());
        $this->assertFalse($return->canBeApproved());

        // Test Posted (Terminal) State
        $return->status = SupplierReturn::STATUS_POSTED;
        $this->assertTrue($return->isPosted());
        $this->assertTrue($return->isTerminal());
        $this->assertFalse($return->canBeEdited());
        $this->assertFalse($return->canBeCancelled());

        // Test Cancelled (Terminal) State
        $return->status = SupplierReturn::STATUS_CANCELLED;
        $this->assertTrue($return->isCancelled());
        $this->assertTrue($return->isTerminal());
        $this->assertFalse($return->canBeEdited());
        $this->assertFalse($return->canBeCancelled());

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_document_number_generation_sequence_and_uniqueness(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-01', 'name' => 'Test Supplier']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $date = '2026-05-18';

        // First Generation (Should be sequence 0001)
        $doc1 = SupplierReturn::generateDocumentNumber($tenant->id, $branch->id, $date);
        $this->assertEquals('RMA-MNL-20260518-0001', $doc1);

        // Save doc1 to DB
        SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => $doc1,
            'return_date' => $date,
            'created_by' => $user->id,
        ]);

        // Second Generation (Should be sequence 0002)
        $doc2 = SupplierReturn::generateDocumentNumber($tenant->id, $branch->id, $date);
        $this->assertEquals('RMA-MNL-20260518-0002', $doc2);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_uniqueness_constraint_fails_on_duplicate_document_numbers_per_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-01', 'name' => 'Test Supplier']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-TEST-DUP',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-TEST-DUP',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        app(TenantContext::class)->clear();
    }
}
