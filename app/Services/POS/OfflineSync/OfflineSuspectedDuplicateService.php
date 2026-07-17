<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\SalesMachineProfile;

class OfflineSuspectedDuplicateService
{
    public const DETECTION_VERSION = 'duplicate-v1';
    public const REVIEW_THRESHOLD = 90;

    public function classify(SalesMachineProfile $profile, OfflineSalesImport $import, array $rawImport): ?OfflineSyncConflictDecision
    {
        $candidates = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $profile->tenant_id)
            ->where('branch_id', $profile->branch_id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where('offline_transaction_uuid', '!=', $import->offline_transaction_uuid)
            ->whereIn('server_sync_status', [
                OfflineSalesImport::SYNC_ACCEPTED,
                OfflineSalesImport::SYNC_REVIEW_REQUIRED,
            ])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (OfflineSalesImport $candidate) => $this->scoreCandidate($candidate, $rawImport))
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->all();

        $top = $candidates[0] ?? null;
        if (!$top || $top['score'] < self::REVIEW_THRESHOLD) {
            return null;
        }

        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status);

        return OfflineSyncConflictDecision::review(
            reasonCode: 'review_suspected_duplicate_capture',
            conflictFamily: 'duplicate',
            reviewSeverity: $cashExposure === 'collected' ? 'high' : 'medium',
            cashExposure: $cashExposure,
            suggestedActionCode: 'manager_review_possible_duplicate',
            metadata: [
                'duplicate_detection_version' => self::DETECTION_VERSION,
                'duplicate_review_threshold' => self::REVIEW_THRESHOLD,
                'duplicate_score' => $top['score'],
                'duplicate_rule_ids' => $top['rule_ids'],
                'duplicate_candidates' => $candidates,
                'duplicate_candidate_import_id' => $top['candidate_type'] === 'offline_import' ? $top['candidate_id'] : null,
                'duplicate_candidate_sale_id' => $top['candidate_type'] === 'sale' ? $top['candidate_id'] : null,
            ],
        );
    }

    private function scoreCandidate(OfflineSalesImport $candidate, array $rawImport): array
    {
        $candidatePayload = $candidate->raw_payload ?? [];
        $score = 0;
        $rules = [];

        if (($rawImport['local_receipt_number'] ?? null)
            && ($rawImport['local_receipt_number'] ?? null) === ($candidatePayload['local_receipt_number'] ?? null)) {
            $score = max($score, 100);
            $rules[] = 'same_local_receipt_number';
        }

        if (($rawImport['local_transaction_reference'] ?? null)
            && ($rawImport['local_transaction_reference'] ?? null) === ($candidatePayload['local_transaction_reference'] ?? null)) {
            $score = max($score, 95);
            $rules[] = 'same_local_transaction_reference';
        }

        if (($rawImport['client_total'] ?? null) !== null
            && (string) ($rawImport['client_total'] ?? '') === (string) ($candidatePayload['client_total'] ?? '')
            && json_encode($rawImport['items'] ?? []) === json_encode($candidatePayload['items'] ?? [])
            && ($rawImport['business_date'] ?? null) === ($candidatePayload['business_date'] ?? null)) {
            $score = max($score, 90);
            $rules[] = 'same_items_total_business_date';
        }

        return [
            'candidate_type' => $candidate->reconciled_sale_id ? 'sale' : 'offline_import',
            'candidate_id' => $candidate->reconciled_sale_id ?: $candidate->id,
            'candidate_import_id' => $candidate->id,
            'candidate_sale_id' => $candidate->reconciled_sale_id,
            'score' => $score,
            'rule_ids' => array_values(array_unique($rules)),
        ];
    }
}
