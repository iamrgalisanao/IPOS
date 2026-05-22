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

class StocktakeReviewTest extends TestCase
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

        $this->counter = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->counter->branches()->attach($this->branch);
        
        $counterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter-Test',
            'description' => 'Test Counter',
        ]);
        $viewPerm = \App\Models\Permission::where('name', 'inventory.stocktake.view')->first();
        $countPerm = \App\Models\Permission::where('name', 'inventory.stocktake.count')->first();
        $counterRole->permissions()->attach([$viewPerm->id, $countPerm->id]);
        $this->counter->assignRole($counterRole);
    }

    public function test_authorized_reviewer_can_access_review_screen()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-REV-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.show', $session->id));

        $response->assertStatus(200);
        $this->assertEquals('Inventory/Stocktake/Review', $response->viewData('page')['component']);
    }

    public function test_counting_only_user_cannot_view_review_payload()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-REV-002',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 8.0000,
            'variance_quantity' => -2.0000,
        ]);

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
    }

    public function test_session_with_uncounted_items_cannot_be_submitted_for_review()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-SUB-001',
            'status' => StocktakeSession::STATUS_COUNTING,
            'started_by' => $this->manager->id,
        ]);

        // Line with null counted_quantity
        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => null,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.submit', $session->id));

        $response->assertSessionHas('error');
        $this->assertEquals(StocktakeSession::STATUS_COUNTING, $session->fresh()->status);
    }

    public function test_reviewer_can_update_variance_reasons()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-REASON-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 5.0000,
            'variance_quantity' => -5.0000,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->put(route('inventory.stocktakes.variance-reasons.update', $session->id), [
                'lines' => [
                    [
                        'id' => $line->id,
                        'reason_code' => StocktakeLine::REASON_DAMAGED,
                        'remarks' => 'Found broken in box'
                    ]
                ]
            ]);

        $response->assertSessionHas('success');
        $this->assertEquals(StocktakeLine::REASON_DAMAGED, $line->fresh()->reason_code);
    }

    public function test_other_reason_requires_remarks()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-REASON-002',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 5.0000,
            'variance_quantity' => -5.0000,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->put(route('inventory.stocktakes.variance-reasons.update', $session->id), [
                'lines' => [
                    [
                        'id' => $line->id,
                        'reason_code' => StocktakeLine::REASON_OTHER,
                        'remarks' => '' // Empty remarks
                    ]
                ]
            ]);

        $response->assertSessionHasErrors(['lines.' . $line->id . '.remarks']);
    }

    public function test_reviewer_can_reject_session()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-REJ-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.reject', $session->id));

        $session->refresh();
        $this->assertEquals(StocktakeSession::STATUS_REJECTED, $session->status);
        $this->assertNotNull($session->rejected_at);
        $this->assertEquals($this->manager->id, $session->reviewed_by);
    }

    public function test_unauthorized_user_cannot_update_reasons()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-UNAUTH-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'expected_quantity' => 10.0000,
            'counted_quantity' => 5.0000,
            'variance_quantity' => -5.0000,
        ]);

        $response = $this->actingAs($this->counter)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->put(route('inventory.stocktakes.variance-reasons.update', $session->id), [
                'lines' => [
                    [
                        'id' => $line->id,
                        'reason_code' => StocktakeLine::REASON_DAMAGED,
                    ]
                ]
            ]);

        $response->assertStatus(403);
    }
}
