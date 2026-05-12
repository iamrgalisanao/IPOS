<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use Illuminate\Support\Carbon;

class AccountingOutboxSyncStateService
{
    /**
     * Mark an outbox record as processing.
     * Allowed from: pending, failed.
     */
    public function markAsProcessing(AccountingOutbox $record): void
    {
        $this->validateTransition($record->sync_status, 'processing');

        $record->update([
            'sync_status' => 'processing',
            'attempt_count' => $record->attempt_count + 1,
            'sync_error' => null, // Clear previous error
            'sync_error_category' => null,
            'last_attempted_at' => now(),
        ]);
    }

    /**
     * Mark an outbox record as synced.
     * Allowed from: processing.
     */
    public function markAsSynced(AccountingOutbox $record, array $externalReference = []): void
    {
        $this->validateTransition($record->sync_status, 'synced');

        $updates = [
            'sync_status' => 'synced',
            'synced_at' => now(),
            'sync_error' => null,
            'sync_error_category' => null,
            'next_attempt_at' => null,
            'available_at' => null,
        ];

        foreach (['external_provider', 'external_id', 'external_reference'] as $field) {
            if (array_key_exists($field, $externalReference)) {
                $updates[$field] = $externalReference[$field];
            }
        }

        $record->update($updates);
    }

    /**
     * Mark an outbox record as failed.
     * Allowed from: processing.
     */
    public function markAsFailed(AccountingOutbox $record, string $error, string $category = 'system', ?Carbon $nextAttemptAt = null): void
    {
        $this->validateTransition($record->sync_status, 'failed');

        $record->update([
            'sync_status' => 'failed',
            'sync_error' => substr($error, 0, 1000), // AC: Safe error storage
            'sync_error_category' => $category,
            'next_attempt_at' => $nextAttemptAt,
            'available_at' => $nextAttemptAt,
        ]);
    }

    /**
     * Validate status transition logic.
     */
    protected function validateTransition(string $currentStatus, string $targetStatus): void
    {
        $allowed = [
            'pending' => ['processing'],
            'failed' => ['processing'],
            'processing' => ['synced', 'failed'],
            'synced' => [], // Terminal state
        ];

        if (!isset($allowed[$currentStatus]) || !in_array($targetStatus, $allowed[$currentStatus])) {
            throw new \RuntimeException("Invalid transition: {$currentStatus} -> {$targetStatus}");
        }
    }
}
