<?php

namespace Tests\Feature\Epic14;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ComplianceCsvDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $branchManager;
    protected User $cashier;

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
        $this->owner->assignToBranch($this->branchA);

        $this->branchManager = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->branchManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->branchManager->assignToBranch($this->branchA);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branchA);
        
        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_from_csv_export(): void
    {
        $this->get(route('reports.tax.export.csv'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_cannot_access_csv_export(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv'))
            ->assertForbidden();
    }

    public function test_authorized_users_can_download_csv_with_correct_headers(): void
    {
        $dateFrom = '2026-05-12';
        $dateTo = '2026-05-13';

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ipos-tax-compliance-summary-'.$dateFrom.'-to-'.$dateTo.'.csv"');
        
        $content = $response->getContent();
        $this->assertStringContainsString('IPOS Compliance Export', $content);
        $this->assertStringContainsString('Metadata', $content);
        $this->assertStringContainsString('Filters', $content);
        $this->assertStringContainsString('Summary', $content);
        $this->assertStringContainsString('Notes', $content);
        $this->assertStringContainsString('not represent standalone BIR certification', $content);
    }

    public function test_csv_export_enforces_branch_scope(): void
    {
        // Branch manager for Branch A should not be able to export Branch B
        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv', [
                'branch_id' => $this->branchB->id,
            ]))
            ->assertNotFound();

        // Branch manager for Branch A should be able to export Branch A
        $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv', [
                'branch_id' => $this->branchA->id,
            ]))
            ->assertOk();
    }

    public function test_csv_export_preserves_filter_values_in_content(): void
    {
        $dateFrom = '2026-05-01';
        $dateTo = '2026-05-05';
        $branchId = $this->branchA->id;

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'branch_id' => $branchId,
            ]));

        $response->assertOk();
        $content = $response->getContent();
        
        $this->assertStringContainsString('date_from,"'.$dateFrom.' 00:00:00"', $content);
        $this->assertStringContainsString('date_to,"'.$dateTo.' 23:59:59"', $content);
        $this->assertStringContainsString('branch_id,'.$branchId, $content);
    }

    public function test_csv_export_does_not_mutate_database(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $usersBefore = User::count();

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv'))
            ->assertOk();

        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertSame($usersBefore, User::count());
    }

    public function test_csv_export_does_not_leak_sensitive_metadata(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv'));

        $response->assertOk();
        $content = $response->getContent();
        
        $this->assertStringNotContainsString('access_token', $content);
        $this->assertStringNotContainsString('refresh_token', $content);
        $this->assertStringNotContainsString('provider_payload', $content);
    }

    public function test_csv_export_enforces_tenant_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        
        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.tax.export.csv', [
                'branch_id' => $otherBranch->id,
            ]))
            ->assertNotFound();
    }
}
