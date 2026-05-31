<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\ProcessDataExportJob;
use App\Models\DataExport;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\EJournalExportService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AsyncEJournalExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('exports');
    }

    public function test_process_data_export_job_generates_ejournal_file()
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenant);
        
        $branch = \App\Models\Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->branches()->attach($branch);

        // Create some sales
        Sale::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'invoice_issued_at' => now(),
        ]);

        $export = DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_PENDING,
            'parameters' => [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->toDateString(),
            ],
            'requested_at' => now(),
        ]);

        $job = new ProcessDataExportJob($export);
        $job->handle(app(EJournalExportService::class));

        $export->refresh();

        $this->assertEquals(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertNotNull($export->checksum);
        $this->assertNotNull($export->file_size);
        $this->assertEquals('exports', $export->file_disk);

        // Verify file exists
        Storage::disk('exports')->assertExists($export->file_path);

        // Verify contents
        $content = Storage::disk('exports')->get($export->file_path);
        $this->assertStringContainsString('Timestamp|Record Type', $content);
        $this->assertStringContainsString('SALE', $content);
        
        // Ensure hashes are appended
        $lines = explode("\n", trim($content));
        $this->assertGreaterThan(3, count($lines)); // 1 header + 3 sales
        array_shift($lines); // skip header
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            $this->assertGreaterThan(10, count($parts));
            // the last part should be the hash
            $hash = end($parts);
            $this->assertEquals(64, strlen($hash)); // sha256 length
        }
    }
}
