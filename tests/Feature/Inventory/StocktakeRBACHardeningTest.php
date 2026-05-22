<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeRBACHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $admin;
    protected $counter;
    protected $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $adminRole = Role::where('name', 'Owner/Admin')->first();
        $cashierRole = Role::where('name', 'Cashier')->first();
        
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole($adminRole);
        $this->admin->branches()->attach($this->branch);

        $this->counter = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->counter->assignRole($cashierRole);
        $this->counter->branches()->attach($this->branch);
        
        // Give counter view and count permission for testing
        $viewPerm = \App\Models\Permission::where('name', 'inventory.stocktake.view')->first();
        $countPerm = \App\Models\Permission::where('name', 'inventory.stocktake.count')->first();
        $this->counter->roles()->first()->permissions()->sync([$viewPerm->id, $countPerm->id]);

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // No roles/permissions for viewer initially
    }

    public function test_unauthorized_user_cannot_access_index()
    {
        $response = $this->actingAs($this->viewer)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.index'));

        $response->assertStatus(403);
    }

    public function test_counting_user_cannot_see_expected_quantity_in_payload()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-BLIND-001',
            'status' => StocktakeSession::STATUS_COUNTING,
            'started_by' => $this->admin->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 123.4567,
        ]);

        $response = $this->actingAs($this->counter)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.show', $session->id));

        $response->assertStatus(200);
        $lines = $response->viewData('page')['props']['lines'];
        
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('expected_quantity', $line);
            $this->assertArrayNotHasKey('variance_quantity', $line);
        }
    }

    public function test_terminal_session_cannot_be_mutated()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-TERM-001',
            'status' => StocktakeSession::STATUS_POSTED,
            'started_by' => $this->admin->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->put(route('inventory.stocktakes.lines.update', $session->id), [
                'lines' => [['id' => $line->id, 'counted_quantity' => 5]]
            ]);

        $response->assertStatus(422);
        $this->assertEquals(0, (float) $line->fresh()->counted_quantity);
    }

    public function test_cross_tenant_access_is_blocked()
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        
        $session = StocktakeSession::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'stocktake_number' => 'ST-CROSS-001',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $this->admin->id,
        ]);
        
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->get(route('inventory.stocktakes.show', $session->id));

        $this->assertTrue(in_array($response->getStatusCode(), [403, 404]));
    }

    public function test_audit_logs_are_generated_for_lifecycle_events()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-AUDIT-001',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $this->admin->id,
        ]);

        // Start Counting
        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->post(route('inventory.stocktakes.start-counting', $session->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stocktake_counting_started',
            'auditable_id' => $session->id,
        ]);

        // Submit for Review
        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->post(route('inventory.stocktakes.submit', $session->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stocktake_submitted_for_review',
            'auditable_id' => $session->id,
        ]);

        // Reject
        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->post(route('inventory.stocktakes.reject', $session->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stocktake_rejected',
            'auditable_id' => $session->id,
        ]);
    }

    public function test_cancel_session()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-CANCEL-001',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id, 'X-Branch-ID' => $this->branch->id])
            ->post(route('inventory.stocktakes.cancel', $session->id));

        $response->assertRedirect(route('inventory.stocktakes.index'));
        $this->assertEquals(StocktakeSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stocktake_cancelled',
            'auditable_id' => $session->id,
        ]);
    }
}
