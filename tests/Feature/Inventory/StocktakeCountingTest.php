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
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeCountingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $manager;
    protected $counter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Seed roles and permissions
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $managerRole = Role::where('name', 'Branch Manager')->first();
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->assignRole($managerRole);
        $this->manager->branches()->attach($this->branch);

        // Counter role (simulated by assigning only count permission to a new role if needed, 
        // but for now we'll just check specific permissions)
        $this->counter = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->counter->branches()->attach($this->branch);
    }

    public function test_authorized_user_can_view_stocktake_index()
    {
        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.index'));

        $response->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_view_stocktake_index()
    {
        $guest = User::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $response = $this->actingAs($guest)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.index'));

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_create_draft_stocktake_session()
    {
        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.store'), [
                'notes' => 'Test Session'
            ]);

        $this->assertDatabaseHas('stocktake_sessions', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'status' => StocktakeSession::STATUS_DRAFT,
            'notes' => 'Test Session',
        ]);
        
        $session = StocktakeSession::first();
        $response->assertRedirect(route('inventory.stocktakes.show', $session->id));
    }

    public function test_starting_counting_captures_expected_quantities()
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        BranchInventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 50.0000,
        ]);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-TEST',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.start-counting', $session->id));

        $this->assertEquals(StocktakeSession::STATUS_COUNTING, $session->fresh()->status);
        $this->assertDatabaseHas('stocktake_lines', [
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 50.0000,
        ]);

        $line = StocktakeLine::where('stocktake_session_id', $session->id)->first();
        $this->assertEquals('50.0000', $line->expected_quantity_at_count_start);
        $this->assertNotNull($line->count_start_movement_sequence);
        $this->assertNotNull($line->count_start_stock_snapshot_at);
        $this->assertEquals(StocktakeSession::SCOPE_FULL_BRANCH, $session->fresh()->stocktake_scope_type);
    }

    public function test_blind_count_payload_shaping()
    {
        // Define a limited role for counter
        $counterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter',
            'description' => 'Counter Role',
        ]);
        $viewPerm = \App\Models\Permission::where('name', 'inventory.stocktake.view')->first();
        $countPerm = \App\Models\Permission::where('name', 'inventory.stocktake.count')->first();
        $counterRole->permissions()->attach([$viewPerm->id, $countPerm->id]);
        $this->counter->assignRole($counterRole);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-BLIND',
            'status' => StocktakeSession::STATUS_COUNTING,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 100.0000,
            'counted_quantity' => 0,
        ]);

        // Access as Counter (Blind)
        $response = $this->actingAs($this->counter)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.show', $session->id));

        $response->assertStatus(200);
        $linesProp = $response->viewData('page')['props']['lines'];
        $this->assertArrayNotHasKey('expected_quantity', $linesProp[0]);
        $this->assertArrayNotHasKey('variance_quantity', $linesProp[0]);

        // Access as Manager (Non-Blind)
        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.show', $session->id));

        $linesProp = $response->viewData('page')['props']['lines'];
        $this->assertArrayHasKey('expected_quantity', $linesProp[0]);
        $this->assertEquals(100.0000, $linesProp[0]['expected_quantity']);
    }

    public function test_counter_can_save_counted_quantity()
    {
        // Define a limited role for counter
        $counterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter-Only',
            'description' => 'Counter-Only Role',
        ]);
        $viewPerm = \App\Models\Permission::where('name', 'inventory.stocktake.view')->first();
        $countPerm = \App\Models\Permission::where('name', 'inventory.stocktake.count')->first();
        $counterRole->permissions()->attach([$viewPerm->id, $countPerm->id]);
        $this->counter->assignRole($counterRole);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-COUNT',
            'status' => StocktakeSession::STATUS_COUNTING,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 0,
        ]);

        $response = $this->actingAs($this->counter)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->put(route('inventory.stocktakes.lines.update', $session->id), [
                'lines' => [
                    [
                        'id' => $line->id,
                        'counted_quantity' => 8.5000,
                        'remarks' => 'Slightly damaged'
                    ]
                ]
            ]);

        $line->refresh();
        $this->assertEquals(8.5000, $line->counted_quantity);
        $this->assertEquals(-1.5000, $line->variance_quantity);
        $this->assertEquals($this->counter->id, $line->counted_by);
        $this->assertNotNull($line->counted_at);
        $this->assertNotNull($line->count_snapshot_uuid);
        $this->assertNotNull($line->count_recorded_at);
        $this->assertNotNull($line->physically_counted_at);
        $this->assertNotNull($line->counted_movement_sequence);
        $this->assertEquals('10.0000', $line->expected_quantity_at_count_time);
        $this->assertEquals('-1.5000', $line->physical_count_variance_quantity);
    }

    public function test_cross_tenant_access_is_blocked()
    {
        app(TenantContext::class)->clear();
        $tenantB = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenantB);
        
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        
        $sessionB = StocktakeSession::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'stocktake_number' => 'ST-TENANT-B',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $userB->id,
        ]);

        // Manager is from Tenant A
        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.show', $sessionB->id));

        $response->assertStatus(404);
    }
}
