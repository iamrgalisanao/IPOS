<?php

namespace Tests\Feature\Reports;

use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataExportStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('exports');
    }

    public function test_data_export_lifecycle_status_methods()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $export = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->assertTrue($export->isPending());
        $this->assertFalse($export->isProcessing());
        $this->assertFalse($export->isCompleted());
        $this->assertFalse($export->isFailed());
        $this->assertFalse($export->canBeDownloaded());

        $export->update(['status' => DataExport::STATUS_PROCESSING, 'started_at' => now()]);
        $this->assertTrue($export->isProcessing());

        $export->update(['status' => DataExport::STATUS_COMPLETED, 'completed_at' => now(), 'expires_at' => now()->addDays(2)]);
        $this->assertTrue($export->isCompleted());
        $this->assertTrue($export->canBeDownloaded());

        // Test expiry
        Carbon::setTestNow(now()->addDays(3));
        $this->assertFalse($export->canBeDownloaded());
        Carbon::setTestNow();

        $export->update(['status' => DataExport::STATUS_FAILED, 'failed_at' => now(), 'error_message' => 'Test error']);
        $this->assertTrue($export->isFailed());
        $this->assertFalse($export->canBeDownloaded());
    }
}
