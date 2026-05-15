<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesHistoryExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RBAC seeding is typically handled by DatabaseSeeder or a specific trait
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    /** @test */
    public function authorized_user_can_export_csv()
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner'); // Has export_sales_history

        Sale::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'status' => 'paid',
            'subtotal' => 100,
            'tax_total' => 12,
            'total' => 112
        ]);

        $response = $this->actingAs($user)->get(route('sales.history.export'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv');
        $this->assertStringContainsString('Sale Number,Isolation ID', $response->getContent());
        $this->assertStringContainsString('112.00', $response->getContent());
        
        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'transaction_history_exported',
            'actor_user_id' => $user->id
        ]);
    }

    /** @test */
    public function unauthorized_user_is_blocked_from_export()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // No roles assigned

        $response = $this->actingAs($user)->get(route('sales.history.export'));

        $response->assertStatus(403);
    }

    /** @test */
    public function tenant_isolation_is_enforced_on_export()
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA->assignRole('Owner');

        Sale::factory()->create(['tenant_id' => $tenantA->id, 'sale_number' => 'SALE-A']);
        Sale::factory()->create(['tenant_id' => $tenantB->id, 'sale_number' => 'SALE-B']);

        $response = $this->actingAs($userA)->get(route('sales.history.export'));

        $response->assertStatus(200);
        $this->assertStringContainsString('SALE-A', $response->getContent());
        $this->assertStringNotContainsString('SALE-B', $response->getContent());
    }

    /** @test */
    public function branch_scoping_is_enforced_on_export()
    {
        $tenant = Tenant::factory()->create();
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->branches()->attach($branch1);
        $user->assignRole('Branch Manager'); // Assume Branch Manager has view_sales_history but not export_sales_history by default in my plan
        
        // For this test, let's give the user the export permission manually
        $user->givePermissionTo('export_sales_history');

        Sale::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch1->id, 'sale_number' => 'BRANCH-1']);
        Sale::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch2->id, 'sale_number' => 'BRANCH-2']);

        $response = $this->actingAs($user)->get(route('sales.history.export'));

        $response->assertStatus(200);
        $this->assertStringContainsString('BRANCH-1', $response->getContent());
        $this->assertStringNotContainsString('BRANCH-2', $response->getContent());
    }

    /** @test */
    public function csv_formula_injection_protection_works()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        Sale::factory()->create([
            'tenant_id' => $tenant->id,
            'sale_number' => '=SUM(A1:A10)'
        ]);

        $response = $this->actingAs($user)->get(route('sales.history.export'));

        $response->assertStatus(200);
        $this->assertStringContainsString("'=SUM(A1:A10)", $response->getContent());
    }

    /** @test */
    public function export_preserves_filters()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        Sale::factory()->create(['tenant_id' => $tenant->id, 'status' => 'paid', 'sale_number' => 'PAID-01']);
        Sale::factory()->create(['tenant_id' => $tenant->id, 'status' => 'voided', 'sale_number' => 'VOID-01']);

        $response = $this->actingAs($user)->get(route('sales.history.export', ['status' => 'paid']));

        $response->assertStatus(200);
        $this->assertStringContainsString('PAID-01', $response->getContent());
        $this->assertStringNotContainsString('VOID-01', $response->getContent());
    }
}
