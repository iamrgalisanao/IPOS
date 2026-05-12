<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementExportService;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\Settlement\SettlementSnapshotService;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettlementExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;
    protected SettlementExportService $exportService;
    protected SettlementPeriodService $periodService;
    protected SettlementSnapshotService $snapshotService;

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

        $this->exportService = app(SettlementExportService::class);
        $this->periodService = app(SettlementPeriodService::class);
        $this->snapshotService = app(SettlementSnapshotService::class);

        app(TenantContext::class)->clear();
    }

    public function test_export_summary_to_csv_live_mode(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $csv = $this->exportService->exportSummaryToCsv($period, $this->accountant);

        $this->assertStringContainsString('Settlement Summary Report', $csv);
        $this->assertStringContainsString('live_query', $csv);
        $this->assertStringContainsString($period->id, $csv);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settlement_export_generated',
            'metadata->report_type' => 'summary',
            'metadata->source_mode' => 'live_query',
            'metadata->record_count' => 1,
        ]);
    }

    public function test_export_summary_to_csv_snapshot_mode_when_locked(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        
        // Create a snapshot
        $this->snapshotService->create($period, $this->accountant, SettlementSnapshot::TYPE_REVIEW);
        
        // Lock the period (manually for speed in test)
        $period->update(['status' => SettlementPeriod::STATUS_LOCKED]);

        $csv = $this->exportService->exportSummaryToCsv($period, $this->accountant);

        $this->assertStringContainsString('snapshot', $csv);
        
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settlement_export_generated',
            'metadata->report_type' => 'summary',
            'metadata->source_mode' => 'snapshot',
        ]);
    }

    public function test_export_summary_to_pdf_live_mode(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $pdf = $this->exportService->exportSummaryToPdf($period, $this->accountant);

        // Simple check for PDF header
        $this->assertStringStartsWith('%PDF-', $pdf);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settlement_export_generated',
            'metadata->report_type' => 'summary',
            'metadata->format' => 'pdf',
        ]);
    }


    public function test_export_variances_to_csv(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $csv = $this->exportService->exportVariancesToCsv($period, $this->accountant);

        $this->assertStringContainsString('Category', $csv);
        $this->assertStringContainsString('Source Type', $csv);
        $this->assertStringContainsString('Source ID', $csv);
        
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settlement_export_generated',
            'metadata->report_type' => 'variance',
        ]);
    }

    public function test_export_sync_status_to_csv_with_redaction(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        // Create an outbox record with sensitive data in sync_error
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        DB::table('accounting_outbox')->insert([
            'id' => $outboxId,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => json_encode(['total' => '100.0000']),
            'sync_status' => 'failed',
            'sync_error' => 'Error: access_token=12345secret; refresh_token=abcde; Connection failed.',
            'attempt_count' => 1,
            'available_at' => now(),
            'created_at' => $period->period_start_at->addHour(),
        ]);

        $csv = $this->exportService->exportSyncStatusToCsv($period, $this->accountant);

        $this->assertStringContainsString('Outbox ID', $csv);
        $this->assertStringContainsString('Event Type', $csv);
        $this->assertStringContainsString($outboxId, $csv);
        
        // Redaction Check
        $this->assertStringContainsString('access_token=[redacted]', $csv);
        $this->assertStringContainsString('refresh_token=[redacted]', $csv);
        $this->assertStringNotContainsString('12345secret', $csv);
        $this->assertStringNotContainsString('abcde', $csv);
    }

    public function test_export_denied_without_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
        ]);
        // No roles assigned

        $this->expectException(AuthorizationException::class);
        $this->exportService->exportSummaryToCsv($period, $user);
    }

    public function test_export_denied_due_to_branch_isolation(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        
        // Create a user without multi-branch dashboard permission
        $role = \App\Models\Role::create(['name' => 'Branch Manager Limited', 'description' => 'Limited manager']);
        $role->permissions()->attach(\App\Models\Permission::where('name', 'export_reports')->firstOrFail()->id);
        
        $limitedUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $limitedUser->assignRole($role);
        $limitedUser->assignToBranch($this->branch);

        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $period = $this->createPeriod(['branch_id' => $otherBranch->id]);
        
        // $limitedUser is only assigned to $this->branch
        $this->expectException(AuthorizationException::class);
        $this->exportService->exportSummaryToCsv($period, $limitedUser);
    }


    public function test_export_does_not_mutate_state(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        
        $initialStatus = $period->status;
        $initialUpdatedAt = $period->updated_at;

        // Run all exports
        $this->exportService->exportSummaryToCsv($period, $this->accountant);
        $this->exportService->exportSummaryToPdf($period, $this->accountant);
        $this->exportService->exportVariancesToCsv($period, $this->accountant);
        $this->exportService->exportSyncStatusToCsv($period, $this->accountant);

        $period->refresh();
        $this->assertEquals($initialStatus, $period->status);
        $this->assertEquals($initialUpdatedAt->toDateTimeString(), $period->updated_at->toDateTimeString());
        
        // Ensure no new snapshots created
        $this->assertEquals(0, $period->snapshots()->count());
        
        // Ensure no new outbox records created
        $this->assertEquals(0, AccountingOutbox::count());
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
