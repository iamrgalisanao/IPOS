<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitConversionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $owner;
    protected User $cashier;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        
        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());

        $this->productA = Product::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'PRODA', 'name' => 'Product A']);
        $this->productB = Product::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'PRODB', 'name' => 'Product B']);
        
        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('inventory.unit-conversions.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_are_forbidden_from_viewing_unit_conversions(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.unit-conversions.index'))
            ->assertForbidden();
    }

    public function test_authorized_users_can_view_conversions(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.unit-conversions.index'));

        $response->assertOk();
    }

    public function test_can_create_valid_global_conversion(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.unit-conversions.store'), [
                'from_unit' => 'Bag',
                'to_unit' => 'kg',
                'conversion_factor' => 25.5,
                'is_active' => true,
            ]);

        $response->assertRedirect();
        
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertDatabaseHas('unit_conversions', [
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'Bag',
            'to_unit' => 'kg',
            'conversion_factor' => 25.5,
            'is_active' => true,
        ]);
    }

    public function test_can_create_valid_product_override_conversion(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.unit-conversions.store'), [
                'product_id' => $this->productA->id,
                'from_unit' => 'Bag',
                'to_unit' => 'kg',
                'conversion_factor' => 50.0,
                'is_active' => true,
            ]);

        $response->assertRedirect();
        
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertDatabaseHas('unit_conversions', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->productA->id,
            'from_unit' => 'Bag',
            'to_unit' => 'kg',
            'conversion_factor' => 50.0,
            'is_active' => true,
        ]);
    }

    public function test_global_uniqueness_is_enforced(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'from_unit' => 'Bag',
            'to_unit' => 'kg',
            'conversion_factor' => 25.5,
            'is_active' => true
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.unit-conversions.store'), [
                'from_unit' => 'Bag',
                'to_unit' => 'kg',
                'conversion_factor' => 10.0,
                'is_active' => true,
            ]);

        $response->assertSessionHasErrors(['from_unit']);
    }

    public function test_product_uniqueness_is_enforced(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->productA->id,
            'from_unit' => 'Bag',
            'to_unit' => 'kg',
            'conversion_factor' => 25.5,
            'is_active' => true
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.unit-conversions.store'), [
                'product_id' => $this->productA->id,
                'from_unit' => 'Bag',
                'to_unit' => 'kg',
                'conversion_factor' => 10.0,
                'is_active' => true,
            ]);

        $response->assertSessionHasErrors(['from_unit']);
    }

    public function test_soft_deactivation_is_enforced_instead_of_hard_delete(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $conversion = UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'from_unit' => 'Bag',
            'to_unit' => 'kg',
            'conversion_factor' => 25.5,
            'is_active' => true
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->delete(route('inventory.unit-conversions.destroy', $conversion->id));

        $response->assertRedirect();
        
        // Assert still exists in database, but is_active = false
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertDatabaseHas('unit_conversions', [
            'id' => $conversion->id,
            'is_active' => false
        ]);
    }
}
