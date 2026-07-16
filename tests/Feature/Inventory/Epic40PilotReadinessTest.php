<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceLog;
use App\Models\Permission;
use App\Models\PosAdjustmentRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Epic40PilotReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $owner;
    private Product $product;
    private BranchInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Epic 40 Pilot Branch',
        ]);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->branches()->attach($this->branch->id);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Pilot Flour',
            'sku' => 'PILOT-FLOUR',
            'unit_of_measure' => 'kg',
            'is_inventory_tracked' => true,
            'status' => 'active',
        ]);
        $this->inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 10,
            'inventory_revision' => 1,
            'reorder_level' => 2,
            'average_cost' => 1,
            'status' => 'active',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $this->inventory->id,
            'movement_sequence' => 1,
            'movement_type' => 'opening_balance',
            'quantity_change' => 10,
            'quantity_before' => 0,
            'quantity_after' => 10,
            'business_date' => '2026-07-16',
            'posted_at' => now(),
            'source_type' => 'pilot_readiness_fixture',
            'source_id' => 'epic-40-readiness',
            'source_effect_key' => 'pilot-readiness:opening',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_epic_40_pilot_readiness_documents_are_present_and_governance_complete(): void
    {
        $documents = [
            'docs/user-enablement/pilot-enablement-pack-overview.md' => ['Pilot Governance Summary'],
            'docs/user-enablement/inventory-pilot-branch-walkthrough-run-sheet.md' => ['Walkthrough Sequence'],
            'docs/user-enablement/inventory-pilot-checklist-addendum.md' => ['Pilot Entry Checklist', 'Hypercare Daily Checklist'],
            'docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md' => ['Containment Modes', 'Forbidden Shortcuts'],
            'docs/user-enablement/inventory-pilot-branch-manager-demo-script.md' => ['Sale, Recipe, Offline Sync, and Replay'],
            'docs/user-enablement/inventory-pilot-screenshot-capture-pack.md' => ['Evidence Manifest Template'],
            'docs/user-guide/04-module-guides/inventory.md' => ['Offline Boundary', 'Recovery Rules'],
            'docs/validation/epic-40-pilot-uat-readiness.md' => ['UAT Scenario Matrix', 'Go/No-Go Criteria', 'Hypercare'],
        ];

        foreach ($documents as $path => $requiredHeadings) {
            $fullPath = base_path($path);

            $this->assertFileExists($fullPath, "Missing pilot readiness document: {$path}");

            $contents = file_get_contents($fullPath);
            foreach ($requiredHeadings as $heading) {
                $this->assertStringContainsString($heading, $contents, "Missing heading [{$heading}] in {$path}");
            }
        }

        $this->assertFileDoesNotExist(base_path('docs/user-enablement/inventory-pilot-escalation-and-rollback-notes.md'));
    }

    public function test_pilot_readiness_report_surfaces_do_not_mutate_inventory_evidence(): void
    {
        $before = $this->inventoryEvidenceCounts();

        $routes = [
            route('inventory.hub.index'),
            route('inventory.reports.current-stock.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.stock-card.index', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
            ]),
            route('inventory.reports.movement-summary.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.negative-stock-exceptions.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.physical-count-variance.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.reconciliation-exceptions.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.usage-reconciliation.index', ['branch_id' => $this->branch->id]),
            route('inventory.reports.integrity.index', ['branch_id' => $this->branch->id]),
        ];

        foreach ($routes as $route) {
            $this->actingAs($this->owner)
                ->withHeaders([
                    'X-Tenant-ID' => $this->tenant->id,
                    'X-Branch-ID' => $this->branch->id,
                ])
                ->get($route)
                ->assertOk();
        }

        $this->assertSame($before, $this->inventoryEvidenceCounts());
    }

    public function test_audit_exports_are_permission_gated_without_inventory_mutation(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pilot Report Viewer',
            'description' => 'Can view inventory reports but cannot export audit reports',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $before = $this->inventoryEvidenceCounts();

        $this->actingAs($viewer)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.reports.integrity.export', ['branch_id' => $this->branch->id]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.reports.current-stock.export', ['branch_id' => $this->branch->id]))
            ->assertOk();

        $this->assertSame($before, $this->inventoryEvidenceCounts());
    }

    private function inventoryEvidenceCounts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $counts = [
            'branch_inventories' => BranchInventory::count(),
            'inventory_movements' => InventoryMovement::count(),
            'inventory_variance_logs' => InventoryVarianceLog::count(),
            'stocktake_sessions' => StocktakeSession::count(),
            'stocktake_lines' => StocktakeLine::count(),
            'pos_adjustment_requests' => PosAdjustmentRequest::count(),
        ];

        app(TenantContext::class)->clear();

        return $counts;
    }
}
