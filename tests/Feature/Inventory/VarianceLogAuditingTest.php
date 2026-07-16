<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceCorrectionLink;
use App\Models\InventoryVarianceLog;
use App\Models\InventoryVarianceStatusEvent;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VarianceLogAuditingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $cashier;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        
        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());

        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'PRODA', 'name' => 'Product A']);
        
        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('inventory.reports.variance-logs.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_are_forbidden_from_viewing_variance_logs(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.variance-logs.index'))
            ->assertForbidden();
    }

    public function test_authorized_users_can_view_variance_logs(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.variance-logs.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/VarianceLogs/Index')
            ->has('logs.data')
            ->has('branches')
            ->has('filters')
        );
    }

    public function test_logs_are_immutable_and_cannot_be_updated_or_deleted(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
        ]);

        $log = InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'Sale auto-deduction shortage'
        ]);

        $this->expectException(\RuntimeException::class);
        $log->update(['reason' => 'Malicious update']);

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    public function test_csv_export_mitigates_formula_injection(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
        ]);
        
        // Create logs containing potentially dangerous formula characters
        InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => '=SUM(1,2)'
        ]);
        
        InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => '+dangerous'
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.variance-logs.export'));

        $response->assertOk();
        
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        
        // Assert formula prefix safety quotes are added
        $this->assertStringContainsString("'=SUM(1,2)", $content);
        $this->assertStringContainsString("'+dangerous", $content);
    }

    public function test_csv_export_enforces_tenant_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active', 'name' => 'Other Branch']);
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id, 'sku' => 'PRODX', 'name' => 'Other Product']);
        
        $otherSale = Sale::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
        ]);

        // Create log on other tenant
        InventoryVarianceLog::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'sale_id' => $otherSale->id,
            'ingredient_id' => $otherProduct->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'Other tenant log'
        ]);
        
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.variance-logs.export'));

        $response->assertOk();
        
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        
        // Should not leak other tenant's logs
        $this->assertStringNotContainsString('Other tenant log', $content);
    }

    public function test_lifecycle_acknowledgement_creates_append_only_status_event(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
        ]);

        $log = InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'Sale auto-deduction shortage'
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.variance-logs.acknowledge', $log), [
                'request_uuid' => 'ack-001',
                'reason_code' => 'reviewed',
                'notes' => 'Checked by manager',
            ]);

        $response->assertRedirect();

        $this->assertSame('acknowledged', $log->refresh()->current_status);
        $this->assertDatabaseHas('inventory_variance_status_events', [
            'inventory_variance_log_id' => $log->id,
            'event_type' => 'acknowledged',
            'to_status' => 'acknowledged',
            'request_uuid' => 'ack-001',
        ]);

        $this->expectException(\RuntimeException::class);
        InventoryVarianceStatusEvent::firstOrFail()->update(['notes' => 'tamper']);
    }

    public function test_correction_link_is_append_only_and_does_not_resolve_exception(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $this->product->id,
            'current_stock' => -5.0000,
            'status' => 'active',
        ]);

        $log = InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'ingredient_product_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'Sale auto-deduction shortage'
        ]);

        $movement = InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 3.0000,
            'quantity_before' => -5.0000,
            'quantity_after' => -2.0000,
            'source_type' => 'stock_in',
            'source_id' => 'receipt-001',
            'source_reference' => 'receipt-001',
        ]);

        $secondMovement = InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 2.0000,
            'quantity_before' => -2.0000,
            'quantity_after' => 0.0000,
            'source_type' => 'stock_in',
            'source_id' => 'receipt-002',
            'source_reference' => 'receipt-002',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.variance-logs.link-correction', $log), [
                'inventory_movement_id' => $movement->id,
                'relationship_type' => 'partially_addresses',
                'linked_quantity' => 3.0000,
                'reason_code' => 'receiving_posted',
            ]);

        $response->assertRedirect();

        $this->assertSame('linked_to_correction', $log->refresh()->current_status);
        $this->assertDatabaseHas('inventory_variance_correction_links', [
            'inventory_variance_log_id' => $log->id,
            'inventory_movement_id' => $movement->id,
            'relationship_type' => 'partially_addresses',
        ]);
        $this->assertDatabaseHas('inventory_variance_status_events', [
            'inventory_variance_log_id' => $log->id,
            'event_type' => 'linked_to_correction',
        ]);

        $secondResponse = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('inventory.variance-logs.link-correction', $log), [
                'inventory_movement_id' => $secondMovement->id,
                'relationship_type' => 'addresses',
                'linked_quantity' => 2.0000,
                'reason_code' => 'second_receiving_posted',
            ]);

        $secondResponse->assertRedirect();
        $this->assertSame('linked_to_correction', $log->refresh()->current_status);
        $this->assertDatabaseCount('inventory_variance_correction_links', 2);
        $this->assertDatabaseCount('inventory_variance_status_events', 2);

        $this->expectException(\RuntimeException::class);
        InventoryVarianceCorrectionLink::firstOrFail()->delete();
    }

    public function test_report_filters_by_status_category_and_policy(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
        ]);

        InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 10.0,
            'available_quantity_before' => 5.0,
            'shortage_quantity' => 5.0,
            'resulting_quantity' => -5.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'current_status' => 'acknowledged',
            'reason' => 'Included'
        ]);

        InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'ingredient_id' => $this->product->id,
            'required_quantity' => 2.0,
            'available_quantity_before' => 1.0,
            'shortage_quantity' => 1.0,
            'resulting_quantity' => -1.0,
            'unit' => 'pcs',
            'policy' => 'allow_negative_with_warning',
            'current_status' => 'open',
            'reason' => 'Excluded'
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.variance-logs.index', [
                'status' => 'acknowledged',
                'category' => 'negative_stock',
                'policy' => 'allow_negative_with_warning',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/VarianceLogs/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.reason', 'Included')
        );
    }
}
