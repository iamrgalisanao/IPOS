<?php

namespace Tests\Feature\Settlement;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;
    protected SettlementPeriodService $periodService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branch);

        $this->periodService = app(SettlementPeriodService::class);

        app(TenantContext::class)->clear();
    }

    public function test_export_summary_csv_route(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $response = $this->actingAs($this->accountant)
            ->get(route('settlement.periods.export.summary.csv', $period->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="settlement-summary-' . $period->id . '-' . now()->format('Ymd-His') . '.csv"');
    }

    public function test_export_summary_pdf_route(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $response = $this->actingAs($this->accountant)
            ->get(route('settlement.periods.export.summary.pdf', $period->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_variance_csv_route(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $response = $this->actingAs($this->accountant)
            ->get(route('settlement.periods.export.variance.csv', $period->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_sync_status_csv_route(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $response = $this->actingAs($this->accountant)
            ->get(route('settlement.periods.export.sync-status.csv', $period->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_route_protection(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        
        $unauthorizedUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($unauthorizedUser)
            ->get(route('settlement.periods.export.summary.csv', $period->id));

        $response->assertStatus(403);
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        return $this->periodService->create(array_merge([
            'branch_id' => $this->branch->id,
            'period_start_at' => now()->subDay()->startOfDay(),
            'period_end_at' => now()->subDay()->endOfDay(),
        ], $overrides), $this->accountant);
    }
}
