<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\SalesMachineProfile;
use Carbon\CarbonImmutable;

class OfflinePolicyDriftService
{
    public const POLICY_VERSION = 'policy-drift-v1';
    private const CLOCK_DRIFT_REVIEW_MINUTES = 30;

    public function classify(SalesMachineProfile $profile, OfflineSalesImport $import, array $rawImport): ?OfflineSyncConflictDecision
    {
        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status);
        $materiality = $rawImport['policy_drift_materiality'] ?? null;
        $policyArea = $rawImport['policy_drift_area'] ?? null;
        $reason = $rawImport['policy_drift_reason'] ?? null;

        if ($materiality === 'non_material') {
            return null;
        }

        $metadata = [
            'conflict_policy_version' => self::POLICY_VERSION,
            'policy_area' => $policyArea,
            'policy_drift_materiality' => $materiality,
            'policy_drift_reason' => $reason,
            'time_evidence_status' => 'not_evaluated',
            'business_date_status' => 'not_evaluated',
        ];

        $clockDecision = $this->clockDecision($rawImport);
        if ($clockDecision !== null) {
            $metadata = array_merge($metadata, $clockDecision['metadata']);

            return OfflineSyncConflictDecision::review(
                reasonCode: $clockDecision['reason_code'],
                conflictFamily: 'time',
                reviewSeverity: 'medium',
                cashExposure: $cashExposure,
                suggestedActionCode: 'manager_review_business_date',
                metadata: $metadata,
            );
        }

        if (($rawImport['business_date_status'] ?? null) === 'conflict') {
            $metadata['business_date_status'] = 'review_required';
            $metadata['proposed_business_date'] = $rawImport['business_date'] ?? null;
            $metadata['business_date_review_reason'] = 'business_date_conflict';

            return OfflineSyncConflictDecision::review(
                reasonCode: 'review_business_date_out_of_policy',
                conflictFamily: 'time',
                reviewSeverity: 'medium',
                cashExposure: $cashExposure,
                suggestedActionCode: 'manager_review_business_date',
                metadata: $metadata,
            );
        }

        if ($materiality === 'material_review') {
            return OfflineSyncConflictDecision::review(
                reasonCode: $reason ?: 'review_policy_drift_material',
                conflictFamily: 'policy',
                reviewSeverity: $cashExposure === 'collected' ? 'high' : 'medium',
                cashExposure: $cashExposure,
                suggestedActionCode: 'manager_review_policy_drift',
                metadata: $metadata,
            );
        }

        if ($materiality === 'prohibited') {
            return $cashExposure === 'collected'
                ? OfflineSyncConflictDecision::review(
                    reasonCode: $reason ?: 'review_policy_drift_prohibited',
                    conflictFamily: 'policy',
                    reviewSeverity: 'high',
                    cashExposure: $cashExposure,
                    suggestedActionCode: 'support_review_prohibited_policy',
                    metadata: $metadata,
                )
                : OfflineSyncConflictDecision::rejected(
                    reasonCode: $reason ?: 'rejected_policy_drift_prohibited',
                    conflictFamily: 'policy',
                    cashExposure: $cashExposure,
                    metadata: $metadata,
                );
        }

        return null;
    }

    private function clockDecision(array $rawImport): ?array
    {
        if (($rawImport['clock_drift_minutes'] ?? null) !== null
            && abs((int) $rawImport['clock_drift_minutes']) > self::CLOCK_DRIFT_REVIEW_MINUTES) {
            return [
                'reason_code' => 'review_device_clock_drift',
                'metadata' => [
                    'time_evidence_status' => 'clock_drift_review',
                    'business_date_status' => 'not_resolved',
                    'business_date_review_reason' => 'clock_drift_exceeds_tolerance',
                ],
            ];
        }

        if (empty($rawImport['terminal_timestamp']) || empty($rawImport['submitted_at'])) {
            return null;
        }

        try {
            $terminalTime = CarbonImmutable::parse($rawImport['terminal_timestamp']);
            $submittedAt = CarbonImmutable::parse($rawImport['submitted_at']);
        } catch (\Throwable) {
            return [
                'reason_code' => 'review_device_clock_drift',
                'metadata' => [
                    'time_evidence_status' => 'invalid_time_evidence',
                    'business_date_status' => 'not_resolved',
                    'business_date_review_reason' => 'invalid_time_evidence',
                ],
            ];
        }

        if (abs($terminalTime->diffInMinutes($submittedAt, false)) > self::CLOCK_DRIFT_REVIEW_MINUTES) {
            return [
                'reason_code' => 'review_device_clock_drift',
                'metadata' => [
                    'time_evidence_status' => 'clock_drift_review',
                    'business_date_status' => 'not_resolved',
                    'proposed_business_date' => $rawImport['business_date'] ?? null,
                    'business_date_review_reason' => 'clock_drift_exceeds_tolerance',
                ],
            ];
        }

        return null;
    }
}
