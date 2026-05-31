<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExpiredDataExports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:prune-exports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired data exports and delete their physical files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to prune expired data exports...');

        $expiredExports = DataExport::where('status', DataExport::STATUS_COMPLETED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;

        foreach ($expiredExports as $export) {
            if ($export->file_disk && $export->file_path) {
                $disk = Storage::disk($export->file_disk);
                if ($disk->exists($export->file_path)) {
                    $disk->delete($export->file_path);
                }
            }

            $export->update([
                'status' => DataExport::STATUS_EXPIRED,
            ]);

            $count++;
        }

        $this->info("Pruned $count expired data exports.");
    }
}
