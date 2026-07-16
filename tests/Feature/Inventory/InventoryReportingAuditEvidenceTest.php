<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryReportingAuditEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;
    protected Product $product;
    protected BranchInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Main Branch',
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
            'name' => 'Flour',
            'sku' => 'FLOUR',
            'unit_of_measure' => 'kg',
            'is_inventory_tracked' => true,
            'status' => 'active',
        ]);
        $this->inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 13,
            'inventory_revision' => 4,
            'reorder_level' => 5,
            'average_cost' => 1,
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_current_stock_report_exposes_revision_and_watermark_metadata(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->movement(1, 'opening_balance', 10, 0, 10, '2026-07-01');
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.current-stock.index', ['branch_id' => $this->branch->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Reports/Index')
            ->where('reportKey', 'current-stock')
            ->where('rows.0.inventory_revision', 4)
            ->where('rows.0.latest_movement_sequence', 1)
            ->where('meta.consistency_level', 'best_effort')
            ->where('meta.historical_as_of_supported', false)
        );
    }

    public function test_movement_summary_uses_business_date_safe_opening_from_baseline_and_watermark(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->movement(1, 'opening_balance', 10, 0, 10, '2026-07-01');
        $this->movement(2, 'sale_deduction', -2, 10, 8, '2026-07-10');
        $this->movement(3, 'stock_in', 5, 8, 13, '2026-07-08');
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.movement-summary.index', [
                'branch_id' => $this->branch->id,
                'date_from' => '2026-07-09',
                'date_to' => '2026-07-10',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.opening_stock', 15)
            ->where('rows.0.stock_out', 2)
            ->where('rows.0.movement_derived_closing_stock', 13)
            ->where('rows.0.summary_calculation_basis', 'business_date_activity')
            ->where('rows.0.ledger_as_of_sequence', 3)
            ->where('meta.consistency_level', 'sequence_bounded')
        );
    }

    public function test_stock_card_requires_filters_then_returns_sequence_bounded_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->movement(1, 'opening_balance', 10, 0, 10, '2026-07-01');
        $this->movement(2, 'manual_adjustment', -1, 10, 9, '2026-07-02');
        app(TenantContext::class)->clear();

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.stock-card.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.required_filters', 'branch_id and product_id')
            );

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.stock-card.index', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.movement_sequence', 2)
            ->where('rows.0.movement_category', 'adjustment_out')
            ->where('rows.1.movement_sequence', 1)
            ->where('meta.data_as_of_movement_sequence', 2)
        );
    }

    public function test_csv_export_preserves_negative_numbers_and_escapes_formula_text(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->product->update(['name' => '-cmd|calc']);
        $this->inventory->update(['current_stock' => -5]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.current-stock.export', ['branch_id' => $this->branch->id]));

        $response->assertOk();
        $csv = $response->getContent();
        $this->assertStringContainsString("'-cmd|calc", $csv);
        $this->assertStringContainsString(',-5,', $csv);
    }

    public function test_audit_report_export_requires_audit_permission_and_logs_successful_export(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Report Viewer',
            'description' => 'Can view but not export audit reports',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.integrity.export', ['branch_id' => $this->branch->id]))
            ->assertForbidden();

        app(TenantContext::class)->setTenant($this->tenant);
        AuditLog::query()->delete();
        app(TenantContext::class)->clear();

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.integrity.export', ['branch_id' => $this->branch->id]))
            ->assertOk();

        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'INVENTORY_REPORT_EXPORTED',
        ]);
        $log = AuditLog::query()->where('action', 'INVENTORY_REPORT_EXPORTED')->firstOrFail();
        $this->assertSame('integrity', $log->after_values['report_type']);
        $this->assertSame($this->owner->id, $log->after_values['user_id']);
        app(TenantContext::class)->clear();
    }

    public function test_branch_limited_user_cannot_report_on_unassigned_branch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Report Viewer',
            'description' => 'Branch scoped report viewer',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.current-stock.index', ['branch_id' => $otherBranch->id]))
            ->assertForbidden();
    }

    private function movement(int $sequence, string $type, float $change, float $before, float $after, string $businessDate): InventoryMovement
    {
        return InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $this->inventory->id,
            'movement_sequence' => $sequence,
            'movement_type' => $type,
            'quantity_change' => $change,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'business_date' => $businessDate,
            'posted_at' => now()->addMinutes($sequence),
            'source_type' => 'test',
            'source_id' => 'movement-'.$sequence,
            'source_effect_key' => 'test:movement-'.$sequence,
        ]);
    }
}
