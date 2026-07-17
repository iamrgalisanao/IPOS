<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\SalesMachineProfile;

class OfflineSyncOrderingService
{
    public const POLICY_VERSION = 'ordering-v1';
    public const GAP_GRACE_MINUTES = 5;

    public function classify(SalesMachineProfile $profile, OfflineSalesImport $import, array $rawImport): ?OfflineSyncConflictDecision
    {
        if (($rawImport['predecessor_dependency'] ?? null) !== 'strict'
            && ($rawImport['strict_ordering'] ?? false) !== true) {
            return null;
        }

        $localSequence = $this->numericSequence($import->local_sequence);

        if ($localSequence === null || $localSequence <= 1) {
            return null;
        }

        $previousSequence = (string) ($localSequence - 1);
        $predecessor = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $profile->tenant_id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where('terminal_binding_epoch', $import->terminal_binding_epoch)
            ->where('local_sequence', $previousSequence)
            ->first();

        if ($predecessor && in_array($predecessor->server_sync_status, [
            OfflineSalesImport::SYNC_ACCEPTED,
            OfflineSalesImport::SYNC_REPLAYED,
        ], true)) {
            return null;
        }

        $now = now();
        $detectedAt = $import->sequence_gap_detected_at ?: $now;
        $expiresAt = $import->sequence_gap_grace_expires_at ?: $detectedAt->copy()->addMinutes(self::GAP_GRACE_MINUTES);
        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status);
        $metadata = [
            'ordering_policy_version' => self::POLICY_VERSION,
            'predecessor_dependency' => 'strict',
            'sequence_gap_detected_at' => $detectedAt->toISOString(),
            'sequence_gap_grace_expires_at' => $expiresAt->toISOString(),
            'missing_sequence_from' => $previousSequence,
            'missing_sequence_to' => $previousSequence,
            'predecessor_lookup_last_attempt_at' => $now->toISOString(),
            'sequence_gap_state' => $now->lt($expiresAt) ? 'grace_period' : 'escalated',
        ];

        if ($now->lt($expiresAt)) {
            return OfflineSyncConflictDecision::retryable(
                reasonCode: 'retry_sequence_gap_waiting',
                conflictFamily: 'ordering',
                cashExposure: $cashExposure,
                metadata: $metadata,
            );
        }

        return OfflineSyncConflictDecision::review(
            reasonCode: 'review_sequence_gap',
            conflictFamily: 'ordering',
            reviewSeverity: $cashExposure === 'collected' ? 'high' : 'medium',
            cashExposure: $cashExposure,
            suggestedActionCode: 'support_review_sequence_gap',
            blocksSuccessors: true,
            metadata: $metadata,
        );
    }

    private function numericSequence(?string $value): ?int
    {
        if ($value === null || $value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
