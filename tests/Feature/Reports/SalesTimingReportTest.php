<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesTimingReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $manager;
    protected User $owner;

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
            'name' => 'Express Branch',
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Branch Manager',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->manager->assignToBranch($this->branch);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Owner Admin',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        app(TenantContext::class)->clear();
    }

    public function test_authorized_user_can_view_sales_timing_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->sale([
            'status' => 'paid',
            'gross_sales_amount' => 100,
            'total' => 100,
            'reporting_basis_at' => '2026-05-18 09:15:00',
            'confirmed_at' => '2026-05-18 09:15:00',
            'created_at' => '2026-05-18 09:15:00',
        ]);
        $this->sale([
            'status' => 'paid',
            'gross_sales_amount' => 250,
            'total' => 250,
            'reporting_basis_at' => '2026-05-18 14:30:00',
            'confirmed_at' => '2026-05-18 14:30:00',
            'created_at' => '2026-05-18 14:30:00',
        ]);
        $this->sale([
            'status' => 'paid',
            'gross_sales_amount' => 50,
            'total' => 50,
            'reporting_basis_at' => '2026-05-19 09:45:00',
            'confirmed_at' => '2026-05-19 09:45:00',
            'created_at' => '2026-05-19 09:45:00',
        ]);
        $this->sale([
            'status' => 'created',
            'gross_sales_amount' => 999,
            'total' => 999,
            'reporting_basis_at' => '2026-05-20 20:00:00',
            'confirmed_at' => '2026-05-20 20:00:00',
            'created_at' => '2026-05-20 20:00:00',
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-timing.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/SalesTiming/Index')
            ->where('filters.status', 'paid')
            ->where('summary.peak_sales_hour', '14:00-14:59')
            ->where('summary.peak_sales_weekday', 'Monday')
            ->where('summary.lowest_sales_hour', '09:00-09:59')
            ->where('summary.total_transactions', 3)
            ->where('summary.total_net_sales', 400)
            ->has('hourly_rows', 24)
            ->where('hourly_rows.9.transaction_count', 2)
            ->where('hourly_rows.9.net_sales', 150)
            ->where('hourly_rows.9.average_transaction_value', 75)
            ->where('hourly_rows.14.transaction_count', 1)
            ->where('hourly_rows.14.net_sales', 250)
            ->has('weekday_rows', 7)
            ->where('weekday_rows.0.weekday_label', 'Monday')
            ->where('weekday_rows.0.transaction_count', 2)
            ->where('weekday_rows.0.net_sales', 350)
            ->where('weekday_rows.1.weekday_label', 'Tuesday')
            ->where('weekday_rows.1.transaction_count', 1)
            ->where('meta.can_export', false)
        );
    }

    public function test_unauthorized_user_cannot_view_sales_timing_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->get(route('reports.sales-timing.index'));

        $response->assertForbidden();
    }

    public function test_branch_scoping_fail_closes_for_unassigned_branch_filter(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hidden Branch',
        ]);

        $this->sale([
            'branch_id' => $otherBranch->id,
            'status' => 'paid',
            'total' => 999,
            'reporting_basis_at' => '2026-05-18 10:00:00',
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-timing.index', ['branch_id' => $otherBranch->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_transactions', 0)
            ->where('summary.total_net_sales', 0)
            ->where('hourly_rows.10.transaction_count', 0)
        );
    }

    public function test_status_and_cashier_filters_narrow_timing_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherCashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Other Cashier',
        ]);
        $otherCashier->assignToBranch($this->branch);

        $this->sale([
            'user_id' => $this->manager->id,
            'status' => 'paid',
            'total' => 120,
            'reporting_basis_at' => '2026-05-18 08:00:00',
        ]);
        $this->sale([
            'user_id' => $otherCashier->id,
            'status' => 'paid',
            'total' => 220,
            'reporting_basis_at' => '2026-05-18 12:00:00',
        ]);
        $this->sale([
            'user_id' => $this->manager->id,
            'status' => 'created',
            'total' => 320,
            'reporting_basis_at' => '2026-05-18 15:00:00',
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.sales-timing.index', [
                'cashier_id' => $this->manager->id,
                'status' => 'paid',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_transactions', 1)
            ->where('summary.total_net_sales', 120)
            ->where('hourly_rows.8.transaction_count', 1)
            ->where('hourly_rows.12.transaction_count', 0)
            ->where('hourly_rows.15.transaction_count', 0)
        );
    }

    public function test_export_is_permission_gated_and_contains_timing_sections(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->sale([
            'status' => 'paid',
            'total' => 200,
            'reporting_basis_at' => '2026-05-18 09:00:00',
        ]);

        $managerResponse = $this->actingAs($this->manager)
            ->get(route('reports.sales-timing.export'));
        $managerResponse->assertForbidden();

        $ownerResponse = $this->actingAs($this->owner)
            ->get(route('reports.sales-timing.export'));

        $ownerResponse->assertOk();
        $this->assertStringStartsWith('text/csv', $ownerResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('IPOS Sales by Hour / Weekday Report', $ownerResponse->getContent());
        $this->assertStringContainsString('"Hour Block","Transaction Count","Gross Sales","Net Sales","Average Transaction Value"', $ownerResponse->getContent());
        $this->assertStringContainsString('Weekday,"Transaction Count","Gross Sales","Net Sales","Average Transaction Value"', $ownerResponse->getContent());
        $this->assertStringContainsString('This report summarizes existing sales records only', $ownerResponse->getContent());
    }

    protected function sale(array $overrides = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->manager->id,
            'status' => 'paid',
            'subtotal' => 0,
            'gross_sales_amount' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'reporting_basis_at' => now(),
            'confirmed_at' => now(),
        ], $overrides));
    }
}
