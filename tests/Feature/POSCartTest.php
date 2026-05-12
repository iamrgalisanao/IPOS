<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\PaymentMethod;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class POSCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_pos_search_returns_accounting_silent_payload(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        
        $cat = ProductCategory::create(['name' => 'Food', 'code' => 'F']);
        Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Burger',
            'sku' => 'B1',
            'selling_price' => 10,
            'cost_price' => 5, // SENSITIVE
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get(route('pos.search', ['q' => 'Burger']));
            
        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertCount(1, $data);
        
        $product = $data[0];
        $this->assertEquals('Burger', $product['display_name']);
        $this->assertEquals(10, $product['selling_price']);
        
        // 9. Accounting-silent POS payload boundary
        $this->assertArrayNotHasKey('cost_price', $product);
        $this->assertArrayNotHasKey('quickbooks_id', $product);
    }

    /** @test */
    public function test_pos_ui_loads_with_tenant_context(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Juan Shop', 'status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get(route('pos.index'));
            
        $response->assertStatus(200);
        $response->assertSee('Juan Shop');
    }

    /** @test */
    public function test_pos_ui_provisions_default_payment_methods_when_missing(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Juan Shop', 'status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertCount(0, PaymentMethod::all());

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get(route('pos.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('POS/Index')
            ->has('payment_methods', 2)
            ->where('payment_methods.0.code', 'CASH')
            ->where('payment_methods.1.code', 'GCASH')
        );

        $this->assertDatabaseHas('payment_methods', [
            'tenant_id' => $tenant->id,
            'code' => 'CASH',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'tenant_id' => $tenant->id,
            'code' => 'GCASH',
            'status' => 'active',
        ]);
    }

    /** @test */
    public function test_pos_ui_only_returns_active_payment_methods_for_current_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        PaymentMethod::create(['code' => 'CARD', 'name' => 'Card', 'type' => 'card', 'status' => 'active']);
        PaymentMethod::create(['code' => 'OLD', 'name' => 'Old Method', 'type' => 'other', 'status' => 'inactive']);

        app(TenantContext::class)->setTenant($otherTenant);
        PaymentMethod::create(['code' => 'FOREIGN', 'name' => 'Foreign Method', 'type' => 'cash', 'status' => 'active']);

        app(TenantContext::class)->setTenant($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get(route('pos.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('POS/Index')
            ->has('payment_methods', 3)
            ->where('payment_methods.0.code', 'CASH')
            ->where('payment_methods.1.code', 'CARD')
            ->where('payment_methods.2.code', 'GCASH')
        );
    }

    /** @test */
    public function test_pos_search_respects_category_filter(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        
        $c1 = ProductCategory::create(['name' => 'Drinks', 'code' => 'D']);
        $c2 = ProductCategory::create(['name' => 'Food', 'code' => 'F']);
        
        Product::create(['product_category_id' => $c1->id, 'name' => 'Coke', 'sku' => 'C1', 'selling_price' => 2]);
        Product::create(['product_category_id' => $c2->id, 'name' => 'Pizza', 'sku' => 'P1', 'selling_price' => 15]);

        // Filter by Drinks
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get(route('pos.search', ['category_id' => $c1->id]));
            
        $this->assertCount(1, $response->json());
        $this->assertEquals('Coke', $response->json()[0]['display_name']);
    }
}
