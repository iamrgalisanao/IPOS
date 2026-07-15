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
use App\Models\InventoryMovement;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakePostingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);
        
        $managerRole = Role::where('name', 'Branch Manager')->first();
        // Ensure the manager has 'inventory.stocktake.post' which is seeded but not in role by default
        $postPerm = \App\Models\Permission::where('name', 'inventory.stocktake.post')->first();
        $managerRole->permissions()->syncWithoutDetaching([$postPerm->id]);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->assignRole($managerRole);
        $this->manager->branches()->attach($this->branch);
    }

    public function test_authorized_user_can_post_reviewed_stocktake()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $product1 = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $inv1 = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'current_stock' => 100.0000,
        ]);

        $product2 = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $inv2 = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'current_stock' => 50.0000,
        ]);

        // Line 1: Negative variance (-5)
        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product1->id,
            'expected_quantity' => 100.0000,
            'counted_quantity' => 95.0000,
            'variance_quantity' => -5.0000,
            'reason_code' => StocktakeLine::REASON_DAMAGED,
        ]);

        // Line 2: Positive variance (+10)
        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product2->id,
            'expected_quantity' => 50.0000,
            'counted_quantity' => 60.0000,
            'variance_quantity' => 10.0000,
            'reason_code' => StocktakeLine::REASON_FOUND_STOCK,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertRedirect(route('inventory.stocktakes.index'));
        $response->assertSessionHas('success');

        $session->refresh();
        $this->assertEquals(StocktakeSession::STATUS_POSTED, $session->status);
        $this->assertNotNull($session->posted_at);
        $this->assertEquals($this->manager->id, $session->posted_by);

        // Verify Inventory
        $this->assertEquals(95.0000, (float) $inv1->fresh()->current_stock);
        $this->assertEquals(60.0000, (float) $inv2->fresh()->current_stock);

        // Verify Movements
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product1->id,
            'quantity_change' => -5.0000,
            'movement_type' => 'stock_correction',
            'source_id' => $session->id,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product2->id,
            'quantity_change' => 10.0000,
            'movement_type' => 'stock_correction',
            'source_id' => $session->id,
        ]);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stocktake_posted',
            'auditable_id' => $session->id,
        ]);
    }

    public function test_posting_fails_if_uncounted_items_exist()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-FAIL-1',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => null, // Uncounted
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertSessionHasErrors(['session']);
        $this->assertEquals(StocktakeSession::STATUS_REVIEW, $session->fresh()->status);
    }

    public function test_posting_fails_if_variance_lines_miss_reasons()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-FAIL-2',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 5.0000,
            'variance_quantity' => -5.0000,
            'reason_code' => null, // Missing reason
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertSessionHasErrors(['session']);
    }

    public function test_double_posting_is_prevented()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-DBL',
            'status' => StocktakeSession::STATUS_POSTED, // Already posted
            'started_by' => $this->manager->id,
            'posted_at' => now(),
            'posted_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Only sessions in \'review\' status can be posted', session('error'));
    }

    public function test_unauthorized_user_cannot_post()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-UNAUTH',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $counter = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $counter->branches()->attach($this->branch);

        $response = $this->actingAs($counter)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertStatus(403);
    }
}
