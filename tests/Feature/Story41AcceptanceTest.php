<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\TaxCategory;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Story41AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->user->assignToBranch($this->branch);
    }

    /** @test */
    public function test_story_4_1_compliance_report(): void
    {
        // 1. POS entry loads active product payloads
        $tax = TaxCategory::create([
            'name' => 'VAT',
            'code' => 'VAT12',
            'tax_type' => 'percentage',
            'rate' => 12.0,
            'tenant_id' => $this->tenant->id
        ]);

        $activeProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => ProductCategory::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Category',
                'code' => 'TCAT'
            ])->id,
            'name' => 'Active Product',
            'sku' => 'SKU001',
            'barcode' => '123456789',
            'unit_of_measure' => 'pc',
            'selling_price' => 100.0,
            'cost_price' => 50.0, // Should be excluded
            'tax_category_id' => $tax->id,
            'status' => 'active'
        ]);

        $inactiveProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $activeProduct->product_category_id,
            'name' => 'Inactive Product',
            'sku' => 'SKU002',
            'selling_price' => 200.0,
            'status' => 'inactive'
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->getJson(route('pos.search', ['q' => 'Product']));

        $response->assertStatus(200);
        $data = $response->json();

        // 14. Inactive products cannot be added through normal product selection (search results)
        $this->assertCount(1, $data);
        $this->assertEquals('Active Product', $data[0]['display_name']);

        $payload = $data[0];

        // 11. Cart item captures required draft snapshot fields
        $this->assertEquals($activeProduct->id, $payload['product_id']);
        $this->assertEquals('Active Product', $payload['display_name']);
        $this->assertEquals('SKU001', $payload['sku']);
        $this->assertEquals('123456789', $payload['barcode']);
        $this->assertEquals('pc', $payload['unit_of_measure']);
        $this->assertEquals(100.0, $payload['selling_price']);
        $this->assertEquals($tax->id, $payload['tax_category_id']);
        $this->assertEquals('percentage', $payload['tax_type']);
        $this->assertEquals(12.0, $payload['tax_rate']);

        // 12. Cart item excludes cost_price
        $this->assertArrayNotHasKey('cost_price', $payload);

        // 13. Cart item excludes accounting/sync metadata
        $this->assertArrayNotHasKey('quickbooks_id', $payload);
        $this->assertArrayNotHasKey('xero_id', $payload);
        $this->assertArrayNotHasKey('accounting_sync_status', $payload);

        // 17, 18, 19. No backend mutations
        if (\Illuminate\Support\Facades\Schema::hasTable('sales')) {
            $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('sales')->count());
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('inventory_movements')) {
            $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('inventory_movements')->count());
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('accounting_outbox')) {
            $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('accounting_outbox')->count());
        }
    }

    /** @test */
    public function test_context_singleton_safety(): void
    {
        // Verify that setting context in one test doesn't leak to another (handled by setUp clearing)
        $this->assertTrue(app(TenantContext::class)->hasTenant());
        $this->assertEquals($this->tenant->id, app(TenantContext::class)->getTenantId());
        
        // Manual clear to simulate end of request
        app(TenantContext::class)->clear();
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }
}
