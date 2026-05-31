<?php

namespace Tests\Feature\POS;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDeductionTest extends TestCase
{
    use RefreshDatabase, \Tests\Traits\InteractsWithShifts;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Sale $sale;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $cardMethod;
    protected Product $trackedProduct;
    protected Product $nonTrackedProduct;
    protected BranchInventory $inventory;

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

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'actor_type' => 'tenant_user'
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::where('name', 'create_sale')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);

        $openShiftPermission = Permission::where('name', 'open_shift')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'open_shift']);
        $role->permissions()->attach($openShiftPermission);

        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);
        
        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN'
        ]);

        $this->trackedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'status' => 'active'
        ]);

        $this->nonTrackedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        $this->inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->trackedProduct->id,
            'current_stock' => 10.00,
            'status' => 'active'
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);

        $this->cardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARD',
            'name' => 'Card',
            'type' => 'card',
            'status' => 'active',
        ]);

        $this->openShiftFor($this->user, $this->branch);
    }

    private function createSale(array $items, ?string $tenantId = null, ?string $branchId = null, ?string $userId = null): Sale
    {
        $tenantId = $tenantId ?? $this->tenant->id;
        $branchId = $branchId ?? $this->branch->id;
        $userId = $userId ?? $this->user->id;

        $total = collect($items)->sum('line_total');
        $sale = Sale::factory()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'total' => $total,
            'status' => 'created',
        ]);

        foreach ($items as $item) {
            SaleItem::create(array_merge([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'sale_id' => $sale->id,
                'subtotal' => $item['line_total'],
                'tax_amount' => 0.00,
            ], $item));
        }

        return $sale;
    }

    private function postPayment(Sale $sale, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $sale->id]), $payload);
    }

    /** 1, 5, 7, 8, 9, 10, 11, 12, 13, 14, 29, 30: Successful single payment triggers stock deduction */
    public function test_successful_single_payment_deducts_inventory(): void
    {
        $sale = $this->createSale([[
            'product_id' => $this->trackedProduct->id,
            'product_name' => $this->trackedProduct->name,
            'quantity' => 2.00,
            'unit_price' => 50.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => true
        ]]);

        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($sale, $payload)->assertStatus(200);

        $this->inventory->refresh();
        $this->assertEquals(8.00, (float) $this->inventory->current_stock);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->trackedProduct->id,
            'movement_type' => 'sale_deduction',
            'quantity_change' => -2.00,
            'quantity_before' => 10.00,
            'quantity_after' => 8.00,
            'source_type' => 'sale',
            'source_id' => $sale->id
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_deducted_for_sale',
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'auditable_id' => $sale->id
        ]);
    }

    /** 2, 23: Successful split payment triggers correct stock deduction */
    public function test_successful_split_payment_deducts_inventory_once(): void
    {
        $sale = $this->createSale([[
            'product_id' => $this->trackedProduct->id,
            'product_name' => $this->trackedProduct->name,
            'quantity' => 3.00,
            'unit_price' => 100.00,
            'line_total' => 300.00,
            'is_inventory_tracked' => true
        ]]);

        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100],
                ['payment_method_id' => $this->cardMethod->id, 'amount' => 200],
            ]
        ];
        
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $sale->id]), $payload)
            ->assertStatus(200);

        $this->inventory->refresh();
        $this->assertEquals(7.00, (float) $this->inventory->current_stock);

        // Ensure only one movement record exists for this sale/product combo
        $count = InventoryMovement::where('source_id', $sale->id)
            ->where('product_id', $this->trackedProduct->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    /** 6: Non-inventory-tracked products do not create deduction movement */
    public function test_non_tracked_products_do_not_deduct_inventory(): void
    {
        $sale = $this->createSale([[
            'product_id' => $this->nonTrackedProduct->id,
            'product_name' => $this->nonTrackedProduct->name,
            'quantity' => 5.00,
            'unit_price' => 20.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => false
        ]]);

        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($sale, $payload)->assertStatus(200);

        $this->assertDatabaseMissing('inventory_movements', [
            'product_id' => $this->nonTrackedProduct->id,
            'source_id' => $sale->id
        ]);
    }

    /** 24, 25: Idempotency check */
    public function test_deduction_is_idempotent(): void
    {
        $sale = $this->createSale([[
            'product_id' => $this->trackedProduct->id,
            'product_name' => $this->trackedProduct->name,
            'quantity' => 1.00,
            'unit_price' => 100.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => true
        ]]);

        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($sale, $payload)->assertStatus(200);

        $this->inventory->refresh();
        $this->assertEquals(9.00, (float) $this->inventory->current_stock);

        // Manually trigger again
        app(\App\Services\InventoryService::class)->deductFromSale($sale);

        $this->inventory->refresh();
        $this->assertEquals(9.00, (float) $this->inventory->current_stock);
    }

    /** 26, 27: Isolation check */
    public function test_tenant_isolation_in_deduction(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        // Switch to other tenant for creation
        app(TenantContext::class)->setTenant($otherTenant);
        
        $otherCategory = ProductCategory::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'General',
            'code' => 'GEN'
        ]);

        $otherProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'product_category_id' => $otherCategory->id,
            'is_inventory_tracked' => true
        ]);
        
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active'
        ]);

        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active'
        ]);

        $sale = $this->createSale([[
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->name,
            'quantity' => 1.00,
            'unit_price' => 100.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => true
        ]], $otherTenant->id, $otherBranch->id, $otherUser->id);

        // Attempting to pay for a product belonging to a different tenant
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        
        // This should fail because the InventoryService validates tenant context
        $this->postPayment($sale, $payload)->assertStatus(422);

        // Restore context
        app(TenantContext::class)->setTenant($this->tenant);
    }

    /** 31, 32: Immutability check */
    public function test_inventory_movements_are_immutable(): void
    {
        $sale = $this->createSale([[
            'product_id' => $this->trackedProduct->id,
            'product_name' => $this->trackedProduct->name,
            'quantity' => 1.00,
            'unit_price' => 100.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => true
        ]]);

        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($sale, $payload)->assertStatus(200);

        $movement = InventoryMovement::where('source_id', $sale->id)->first();
        
        $this->expectException(\RuntimeException::class);
        $movement->update(['quantity_change' => -5.00]);
    }
}
