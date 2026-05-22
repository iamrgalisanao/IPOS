<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeReportTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $admin;
    protected $counter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($this->branch);
        
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);
        app(BranchContext::class)->setBranch($this->branch);
        
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $cashierRole = Role::where('name', 'Cashier')->first();
        
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole($adminRole);
        $this->admin->branches()->attach($this->branch);

        $this->counter = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->counter->assignRole($cashierRole);
        $this->counter->branches()->attach($this->branch);
        
        // Give counter view and count permission
        $viewPerm = \App\Models\Permission::where('name', 'inventory.stocktake.view')->first();
        $countPerm = \App\Models\Permission::where('name', 'inventory.stocktake.count')->first();
        $this->counter->roles()->first()->permissions()->sync([$viewPerm->id, $countPerm->id]);
    }

    public function test_authorized_user_can_access_summary()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-SUM-001',
            'status' => StocktakeSession::STATUS_POSTED,
            'started_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.summary', $session->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Stocktake/Summary')
            ->has('session')
            ->has('lines')
            ->has('stats')
        );
    }

    public function test_counter_cannot_access_summary_during_active_counting()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-ACTIVE-001',
            'status' => StocktakeSession::STATUS_COUNTING,
            'started_by' => $this->admin->id,
        ]);

        // Counter has view permission but NOT review/post/approve
        $response = $this->actingAs($this->counter)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.summary', $session->id));

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_export_variance_csv()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-CSV-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->admin->id,
        ]);

        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Test Product', 'sku' => 'TS-001']);
        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10,
            'counted_quantity' => 8,
            'variance_quantity' => -2,
            'reason_code' => 'DAMAGED'
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.export.variance-csv', $session->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ipos-stocktake-variance-st-csv-001.csv"');
        
        $content = $response->getContent();
        $this->assertStringContainsString('"Stocktake Number",Branch,Status,"Product Name",SKU', $content);
        $this->assertStringContainsString('"Test Product",TS-001,10.0000,8.0000,-2.0000,DAMAGED', $content);
    }

    public function test_csv_export_excludes_zero_variance_by_default()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-ZERO-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->admin->id,
        ]);

        $p1 = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Variance Product']);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Zero Product']);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $p1->id,
            'expected_quantity' => 10,
            'counted_quantity' => 8,
            'variance_quantity' => -2,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $p2->id,
            'expected_quantity' => 10,
            'counted_quantity' => 10,
            'variance_quantity' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.export.variance-csv', $session->id));

        $content = $response->getContent();
        $this->assertStringContainsString('Variance Product', $content);
        $this->assertStringNotContainsString('Zero Product', $content);
    }

    public function test_cross_tenant_report_access_is_blocked()
    {
        $otherTenant = Tenant::factory()->create();
        
        // Switch to other tenant context to create resources
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        app(BranchContext::class)->setBranch($otherBranch);

        $session = StocktakeSession::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'stocktake_number' => 'ST-CROSS-REPORT',
            'status' => StocktakeSession::STATUS_POSTED,
            'started_by' => $this->admin->id,
        ]);
        
        // Restore original context
        app(TenantContext::class)->setTenant($this->tenant);
        app(BranchContext::class)->setBranch($this->branch);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.summary', $session->id));

        $this->assertTrue(in_array($response->getStatusCode(), [403, 404]));
    }
}
