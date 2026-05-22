<?php

use App\Jobs\ProcessAccountingOutboxJob;
use App\Models\AccountingOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('accounting:process-outbox {--limit=50} {--sync : Process inline instead of dispatching jobs}', function () {
    $limit = max(1, (int) $this->option('limit'));

    $records = AccountingOutbox::withoutGlobalScope('tenant')
        ->whereIn('sync_status', ['pending', 'failed'])
        ->where(function ($query) {
            $query->whereNull('available_at')
                ->orWhere('available_at', '<=', now());
        })
        ->orderBy('created_at')
        ->limit($limit)
        ->get();

    foreach ($records as $record) {
        if ($this->option('sync')) {
            ProcessAccountingOutboxJob::dispatchSync($record->id);
        } else {
            ProcessAccountingOutboxJob::dispatch($record->id);
        }
    }

    $this->info("Queued {$records->count()} accounting outbox record(s).");
})->purpose('Queue eligible accounting outbox records for tenant-scoped processing');

Schedule::command('accounting:process-outbox --limit=50')->everyMinute();

Schedule::command('ipos:generate-replenishment-drafts')
    ->dailyAt('02:00')
    ->onOneServer()
    ->runInBackground();

