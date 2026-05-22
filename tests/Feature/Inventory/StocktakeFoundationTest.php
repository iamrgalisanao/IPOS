<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_stocktake_session_can_be_created_with_tenant_and_branch_scope()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-001',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $this->user->id,
            'started_at' => now(),
        ]);

        $this->assertDatabaseHas('stocktake_sessions', [
            'id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-001',
        ]);
        
        $this->assertTrue($session->isDraft());
        $this->assertFalse($session->isTerminal());
    }

    public function test_stocktake_session_enforces_tenant_isolation()
    {
        app(TenantContext::class)->clear();
        $tenantB = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        
        StocktakeSession::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'stocktake_number' => 'ST-TENANT-B',
            'status' => StocktakeSession::STATUS_DRAFT,
            'started_by' => $userB->id,
        ]);

        // When scoped to Tenant A, we should not see Tenant B's session
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertEquals(0, StocktakeSession::count());
        
        // When scoped to Tenant B, we should see it
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertEquals(1, StocktakeSession::count());
    }

    public function test_stocktake_lines_can_be_attached_to_a_session()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-002',
            'started_by' => $this->user->id,
        ]);

        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 10.5000,
            'counted_quantity' => 9.0000,
            'variance_quantity' => -1.5000,
            'reason_code' => 'DAMAGED',
            'counted_by' => $this->user->id,
        ]);

        $this->assertCount(1, $session->lines);
        $this->assertEquals(10.5000, $session->lines->first()->expected_quantity);
        $this->assertEquals(-1.5000, $session->lines->first()->variance_quantity);
    }

    public function test_quantity_precision_supports_four_decimal_places()
    {
        $session = StocktakeSession::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_number' => 'ST-PRECISION',
            'started_by' => $this->user->id,
        ]);

        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $line = StocktakeLine::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'stocktake_session_id' => $session->id,
            'product_id' => $product->id,
            'expected_quantity' => 123.4567,
            'counted_quantity' => 123.4567,
            'variance_quantity' => 0.0000,
        ]);

        $this->assertEquals('123.4567', (string) $line->fresh()->expected_quantity);
    }

    public function test_lifecycle_helpers_return_correct_values()
    {
        $session = new StocktakeSession();

        $session->status = StocktakeSession::STATUS_DRAFT;
        $this->assertTrue($session->isDraft());
        $this->assertFalse($session->isTerminal());

        $session->status = StocktakeSession::STATUS_COUNTING;
        $this->assertTrue($session->isCounting());
        
        $session->status = StocktakeSession::STATUS_REVIEW;
        $this->assertTrue($session->isInReview());

        $session->status = StocktakeSession::STATUS_POSTED;
        $this->assertTrue($session->isPosted());
        $this->assertTrue($session->isTerminal());
        $this->assertFalse($session->canBeEdited());

        $session->status = StocktakeSession::STATUS_CANCELLED;
        $this->assertTrue($session->isCancelled());
        $this->assertTrue($session->isTerminal());

        $session->status = StocktakeSession::STATUS_REJECTED;
        $this->assertTrue($session->isRejected());
        $this->assertTrue($session->isTerminal());
    }

    public function test_posted_sessions_are_not_editable_by_helper_logic()
    {
        $session = new StocktakeSession(['status' => StocktakeSession::STATUS_POSTED]);
        $this->assertFalse($session->canBeEdited());

        $session->status = StocktakeSession::STATUS_DRAFT;
        $this->assertTrue($session->canBeEdited());
    }

    public function test_inventory_permissions_are_correctly_registered_in_seeder()
    {
        $seeder = new \App\Services\RbacSeeder();
        
        // Reflection to access protected getPermissions
        $reflection = new \ReflectionClass($seeder);
        $method = $reflection->getMethod('getPermissions');
        $method->setAccessible(true);
        $permissions = $method->invoke($seeder);

        $expected = [
            'inventory.stocktake.view',
            'inventory.stocktake.create',
            'inventory.stocktake.count',
            'inventory.stocktake.review',
            'inventory.stocktake.approve',
            'inventory.stocktake.post',
            'inventory.stocktake.cancel',
            'inventory.adjustment.view',
            'inventory.adjustment.create',
            'inventory.adjustment.approve',
        ];

        foreach ($expected as $perm) {
            $this->assertArrayHasKey($perm, $permissions);
        }
    }
}
