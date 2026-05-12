<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Product $dummyProduct;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Original Tenant',
            'business_registration_number' => 'TIN-12345',
            'receipt_header' => 'Header Text',
            'receipt_footer' => 'Footer Text',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Branch',
            'branch_code' => 'BR-001',
            'address' => '123 Street, City',
            'contact_number' => '09123456789',
            'status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        
        // Setup permissions via a Role
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
        ]);
        
        $permission = Permission::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'create_sale',
        ]);
        
        $role->permissions()->attach($permission);
        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

        $category = ProductCategory::create(['name' => 'General', 'code' => 'GEN']);
        $this->dummyProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name'      => 'Dummy Product',
            'sku'       => 'DUMMY-1',
            'selling_price' => 100,
            'status'    => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    private function getWithContext(string $route, array $params = [], ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $actor = $user ?? $this->user;
        return $this->actingAs($actor)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route($route, $params));
    }

    /**
     * AC 1, 2, 6, 7, 8, 9, 10, 11
     */
    public function test_it_includes_tenant_and_branch_details(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
            'status'              => 'completed',
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $response->assertStatus(200);
        $response->assertJson([
            'tenant' => [
                'business_name' => 'Original Tenant',
                'business_registration_number' => 'TIN-12345',
                'receipt_header' => 'Header Text',
                'receipt_footer' => 'Footer Text',
            ],
            'branch' => [
                'branch_name' => 'Main Branch',
                'branch_code' => 'BR-001',
                'branch_address' => '123 Street, City',
                'branch_contact_number' => '09123456789',
            ]
        ]);
    }

    /**
     * AC 2, 3, 4, 5
     */
    public function test_it_uses_immutable_item_snapshots_ignoring_catalog_changes(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'status'              => 'completed',
            'total'               => 112,
        ]);

        $saleItem = SaleItem::create([
            'tenant_id'    => $this->tenant->id,
            'branch_id'    => $this->branch->id,
            'sale_id'      => $sale->id,
            'product_id'   => $this->dummyProduct->id,
            'product_name' => 'Snapshot Name',
            'unit_price'   => 100.00,
            'line_total'   => 112.00,
            'quantity'     => 1,
            'subtotal'     => 100.00,
            'tax_amount'   => 12.00,
            'tax_rate'     => 12.00,
            'tax_type'     => 'exclusive',
        ]);

        // Change catalog product
        $this->dummyProduct->update([
            'name' => 'New Catalog Name',
            'selling_price' => 200.00,
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $response->assertStatus(200);
        $response->assertJson([
            'items' => [
                [
                    'product_name' => 'Snapshot Name',
                    'unit_price'   => 100.00,
                    'line_total'   => 112.00,
                    'tax_amount'   => 12.00,
                ]
            ]
        ]);
    }

    /**
     * AC 12
     */
    public function test_it_includes_financial_summary(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'subtotal'            => 1000.00,
            'discount_total'      => 50.00,
            'tax_total'           => 114.00,
            'total'               => 1064.00,
            'status'              => 'completed',
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $response->assertJson([
            'totals' => [
                'subtotal'       => 1000.00,
                'discount_total' => 50.00,
                'tax_total'      => 114.00,
                'total'          => 1064.00,
            ]
        ]);
    }

    /**
     * AC 13
     */
    public function test_it_uses_uuid_as_fallback_reference(): void
    {
        $uuid = (string) Str::uuid();
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'sale_number'         => null,
            'client_request_uuid' => $uuid,
            'status'              => 'completed',
            'total'               => 0,
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);
        $response->assertJsonPath('receipt_reference', $uuid);
    }

    /**
     * AC 14, 15
     */
    public function test_it_sanitizes_payload_excluding_sensitive_metadata(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $response->assertJsonMissing(['cost_price']);
        $response->assertJsonMissing(['accounting_sync_status']);
        $response->assertJsonMissing(['reconciliation_id']);
        $response->assertJsonMissing(['outbox_status']);
    }

    /**
     * AC 16, 17
     */
    public function test_it_enforces_strict_isolation(): void
    {
        // Setup other tenant
        app(TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherSale = Sale::create([
            'tenant_id'           => $otherTenant->id,
            'branch_id'           => $otherBranch->id,
            'user_id'             => $otherUser->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
        ]);

        // Revert to original user context
        app(TenantContext::class)->setTenant($this->tenant);
        
        // Attempt to access other tenant's sale
        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $otherSale->id]);
        $response->assertStatus(404);

        // Attempt to access same tenant but different branch
        $otherBranchSameTenant = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        
        // Switch to that branch context to create the sale
        app(BranchContext::class)->setBranch($otherBranchSameTenant);
        $saleInOtherBranch = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $otherBranchSameTenant->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
        ]);
        
        // Revert to original branch context
        app(BranchContext::class)->setBranch($this->branch);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $saleInOtherBranch->id]);
        $response->assertStatus(404);
    }

    /**
     * AC 18
     */
    public function test_it_rejects_unauthorized_users(): void
    {
        $unprivilegedUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        // No role/permission assigned
        
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id], $unprivilegedUser);
        $response->assertStatus(403);
    }

    /**
     * AC 19, 20, 21
     */
    public function test_it_is_mutation_silent(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 0,
        ]);

        // Count sensitive tables
        $counts = [
            'sales' => \DB::table('sales')->count(),
            'sale_items' => \DB::table('sale_items')->count(),
        ];

        $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $this->assertEquals($counts['sales'], \DB::table('sales')->count());
        $this->assertEquals($counts['sale_items'], \DB::table('sale_items')->count());
        
        if (\Schema::hasTable('payments')) {
            $this->assertEquals(0, \DB::table('payments')->count());
        }
        if (\Schema::hasTable('inventory_movements')) {
            $this->assertEquals(0, \DB::table('inventory_movements')->count());
        }
        if (\Schema::hasTable('accounting_outbox')) {
            $this->assertEquals(0, \DB::table('accounting_outbox')->count());
        }
    }

    /**
     * AC for Story 5.5: Receipt includes payment records
     */
    public function test_it_includes_payment_records_in_payload(): void
    {
        $sale = Sale::create([
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->user->id,
            'client_request_uuid' => (string) Str::uuid(),
            'total'               => 500,
            'status'              => 'paid',
        ]);

        $cashMethod = \App\Models\PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'cash',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);

        \App\Models\SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $cashMethod->id,
            'payment_type' => 'cash',
            'amount' => 500,
            'status' => 'recorded',
        ]);

        $response = $this->getWithContext('pos.sales.receipt', ['sale_id' => $sale->id]);

        $response->assertStatus(200);
        $response->assertJson([
            'totals' => [
                'total_paid' => 500.0,
            ],
            'payments' => [
                [
                    'method_name' => 'Cash',
                    'amount' => 500.0,
                ]
            ]
        ]);
    }
}
