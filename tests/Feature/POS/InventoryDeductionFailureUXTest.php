<?php

namespace Tests\Feature\POS;

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

class InventoryDeductionFailureUXTest extends TestCase
{
    use RefreshDatabase, \Tests\Traits\InteractsWithShifts;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Sale $sale;
    protected PaymentMethod $cashMethod;
    protected Product $product;
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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'status' => 'active'
        ]);

        $this->inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 1.00, // Insufficient for the sale (requires 2.00)
            'status' => 'active'
        ]);

        $this->sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
            'status' => 'created',
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 2.00,
            'unit_price' => 50.00,
            'subtotal' => 100.00,
            'tax_amount' => 0.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => true
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);

        $this->openShiftFor($this->user, $this->branch);
    }

    private function postPayment(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), $payload);
    }

    /** AC: Insufficient stock during payment returns controlled cashier-friendly error */
    public function test_insufficient_stock_returns_cashier_friendly_error(): void
    {
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $response = $this->postPayment($payload);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Payment could not be completed because one or more items no longer have enough stock at this branch.');
        $response->assertJsonValidationErrors(['inventory']);

        // Verify no payment/movement persistence
        $this->assertDatabaseMissing('sale_payments', ['sale_id' => $this->sale->id]);
        $this->assertDatabaseMissing('inventory_movements', ['source_id' => $this->sale->id]);
        
        $this->sale->refresh();
        $this->assertEquals('created', $this->sale->status);
    }

    /** AC: Missing branch inventory during payment returns controlled error */
    public function test_missing_branch_inventory_returns_controlled_error(): void
    {
        $this->inventory->delete();

        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $response = $this->postPayment($payload);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Payment could not be completed because one or more items no longer have enough stock at this branch.');
    }

    /** AC: Error does not contain technical details */
    public function test_error_message_is_non_technical(): void
    {
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $response = $this->postPayment($payload);

        $content = $response->getContent();
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('SQL', $content);
        $this->assertStringNotContainsString('rollback', $content);
    }
}
