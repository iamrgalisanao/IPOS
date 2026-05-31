<?php

namespace App\Jobs\Reports;

use App\Models\DataExport;
use App\Services\POS\EJournalExportService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public DataExport $export
    ) {}

    public function handle(EJournalExportService $ejournalService): void
    {
        $this->export->update([
            'status' => DataExport::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            if ($this->export->type !== 'ejournal') {
                throw new \InvalidArgumentException("Export type [{$this->export->type}] is not supported yet.");
            }

            // Bind context
            $tenant = \App\Models\Tenant::find($this->export->tenant_id);
            if ($tenant) {
                app(TenantContext::class)->setTenant($tenant);
            }

            // Generate filename
            $filename = sprintf(
                'ipos-electronic-journal-%s-%s.txt',
                $this->export->parameters['date_from'] ?? 'all',
                $this->export->id
            );
            $filePath = $filename; // Relative to the 'exports' disk root

            // Get absolute path to the local file for streaming
            // The service will write to this file incrementally.
            $disk = Storage::disk('exports');
            $absolutePath = $disk->path($filePath);
            
            // Ensure directory exists
            if (!file_exists(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0755, true);
            }

            // Run export
            $result = $ejournalService->exportToFile($this->export->parameters ?? [], $absolutePath);

            // Update export record
            $this->export->update([
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $filePath,
                'file_disk' => 'exports',
                'file_size' => $result['file_size'] ?? filesize($absolutePath),
                'checksum' => $result['checksum'] ?? hash_file('sha256', $absolutePath),
                'mime_type' => 'text/plain',
                'completed_at' => now(),
                'expires_at' => now()->addHours(48),
            ]);

        } catch (Throwable $e) {
            $this->export->update([
                'status' => DataExport::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Export failed due to an internal error. Please try again later or contact support. (' . get_class($e) . ': ' . $e->getMessage() . ')',
            ]);
            
            // Rethrow so the queue marks the job as failed and retries if appropriate
            throw $e;
        }
    }
}
