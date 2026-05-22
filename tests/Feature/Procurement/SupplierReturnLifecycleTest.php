<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseReceiving;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierReturnLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(TenantContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_cashier_is_completely_blocked_from_all_supplier_return_routes(): void
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
            'document_number' => 'RMA-MNL-20260518-0001',
            'status' => SupplierReturn::STATUS_DRAFT,
            'return_date' => now()->toDateString(),
            'created_by' => $cashier->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier);

        $this->get(route('procurement.returns.index'))->assertStatus(403);
        $this->get(route('procurement.returns.create'))->assertStatus(403);
        $this->post(route('procurement.returns.store'))->assertStatus(403);
        $this->get(route('procurement.returns.show', $sr->id))->assertStatus(403);
        $this->get(route('procurement.returns.edit', $sr->id))->assertStatus(403);
        $this->put(route('procurement.returns.update', $sr->id))->assertStatus(403);
        $this->post(route('procurement.returns.submit', $sr->id))->assertStatus(403);
        $this->post(route('procurement.returns.approve', $sr->id))->assertStatus(403);
        $this->post(route('procurement.returns.cancel', $sr->id))->assertStatus(403);
    }

    /** @test */
    public function test_branch_manager_can_create_supplier_return_draft_with_server_computed_amounts(): void
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

        $product1 = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 10.00]);
        $product2 = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 20.00]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.returns.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'return_date' => now()->toDateString(),
            'notes' => 'RMA for defective units',
            'lines' => [
                [
                    'product_id' => $product1->id,
                    'quantity' => 10,
                    'unit_cost' => 12.50, // overrides product cost
                ],
                [
                    'product_id' => $product2->id,
                    'quantity' => 5,
                    'unit_cost' => 20.00,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        $sr = SupplierReturn::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($sr);

        $response->assertRedirect(route('procurement.returns.show', $sr->id));

        // Assert RMA Document Number Prefix Format
        $expectedDocNumberPrefix = 'RMA-MNL-' . now()->format('Ymd');
        $this->assertStringStartsWith($expectedDocNumberPrefix, $sr->document_number);

        // Assert total amount computed server-side: (10 * 12.50) + (5 * 20.00) = 225
        $this->assertEquals(225.0000, (float) $sr->total_amount);

        // Assert individual lines count and line totals computed server-side
        $this->assertCount(2, $sr->lines);
        $this->assertEquals(125.0000, (float) $sr->lines[0]->line_total);
        $this->assertEquals(100.0000, (float) $sr->lines[1]->line_total);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_prefill_and_validation_from_posted_receiving_voucher(): void
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

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 10.00]);

        // Unposted GRV
        $unpostedGrv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-UNPOSTED-001',
            'status' => 'draft',
            'received_by' => $manager->id,
        ]);

        // Posted GRV
        $postedGrv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-POSTED-001',
            'status' => PurchaseReceiving::STATUS_POSTED,
            'received_by' => $manager->id,
            'posted_at' => now(),
            'posted_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. Trying to load unposted GRV in create page redirects back/shows error
        $response = $this->get(route('procurement.returns.create', ['purchase_receiving_id' => $unpostedGrv->id]));
        $response->assertStatus(302);
        $response->assertSessionHas('error');

        // 2. Loading posted GRV renders successfully
        $response = $this->get(route('procurement.returns.create', ['purchase_receiving_id' => $postedGrv->id]));
        $response->assertStatus(200);

        // 3. Storing return with unposted GRV fails validation
        $response = $this->post(route('procurement.returns.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_receiving_id' => $unpostedGrv->id,
            'return_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10]
            ]
        ]);
        $response->assertSessionHasErrors(['purchase_receiving_id']);
    }

    /** @test */
    public function test_tenant_isolation_is_strictly_enforced_across_returns(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // Setup Tenant A resources
        $this->setTenantContext($tenantA);
        $managerA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignRole(Role::where('name', 'Branch Manager')->first());
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignToBranch($branchA);
        $supplierA = Supplier::create(['tenant_id' => $tenantA->id, 'code' => 'SUPA', 'name' => 'Supplier A']);
        $productA = Product::factory()->create(['tenant_id' => $tenantA->id]);
        app(TenantContext::class)->clear();

        // Setup Tenant B resources
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $supplierB = Supplier::create(['tenant_id' => $tenantB->id, 'code' => 'SUPB', 'name' => 'Supplier B']);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($managerA);

        // Cross-tenant supplier fails (Supplier B is in Tenant B)
        $response1 = $this->post(route('procurement.returns.store'), [
            'supplier_id' => $supplierB->id,
            'branch_id' => $branchA->id,
            'return_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productA->id, 'quantity' => 5, 'unit_cost' => 10]
            ]
        ]);
        $response1->assertStatus(404);

        // Cross-tenant branch fails (Branch B is in Tenant B)
        $response2 = $this->post(route('procurement.returns.store'), [
            'supplier_id' => $supplierA->id,
            'branch_id' => $branchB->id,
            'return_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productA->id, 'quantity' => 5, 'unit_cost' => 10]
            ]
        ]);
        $response2->assertStatus(404);
    }

    /** @test */
    public function test_branch_managers_cannot_view_or_mutate_returns_from_other_branches(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch1); // assigned only to branch 1

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        // Return created for Branch 2 (manager does not have access)
        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-MNL-20260518-0001',
            'status' => SupplierReturn::STATUS_DRAFT,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Manager cannot view or edit return of Branch 2
        $this->get(route('procurement.returns.show', $sr->id))->assertStatus(403);
        $this->get(route('procurement.returns.edit', $sr->id))->assertStatus(403);

        $this->put(route('procurement.returns.update', $sr->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch2->id,
            'return_date' => now()->toDateString(),
            'lines' => []
        ])->assertStatus(403);

        $this->post(route('procurement.returns.submit', $sr->id))->assertStatus(403);
    }

    /** @test */
    public function test_full_supplier_return_happy_path_lifecycle_transitions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-LIFECYCLE',
            'status' => SupplierReturn::STATUS_DRAFT,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. DRAFT -> PENDING_APPROVAL
        $this->post(route('procurement.returns.submit', $sr->id))->assertStatus(302);
        $this->assertEquals(SupplierReturn::STATUS_PENDING_APPROVAL, $sr->refresh()->status);

        // 2. PENDING_APPROVAL -> APPROVED
        $this->post(route('procurement.returns.approve', $sr->id))->assertStatus(302);
        $this->assertEquals(SupplierReturn::STATUS_APPROVED, $sr->refresh()->status);
        $this->assertNotNull($sr->refresh()->approved_at);
        $this->assertEquals($manager->id, $sr->refresh()->approved_by);
    }

    /** @test */
    public function test_supplier_return_can_be_cancelled_from_any_non_terminal_state(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        $sr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-CANCEL',
            'status' => SupplierReturn::STATUS_DRAFT,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Cancel from DRAFT
        $this->post(route('procurement.returns.cancel', $sr->id))->assertStatus(302);
        $this->assertEquals(SupplierReturn::STATUS_CANCELLED, $sr->refresh()->status);
        $this->assertNotNull($sr->refresh()->cancelled_at);
    }

    /** @test */
    public function test_terminal_states_cannot_be_mutated_or_regressed(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        // 1. Return in Posted State
        $postedSr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-POSTED',
            'status' => SupplierReturn::STATUS_POSTED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        // 2. Return in Cancelled State
        $cancelledSr = SupplierReturn::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'document_number' => 'RMA-CANCELLED',
            'status' => SupplierReturn::STATUS_CANCELLED,
            'return_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Attempting to update a posted return throws session/validation errors
        $response = $this->put(route('procurement.returns.update', $postedSr->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'return_date' => now()->toDateString(),
            'lines' => []
        ]);
        $response->assertSessionHasErrors(['status']);

        // Attempting to submit/cancel a posted return throws session/validation errors
        $this->post(route('procurement.returns.submit', $postedSr->id))->assertSessionHasErrors(['status']);
        $this->post(route('procurement.returns.cancel', $postedSr->id))->assertSessionHasErrors(['status']);

        // Attempting to update a cancelled return throws session/validation errors
        $response2 = $this->put(route('procurement.returns.update', $cancelledSr->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'return_date' => now()->toDateString(),
            'lines' => []
        ]);
        $response2->assertSessionHasErrors(['status']);
    }
}
