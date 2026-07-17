<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncAttempt;

class OfflineSyncReviewStateService
{
    public const CONFLICT_POLICY_VERSION = 'conflict-v1';
    public const REVIEW_PAYLOAD_SCHEMA_VERSION = 'review-payload-v1';
    public const REVIEW_SLA_POLICY_ID = 'offline-sync-review-standard';
    public const REVIEW_SLA_POLICY_VERSION = 'sla-v1';

    public function apply(
        OfflineSalesImport $import,
        OfflineSyncAttempt $attempt,
        OfflineSyncConflictDecision $decision,
        array $consequenceStatus,
    ): OfflineSalesImport {
        if (in_array($import->server_sync_status, [
            OfflineSalesImport::SYNC_REJECTED,
            OfflineSalesImport::SYNC_REVIEW_REQUIRED,
        ], true)) {
            return $import->fresh();
        }

        $now = now();
        $metadata = $decision->metadata;
        $syncStatus = $decision->decision === 'retryable_failed'
            ? OfflineSalesImport::SYNC_RETRYABLE_FAILED
            : ($decision->decision === 'rejected' ? OfflineSalesImport::SYNC_REJECTED : OfflineSalesImport::SYNC_REVIEW_REQUIRED);

        $updates = [
            'status' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED
                ? OfflineSalesImport::STATUS_PENDING
                : ($syncStatus === OfflineSalesImport::SYNC_REJECTED ? OfflineSalesImport::STATUS_REJECTED : OfflineSalesImport::STATUS_CONFLICT),
            'server_sync_status' => $syncStatus,
            'original_sync_status' => $syncStatus,
            'review_reason' => $syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED ? $decision->reasonCode : null,
            'rejection_reason' => $syncStatus === OfflineSalesImport::SYNC_REJECTED ? $decision->reasonCode : null,
            'retryable_error_code' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED ? $decision->reasonCode : null,
            'conflict_family' => $decision->conflictFamily,
            'reason_code' => $decision->reasonCode,
            'review_severity' => $decision->reviewSeverity,
            'retry_classification' => $decision->retryClassification,
            'suggested_action_code' => $decision->suggestedActionCode,
            'cash_exposure_status' => $decision->cashExposure,
            'conflict_metadata' => $metadata,
            'consequence_status_snapshot' => $consequenceStatus,
            'current_consequence_status' => $consequenceStatus,
            'conflict_policy_version' => $metadata['conflict_policy_version'] ?? self::CONFLICT_POLICY_VERSION,
            'ordering_policy_version' => $metadata['ordering_policy_version'] ?? null,
            'review_payload_schema_version' => self::REVIEW_PAYLOAD_SCHEMA_VERSION,
            'time_evidence_status' => $metadata['time_evidence_status'] ?? 'not_evaluated',
            'business_date_status' => $metadata['business_date_status'] ?? 'not_evaluated',
            'proposed_business_date' => $metadata['proposed_business_date'] ?? null,
            'resolved_business_date' => $metadata['resolved_business_date'] ?? null,
            'business_date_review_reason' => $metadata['business_date_review_reason'] ?? null,
            'current_resolution_status' => $syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED ? 'pending_support' : null,
        ];

        if ($syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED) {
            $updates += [
                'review_required_at' => $import->review_required_at ?: $now,
                'review_locked_at' => $import->review_locked_at ?: $now,
                'review_opened_at' => $import->review_opened_at ?: $now,
                'review_due_at' => $import->review_due_at ?: $now->copy()->addDay(),
                'review_sla_policy_id' => self::REVIEW_SLA_POLICY_ID,
                'review_sla_policy_version' => self::REVIEW_SLA_POLICY_VERSION,
                'review_escalation_level' => $decision->reviewSeverity === 'critical' ? 3 : 1,
                'last_review_activity_at' => $now,
                'assigned_team' => $metadata['assigned_team'] ?? ($decision->reviewSeverity === 'critical' ? 'security' : 'branch_support'),
                'review_decision_snapshot' => $decision->toArray() + ['metadata' => $metadata],
            ];
        }

        if ($syncStatus === OfflineSalesImport::SYNC_REJECTED) {
            $updates['rejected_at'] = $import->rejected_at ?: $now;
        }

        if (isset($metadata['sequence_gap_detected_at'])) {
            $updates += [
                'predecessor_dependency' => $metadata['predecessor_dependency'] ?? 'strict',
                'sequence_gap_detected_at' => $import->sequence_gap_detected_at ?: $metadata['sequence_gap_detected_at'],
                'sequence_gap_grace_expires_at' => $import->sequence_gap_grace_expires_at ?: $metadata['sequence_gap_grace_expires_at'],
                'sequence_gap_state' => $metadata['sequence_gap_state'] ?? null,
                'missing_sequence_from' => $metadata['missing_sequence_from'] ?? null,
                'missing_sequence_to' => $metadata['missing_sequence_to'] ?? null,
                'predecessor_lookup_last_attempt_at' => $metadata['predecessor_lookup_last_attempt_at'] ?? $now,
            ];
        }

        if (isset($metadata['duplicate_score'])) {
            $updates += [
                'duplicate_score' => $metadata['duplicate_score'],
                'duplicate_review_threshold' => $metadata['duplicate_review_threshold'] ?? null,
                'duplicate_rule_ids' => $metadata['duplicate_rule_ids'] ?? [],
                'duplicate_candidates' => $metadata['duplicate_candidates'] ?? [],
                'duplicate_candidate_sale_id' => $metadata['duplicate_candidate_sale_id'] ?? null,
                'duplicate_candidate_import_id' => $metadata['duplicate_candidate_import_id'] ?? null,
                'duplicate_detection_version' => $metadata['duplicate_detection_version'] ?? null,
            ];
        }

        $import->update($updates);

        $attempt->update([
            'result_status' => $syncStatus,
            'http_status' => 200,
            'transient_error_code' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED ? $decision->reasonCode : null,
            'retryable' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED,
            'metadata' => array_merge($attempt->metadata ?? [], [
                'conflict_decision' => $decision->toArray(),
            ]),
            'response_finished_at' => $now,
        ]);

        return $import->fresh();
    }

    public static function cashExposureFrom(?string $cashStatus): string
    {
        return match ($cashStatus) {
            'collected' => 'collected',
            'disputed', 'capture_uncertain', 'unknown' => 'unknown',
            default => 'none',
        };
    }
}
