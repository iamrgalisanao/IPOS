<?php

namespace Tests\Feature\Reports;

use App\Console\Commands\PruneExpiredDataExports;
use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportRetentionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('exports');
    }

    public function test_expired_exports_are_pruned_and_deleted()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $disk = Storage::disk('exports');
        
        $expiredExport = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'expired-file.txt',
            'file_disk' => 'exports',
            'expires_at' => now()->subDay(),
        ]);
        $disk->put('expired-file.txt', 'expired content');

        $validExport = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'valid-file.txt',
            'file_disk' => 'exports',
            'expires_at' => now()->addDay(),
        ]);
        $disk->put('valid-file.txt', 'valid content');

        Artisan::call(PruneExpiredDataExports::class);

        $expiredExport->refresh();
        $validExport->refresh();

        $this->assertEquals(DataExport::STATUS_EXPIRED, $expiredExport->status);
        $this->assertEquals(DataExport::STATUS_COMPLETED, $validExport->status);

        $disk->assertMissing('expired-file.txt');
        $disk->assertExists('valid-file.txt');
    }
}
