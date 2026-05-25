<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProductPricing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_basic_subscription_is_blocked_from_premium_quickbooks_routes(): void
    {
        // 1. Create a basic tenant (no quickbooks.sync feature)
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic']
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);

        // 2. Create user and assign Owner/Admin role
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        // 3. Act as user and request premium route
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/accounting/quickbooks');

        // Should return 403 Forbidden
        $response->assertStatus(403);
    }

    /** @test */
    public function test_enterprise_subscription_is_allowed_to_access_premium_quickbooks_routes(): void
    {
        // 1. Create an enterprise tenant (has quickbooks.sync feature)
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);

        // 2. Create user and assign Owner/Admin role
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        // 3. Act as user and request premium route
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/accounting/quickbooks');

        // Should render successfully (200)
        $response->assertStatus(200);
    }

    /** @test */
    public function test_basic_subscription_is_blocked_from_custom_pos_layouts(): void
    {
        // 1. Create basic tenant
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic']
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/pos-layouts');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_professional_subscription_is_allowed_to_custom_pos_layouts(): void
    {
        // 1. Create professional tenant (has layout.custom feature)
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional']
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/pos-layouts');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_basic_subscription_is_blocked_from_reports_basic_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        // Disable reports.basic through tenant override to validate middleware deny path.
        $tenant->update([
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['reports.basic' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/reports/tax');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_professional_subscription_is_allowed_to_reports_basic_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/reports/tax');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_basic_subscription_is_blocked_from_procurement_basic_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/procurement/suppliers');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_professional_subscription_is_allowed_to_procurement_basic_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/procurement/suppliers');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_professional_subscription_is_blocked_from_procurement_advanced_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/procurement/returns');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_enterprise_subscription_is_allowed_to_procurement_advanced_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/procurement/returns');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_basic_subscription_is_blocked_from_catalog_edit_write_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);
        $fixtures = $this->createCatalogFixtures($tenant);

        $requests = $this->catalogEditWriteRequests($fixtures);

        foreach ($requests as $request) {
            $response = $this->actingAs($user)
                ->withHeader('X-Tenant-ID', $tenant->id)
                ->{$request['method']}($request['uri'], $request['payload'] ?? []);

            $response->assertStatus(403);
        }
    }

    /** @test */
    public function test_professional_subscription_is_allowed_to_catalog_edit_write_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);
        $fixtures = $this->createCatalogFixtures($tenant);

        $requests = $this->catalogEditWriteRequests($fixtures);

        foreach ($requests as $request) {
            $response = $this->actingAs($user)
                ->withHeader('X-Tenant-ID', $tenant->id)
                ->{$request['method']}($request['uri'], $request['payload'] ?? []);

            $this->assertNotEquals(403, $response->getStatusCode(), 'Unexpected 403 for ' . strtoupper($request['method']) . ' ' . $request['uri']);
        }
    }

    /** @test */
    public function test_non_entitled_tenant_cannot_access_product_category_index(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['catalog.view' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_non_entitled_tenant_cannot_access_product_index(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['catalog.view' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_entitled_tenant_can_access_product_category_index(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_entitled_tenant_can_access_product_index(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_catalog_view_true_and_catalog_edit_false_can_view_lists_but_cannot_write(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'professional',
                'features' => ['catalog.edit' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);
        $fixtures = $this->createCatalogFixtures($tenant);

        $listA = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories');
        $listA->assertStatus(200);

        $listB = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products');
        $listB->assertStatus(200);

        $exportCategories = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories/export/csv');
        $exportCategories->assertStatus(200);

        $exportProducts = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products/export/csv');
        $exportProducts->assertStatus(200);

        $write = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->post('/admin/products', [
                'product_category_id' => $fixtures['category']->id,
                'name' => 'Blocked Write',
                'sku' => 'BLK-WRITE-001',
                'unit_of_measure' => 'piece',
                'selling_price' => 100,
                'cost_price' => 50,
                'is_taxable' => true,
                'is_inventory_tracked' => true,
                'is_discountable' => true,
                'status' => 'active',
                'product_type' => 'finished_good',
                'is_sellable' => true,
            ]);

        $write->assertStatus(403);
    }

    /** @test */
    public function test_non_entitled_tenant_cannot_access_catalog_export_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['catalog.view' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $categoryExport = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories/export/csv');

        $productExport = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products/export/csv');

        $categoryExport->assertStatus(403);
        $productExport->assertStatus(403);
    }

    /** @test */
    public function test_catalog_import_preview_routes_require_catalog_edit_entitlement(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'professional',
                'features' => ['catalog.edit' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        $categoryTemplate = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/product-categories/import/template/csv');

        $productTemplate = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products/import/template/csv');

        $categoryPreview = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->post('/admin/product-categories/import/preview');

        $productPreview = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->post('/admin/products/import/preview');

        $categoryTemplate->assertStatus(403);
        $productTemplate->assertStatus(403);
        $categoryPreview->assertStatus(403);
        $productPreview->assertStatus(403);
    }

    /** @test */
    public function test_catalog_edit_form_routes_require_catalog_edit_entitlement(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'professional',
                'features' => ['catalog.edit' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);
        $fixtures = $this->createCatalogFixtures($tenant);

        $create = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products/create');

        $edit = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get("/admin/products/{$fixtures['product']->id}/edit");

        $create->assertStatus(403);
        $edit->assertStatus(403);
    }

    /** @test */
    public function test_catalog_edit_form_routes_allow_catalog_edit_entitled_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);
        $fixtures = $this->createCatalogFixtures($tenant);

        $create = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/admin/products/create');

        $edit = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get("/admin/products/{$fixtures['product']->id}/edit");

        $create->assertStatus(200);
        $edit->assertStatus(200);
    }

    /** @test */
    public function test_pos_search_remains_unchanged_for_entitled_tenants(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/pos/search?q=te');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_stocktake_catalog_search_remains_unchanged_for_entitled_tenants(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->get('/inventory/stocktakes/catalog/search?q=t');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_sales_pos_disabled_tenant_is_blocked_from_checkout_sensitive_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['sales.pos' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $requests = [
            ['method' => 'post', 'uri' => '/pos/checkout/validate', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/checkout/create-sale', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/checkout/status', 'payload' => []],
            ['method' => 'get', 'uri' => '/pos/sales/' . fake()->uuid() . '/receipt'],
            ['method' => 'post', 'uri' => '/pos/sales/' . fake()->uuid() . '/payments', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/sales/' . fake()->uuid() . '/payments/split', 'payload' => []],
        ];

        foreach ($requests as $request) {
            $response = $this->actingAs($user)
                ->withHeader('X-Tenant-ID', $tenant->id)
                ->withHeader('X-Branch-ID', $branch->id)
                ->{$request['method']}($request['uri'], $request['payload'] ?? []);

            $response->assertStatus(403);
        }
    }

    /** @test */
    public function test_sales_pos_enabled_tenant_is_not_blocked_by_subscription_gate_on_checkout_routes(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $requests = [
            ['method' => 'post', 'uri' => '/pos/checkout/validate', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/checkout/create-sale', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/checkout/status', 'payload' => []],
            ['method' => 'get', 'uri' => '/pos/sales/' . fake()->uuid() . '/receipt'],
            ['method' => 'post', 'uri' => '/pos/sales/' . fake()->uuid() . '/payments', 'payload' => []],
            ['method' => 'post', 'uri' => '/pos/sales/' . fake()->uuid() . '/payments/split', 'payload' => []],
        ];

        foreach ($requests as $request) {
            $response = $this->actingAs($user)
                ->withHeader('X-Tenant-ID', $tenant->id)
                ->withHeader('X-Branch-ID', $branch->id)
                ->{$request['method']}($request['uri'], $request['payload'] ?? []);

            $this->assertNotEquals(403, $response->getStatusCode(), 'Unexpected 403 for ' . strtoupper($request['method']) . ' ' . $request['uri']);
        }
    }

    /** @test */
    public function test_checkout_routes_still_require_create_sale_permission_when_sales_pos_is_enabled(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->branches()->attach($branch->id);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->withHeader('X-Branch-ID', $branch->id)
            ->post('/pos/checkout/validate', []);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_pos_shell_search_active_shift_bootstrap_and_offline_sync_routes_are_gated_by_sales_pos_feature(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => ['sales.pos' => false],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        $user = $this->createOwnerAdminUserForTenant($tenant);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $webRequests = [
            ['method' => 'get', 'uri' => '/pos'],
            ['method' => 'get', 'uri' => '/pos/search?q=te'],
            ['method' => 'get', 'uri' => '/pos/active-shift'],
            ['method' => 'get', 'uri' => '/api/pos/bootstrap-cache'],
        ];

        foreach ($webRequests as $request) {
            $response = $this->actingAs($user)
                ->withHeader('X-Tenant-ID', $tenant->id)
                ->withHeader('X-Branch-ID', $branch->id)
                ->{$request['method']}($request['uri']);

            $response->assertStatus(403);
        }

        $this->assertContains('subscription.feature:sales.pos', Route::getRoutes()->getByName('pos.index')->gatherMiddleware());
        $this->assertContains('subscription.feature:sales.pos', Route::getRoutes()->getByName('pos.search')->gatherMiddleware());
        $this->assertContains('subscription.feature:sales.pos', Route::getRoutes()->getByName('pos.active-shift')->gatherMiddleware());
        $this->assertContains('subscription.feature:sales.pos', Route::getRoutes()->getByName('pos.bootstrap-cache')->gatherMiddleware());
        $this->assertContains('subscription.feature:sales.pos', Route::getRoutes()->getByName('pos.offline-sync')->gatherMiddleware());
    }

    private function createOwnerAdminUserForTenant(Tenant $tenant): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $user->assignRole($adminRole);

        app(TenantContext::class)->clear();

        return $user;
    }

    private function createCatalogFixtures(Tenant $tenant): array
    {
        app(TenantContext::class)->setTenant($tenant);

        $category = ProductCategory::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $category->id,
        ]);
        $ingredient = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $category->id,
        ]);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchPricing = BranchProductPricing::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'selling_price' => 99.99,
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();

        return [
            'category' => $category,
            'product' => $product,
            'ingredient' => $ingredient,
            'branch' => $branch,
            'branchPricing' => $branchPricing,
        ];
    }

    private function catalogEditWriteRequests(array $fixtures): array
    {
        /** @var \App\Models\ProductCategory $category */
        $category = $fixtures['category'];
        /** @var \App\Models\Product $product */
        $product = $fixtures['product'];
        /** @var \App\Models\Product $ingredient */
        $ingredient = $fixtures['ingredient'];
        /** @var \App\Models\Branch $branch */
        $branch = $fixtures['branch'];
        /** @var \App\Models\BranchProductPricing $branchPricing */
        $branchPricing = $fixtures['branchPricing'];

        return [
            [
                'method' => 'post',
                'uri' => '/admin/product-categories',
                'payload' => [
                    'name' => 'New Category',
                    'code' => 'NCAT',
                    'description' => 'Slice A test',
                    'status' => 'active',
                ],
            ],
            [
                'method' => 'put',
                'uri' => "/admin/product-categories/{$category->id}",
                'payload' => [
                    'name' => 'Updated Category',
                    'code' => 'UCAT',
                    'description' => 'Updated',
                    'status' => 'active',
                ],
            ],
            [
                'method' => 'delete',
                'uri' => "/admin/product-categories/{$category->id}",
            ],
            [
                'method' => 'post',
                'uri' => '/admin/products',
                'payload' => [
                    'product_category_id' => $category->id,
                    'name' => 'New Product',
                    'sku' => 'NEW-PROD-001',
                    'unit_of_measure' => 'piece',
                    'selling_price' => 150,
                    'cost_price' => 80,
                    'is_taxable' => true,
                    'is_inventory_tracked' => true,
                    'is_discountable' => true,
                    'status' => 'active',
                    'product_type' => 'finished_good',
                    'is_sellable' => true,
                ],
            ],
            [
                'method' => 'put',
                'uri' => "/admin/products/{$product->id}",
                'payload' => [
                    'product_category_id' => $category->id,
                    'name' => 'Updated Product',
                    'sku' => 'UPD-PROD-001',
                    'unit_of_measure' => 'piece',
                    'selling_price' => 160,
                    'cost_price' => 90,
                    'is_taxable' => true,
                    'is_inventory_tracked' => true,
                    'is_discountable' => true,
                    'status' => 'active',
                    'product_type' => 'finished_good',
                    'is_sellable' => true,
                ],
            ],
            [
                'method' => 'delete',
                'uri' => "/admin/products/{$product->id}",
            ],
            [
                'method' => 'post',
                'uri' => "/admin/products/{$product->id}/branch-pricing",
                'payload' => [
                    'branch_id' => $branch->id,
                    'selling_price' => 175,
                    'is_active' => true,
                ],
            ],
            [
                'method' => 'delete',
                'uri' => "/admin/products/{$product->id}/branch-pricing/{$branchPricing->id}",
            ],
            [
                'method' => 'post',
                'uri' => "/admin/products/{$product->id}/recipe",
                'payload' => [
                    'ingredients' => [
                        [
                            'ingredient_id' => $ingredient->id,
                            'quantity' => 1,
                            'unit' => 'piece',
                        ],
                    ],
                ],
            ],
        ];
    }
}
