<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryVarianceLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
