<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineBootstrapCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private ProductCategory $category;
    private TaxCategory $vatableCategory;
    private Product $product;
    private SalesMachineProfile $machineProfile;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active', 'tax_mode' => 'inclusive']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        // Seed Sales Machine Profile
        $this->machineProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN-POS',
            'machine_identification_number' => 'MIN-001',
            'machine_serial_number' => 'SER-001',
            'software_license_number' => 'LIC-001',
            'permit_to_use_number' => 'PTU-001',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name' => 'Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_accreditation_number' => 'ACC-001',
            'status' => 'active',
        ]);

        // Seed Tax Category
        $this->vatableCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VAT12',
            'name'      => 'VAT 12%',
            'tax_type'  => 'VATable',
            'rate'      => 12.00,
        ]);

        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Coffee', 
            'code' => 'COF',
            'status' => 'active'
        ]);

        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'tax_category_id'      => $this->vatableCategory->id,
            'name'                 => 'Americano (VAT)',
            'sku'                  => 'AMR-VAT',
            'barcode'              => '100001',
            'unit_of_measure'      => 'cup',
            'selling_price'        => 100.00,
            'cost_price'           => 30.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    public function test_bootstrap_cache_returns_expected_payload_keys_and_values(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('pos.bootstrap-cache'));

        $response->assertStatus(200);

        $data = $response->json();

        // Validate structural keys
        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('tax_categories', $data);
        $this->assertArrayHasKey('tenant_context', $data);
        $this->assertArrayHasKey('branch_context', $data);
        $this->assertArrayHasKey('machine_profile_context', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertArrayHasKey('tax_configuration_version_hash', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('cache_ttl_seconds', $data);

        // Validate values
        $this->assertCount(1, $data['products']);
        $this->assertEquals('Americano (VAT)', $data['products'][0]['display_name']);
        $this->assertCount(1, $data['categories']);
        $this->assertEquals('Coffee', $data['categories'][0]['name']);
        $this->assertCount(1, $data['tax_categories']);
        $this->assertEquals('VAT 12%', $data['tax_categories'][0]['name']);
        $this->assertEquals('inclusive', $data['tenant_context']['tax_mode']);
        $this->assertEquals('MAIN-POS', $data['machine_profile_context']['profile_code']);
        $this->assertContains('create_sale', $data['permissions']);
    }

    public function test_bootstrap_cache_returns_403_on_inactive_branch(): void
    {
        $this->branch->update(['status' => 'inactive']);

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('pos.bootstrap-cache'));

        $response->assertStatus(403);
    }

    public function test_tax_configuration_version_hash_reacts_to_updates(): void
    {
        $service = app(CacheBootstrapService::class);
        $hash1 = $service->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        // Modify the tax category rate
        $this->vatableCategory->update(['rate' => 15.00]);

        $hash2 = $service->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        $this->assertNotEquals($hash1, $hash2);

        // Modify the tenant tax settings
        $this->tenant->update(['tax_mode' => 'exclusive']);

        $hash3 = $service->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        $this->assertNotEquals($hash2, $hash3);
    }

    public function test_checkout_validation_rejects_stale_or_missing_hash(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];

        // 1. Missing hash in headers or body -> Should fail with 409
        $responseMissing = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Enforce-Tax-Hash-Check', 'true')
            ->postJson(route('pos.checkout.validate'), $payload);

        $responseMissing->assertStatus(409);
        $responseMissing->assertJson([
            'error' => 'STALE_TAX_CONFIG',
            'message' => 'Your tax and pricing rules are outdated. Synchronizing cache...',
        ]);

        // 2. Incorrect hash -> Should fail with 409
        $responseStale = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Tax-Config-Hash', 'wrong-stale-hash')
            ->postJson(route('pos.checkout.validate'), $payload);

        $responseStale->assertStatus(409);

        // 3. Correct hash -> Should succeed with 200
        $correctHash = app(CacheBootstrapService::class)->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        $responseCorrect = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Tax-Config-Hash', $correctHash)
            ->postJson(route('pos.checkout.validate'), $payload);

        $responseCorrect->assertStatus(200);
    }

    public function test_create_sale_rejects_stale_or_missing_hash(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];

        // 1. Missing hash -> Should fail with 409
        $responseMissing = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Enforce-Tax-Hash-Check', 'true')
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $responseMissing->assertStatus(409);

        // 2. Incorrect hash -> Should fail with 409
        $responseStale = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Tax-Config-Hash', 'wrong-stale-hash')
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $responseStale->assertStatus(409);

        // 3. Correct hash -> Should succeed with 200
        $correctHash = app(CacheBootstrapService::class)->calculateTaxConfigHash($this->tenant->id, $this->branch->id);

        $responseCorrect = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Tax-Config-Hash', $correctHash)
            ->postJson(route('pos.checkout.create-sale'), $payload);

        $responseCorrect->assertStatus(200);
    }
}
