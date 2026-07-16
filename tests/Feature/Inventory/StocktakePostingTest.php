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

    public function test_double_posting_is_prevented_when_posted_evidence_is_complete()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POST-DBL',
            'status' => StocktakeSession::STATUS_POSTED, // Already posted
            'started_by' => $this->manager->id,
            'posted_at' => now(),
            'posted_by' => $this->manager->id,
            'posting_evidence_quality' => 'exact',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertRedirect(route('inventory.stocktakes.index'));
        $response->assertSessionHas('success');
    }

    public function test_already_posted_session_with_incomplete_evidence_fails_closed()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-POSTED-INCOMPLETE',
            'status' => StocktakeSession::STATUS_POSTED,
            'started_by' => $this->manager->id,
            'posted_at' => now(),
            'posted_by' => $this->manager->id,
            'posting_evidence_quality' => 'legacy',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertSessionHas('error');
        $this->assertSame('Posted stocktake evidence is incomplete.', session('error'));
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

    public function test_posting_projects_counted_quantity_with_movements_after_count(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10.0000,
        ]);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-PROJECT-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
            'stocktake_operation_mode' => StocktakeSession::MODE_MOVEMENT_AWARE,
            'stocktake_scope_type' => StocktakeSession::SCOPE_SELECTED_PRODUCTS,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10.0000,
            'expected_quantity_at_count_start' => 10.0000,
            'count_start_movement_sequence' => 0,
            'counted_quantity' => 9.0000,
            'variance_quantity' => -1.0000,
            'count_snapshot_uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
            'counted_movement_sequence' => 0,
            'expected_quantity_at_count_time' => 10.0000,
            'physical_count_variance_quantity' => -1.0000,
            'reason_code' => StocktakeLine::REASON_MISCOUNT,
        ]);

        $inventory->update(['current_stock' => 7.0000]);
        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'sale_deduction',
            'quantity_change' => -3.0000,
            'quantity_before' => 10.0000,
            'quantity_after' => 7.0000,
            'source_type' => 'sale',
            'source_id' => 'sale-after-count',
            'source_effect_key' => 'sale-after-count:line:1',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertRedirect(route('inventory.stocktakes.index'));

        $line->refresh();
        $this->assertEquals('-3.0000', $line->movement_after_count_delta);
        $this->assertEquals('6.0000', $line->counted_quantity_projected_to_posting);
        $this->assertEquals('7.0000', $line->expected_quantity_at_posting);
        $this->assertEquals('-1.0000', $line->posted_variance_quantity);
        $this->assertEquals(StocktakeLine::OUTCOME_NEGATIVE_CORRECTION, $line->posting_outcome);
        $this->assertNotNull($line->posting_movement_id);
        $this->assertEquals(6.0000, (float) $inventory->fresh()->current_stock);
    }

    public function test_posting_preview_is_non_mutating_and_reports_projection(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 7.0000,
        ]);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-PREVIEW-001',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10.0000,
            'expected_quantity_at_count_start' => 10.0000,
            'count_start_movement_sequence' => 0,
            'counted_quantity' => 9.0000,
            'variance_quantity' => -1.0000,
            'count_snapshot_uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
            'counted_movement_sequence' => 0,
            'expected_quantity_at_count_time' => 10.0000,
            'physical_count_variance_quantity' => -1.0000,
            'reason_code' => StocktakeLine::REASON_MISCOUNT,
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'sale_deduction',
            'quantity_change' => -3.0000,
            'quantity_before' => 10.0000,
            'quantity_after' => 7.0000,
            'source_type' => 'sale',
            'source_id' => 'sale-preview',
            'source_effect_key' => 'sale-preview:line:1',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.stocktakes.posting-preview', $session->id));

        $response->assertOk()
            ->assertJsonPath('summary.movement_count', 1)
            ->assertJsonPath('lines.0.posted_variance_quantity', '-1.0000')
            ->assertJsonPath('lines.0.movement_after_count_summary.sales', '-3.0000');

        $this->assertEquals(7.0000, (float) $inventory->fresh()->current_stock);
        $this->assertEquals(0, InventoryMovement::where('movement_type', 'stock_correction')->count());
    }

    public function test_posting_rejects_stale_preview_sequence_without_latest_override(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10.0000,
        ]);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-STALE-PREVIEW',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10,
            'counted_quantity' => 10,
            'variance_quantity' => 0,
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 1.0000,
            'quantity_before' => 10.0000,
            'quantity_after' => 11.0000,
            'source_type' => 'stock_in',
            'source_id' => 'stale-preview-stock-in',
            'source_effect_key' => 'stale-preview-stock-in:line:1',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id), [
                'preview_latest_movement_sequence' => 0,
            ]);

        $response->assertSessionHas('error');
        $this->assertSame('STOCKTAKE_PREVIEW_STALE', session('error'));
        $this->assertEquals(StocktakeSession::STATUS_REVIEW, $session->fresh()->status);
    }

    public function test_posting_fails_closed_when_branch_inventory_is_missing(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-MISSING-INV',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 0,
            'counted_quantity' => 1,
            'variance_quantity' => 1,
            'reason_code' => StocktakeLine::REASON_FOUND_STOCK,
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('missing_branch_inventory', session('error'));
        $this->assertEquals(StocktakeSession::STATUS_REVIEW, $session->fresh()->status);
    }

    public function test_zero_posted_variance_preserves_outcome_without_movement(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10.0000,
        ]);

        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-ZERO-POST',
            'status' => StocktakeSession::STATUS_REVIEW,
            'started_by' => $this->manager->id,
        ]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10,
            'counted_quantity' => 10,
            'variance_quantity' => 0,
        ]);

        $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->post(route('inventory.stocktakes.post', $session->id));

        $line->refresh();
        $this->assertEquals(StocktakeLine::OUTCOME_NO_CORRECTION, $line->posting_outcome);
        $this->assertNull($line->posting_movement_id);
        $this->assertEquals(0, InventoryMovement::where('movement_type', 'stock_correction')->count());
    }
}
