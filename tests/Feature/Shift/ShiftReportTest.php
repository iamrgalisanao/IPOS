<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_authorized_manager_can_view_z_report_with_sensitivity()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        (new \App\Services\RbacSeeder())->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'TEST01',
        ]);
        
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'opening_cash_amount' => 1000.00,
            'expected_cash_amount' => 1000.00,
            'counted_cash_amount' => 1000.00,
            'variance_amount' => 0.00,
        ]);

        $response = $this->actingAs($manager)
            ->withHeaders(['X-Tenant-ID' => $tenant->id, 'X-Branch-ID' => $branch->id])
            ->get(route('shifts.z-report', $shift->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Shift/ZReport')
            ->has('report.reconciliation')
            ->where('can_see_sensitivity', true)
        );
    }

    public function test_cashier_cannot_see_sensitivity_in_z_report()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        (new \App\Services\RbacSeeder())->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'TEST01',
        ]);
        
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        $cashier->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'opening_cash_amount' => 1000.00,
            'expected_cash_amount' => 1000.00,
            'counted_cash_amount' => 1000.00,
            'variance_amount' => 0.00,
        ]);

        $response = $this->actingAs($cashier)
            ->withHeaders(['X-Tenant-ID' => $tenant->id, 'X-Branch-ID' => $branch->id])
            ->get(route('shifts.z-report', $shift->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Shift/ZReport')
            ->where('can_see_sensitivity', false)
            ->missing('report.reconciliation')
        );
    }

    public function test_cross_tenant_access_is_blocked()
    {
        $tenantA = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenantA);
        (new \App\Services\RbacSeeder())->seedForTenant($tenantA);
        app(\App\Services\TenantContext::class)->setTenant($tenantA);
        
        $tenantB = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenantB);
        (new \App\Services\RbacSeeder())->seedForTenant($tenantB);
        app(\App\Services\TenantContext::class)->setTenant($tenantB);

        app(\App\Services\TenantContext::class)->setTenant($tenantA);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA->assignRole(Role::where('name', 'Branch Manager')->first());

        app(\App\Services\TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Test Branch B',
            'branch_code' => 'TEST02',
        ]);
        
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $shiftB = Shift::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'cashier_id' => $userB->id,
            'opened_by' => $userB->id,
            'status' => Shift::STATUS_CLOSED,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
        ]);

        $response = $this->actingAs($userA)
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->get(route('shifts.z-report', $shiftB->id));

        $response->assertStatus(404);
    }

    public function test_report_aggregates_sales_from_payments()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        (new \App\Services\RbacSeeder())->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'TEST01',
        ]);
        
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_CLOSED,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
        ]);

        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => 500.00,
            'total' => 500.00,
            'status' => 'completed',
        ]);

        SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => 500.00,
        ]);

        $response = $this->actingAs($manager)
            ->withHeaders(['X-Tenant-ID' => $tenant->id, 'X-Branch-ID' => $branch->id])
            ->get(route('shifts.z-report', $shift->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('report.sales.gross_sales', '500.0000')
            ->where('report.payments.0.total', '500.0000')
        );
    }

    public function test_report_generation_does_not_mutate_records()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        (new \App\Services\RbacSeeder())->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'TEST01',
        ]);
        
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_CLOSED,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
        ]);

        $originalUpdatedAt = $shift->fresh()->updated_at;

        $this->actingAs($manager)
            ->withHeaders(['X-Tenant-ID' => $tenant->id, 'X-Branch-ID' => $branch->id])
            ->get(route('shifts.z-report', $shift->id));

        $this->assertEquals($originalUpdatedAt->toDateTimeString(), $shift->fresh()->updated_at->toDateTimeString());
    }
}
