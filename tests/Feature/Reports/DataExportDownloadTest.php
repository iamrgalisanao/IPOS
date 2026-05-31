<?php

namespace Tests\Feature\Reports;

use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('exports');
    }

    public function test_user_can_download_their_own_completed_export()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Storage::disk('exports')->put('test-export.txt', 'test content');

        $export = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'test-export.txt',
            'file_disk' => 'exports',
            'completed_at' => now(),
            'expires_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($user)->get("/reports/exports/{$export->id}/download");

        $response->assertStatus(200);
        $response->assertDownload('test-export.txt');
        
        $export->refresh();
        $this->assertEquals(1, $export->download_count);
        $this->assertNotNull($export->downloaded_at);
    }

    public function test_user_cannot_download_expired_export()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $export = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'test-export.txt',
            'file_disk' => 'exports',
            'completed_at' => now()->subDays(3),
            'expires_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user)->get("/reports/exports/{$export->id}/download");

        $response->assertStatus(404);
    }

    public function test_user_cannot_download_other_tenant_export()
    {
        $tenant1 = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant1);
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);

        $tenant2 = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant2);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        Storage::disk('exports')->put('test-export.txt', 'test content');

        $export = DataExport::create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user2->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'test-export.txt',
            'file_disk' => 'exports',
            'completed_at' => now(),
            'expires_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($user1)->get("/reports/exports/{$export->id}/download");

        $response->assertStatus(404);
    }
}
