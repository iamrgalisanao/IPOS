<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\User;
use Illuminate\Support\Arr;

class OfflineSyncStatusProjectionService
{
    public function project(OfflineSalesImport $import, bool $replay, ?User $viewer): array
    {
        return match ($this->roleFor($viewer)) {
            'support', 'audit' => $this->support($import, $replay),
            'manager' => $this->manager($import, $replay),
            default => $this->cashier($import, $replay),
        };
    }

    public function cashier(OfflineSalesImport $import, bool $replay): array
    {
        return array_filter([
            'offline_transaction_uuid' => $import->offline_transaction_uuid,
            'offline_sequence_number' => $import->offline_sequence_number,
            'status' => $this->syncStatus($import, $replay),
            'sync_status' => $this->syncStatus($import, $replay),
            'original_sync_status' => $replay ? $import->server_sync_status : $import->original_sync_status,
            'server_sale_uuid' => $import->reconciledSale?->id,
            'server_sale_number' => $import->reconciledSale?->sale_number,
            'official_invoice_number' => $import->official_invoice_number ?? $import->reconciledSale?->principal_invoice_number,
            'local_reference' => Arr::get($import->raw_payload ?? [], 'local_transaction_reference'),
            'consequence_status' => $this->consequenceStatus($import),
            'review_reason' => $import->review_reason,
            'reason' => $import->rejection_reason ?: $import->review_reason ?: $import->retryable_error_code,
            'retryable_error_code' => $import->retryable_error_code,
            'cash_exposure' => $import->cash_exposure_status,
            'resolution_status' => $import->current_resolution_status ?: $import->resolution_status,
            'suggested_action_code' => $import->suggested_action_code,
            'diagnostic_reference' => $import->review_required_at ? 'offline-review:' . $import->id : null,
            'review_due_at' => $import->review_due_at?->toIso8601String(),
            'message' => $this->messageFor($import),
            'contract_version' => $import->sync_contract_version ?? OfflineEnvelopeSynchronizationService::CONTRACT_VERSION,
        ], fn ($value) => $value !== null);
    }

    public function manager(OfflineSalesImport $import, bool $replay): array
    {
        return $this->cashier($import, $replay) + array_filter([
            'conflict_family' => $import->conflict_family,
            'reason_code' => $import->reason_code,
            'review_severity' => $import->review_severity,
            'retry_classification' => $import->retry_classification,
            'terminal_id' => $import->sales_machine_profile_id,
            'cashier_id' => Arr::get($import->raw_payload ?? [], 'cashier_id') ?: Arr::get($import->raw_payload ?? [], 'user_id'),
            'drawer_session_id' => Arr::get($import->raw_payload ?? [], 'drawer_session_id'),
        ], fn ($value) => $value !== null);
    }

    public function support(OfflineSalesImport $import, bool $replay): array
    {
        return $this->manager($import, $replay) + array_filter([
            'business_payload_fingerprint' => $import->server_payload_fingerprint,
            'conflict_metadata' => $import->conflict_metadata,
            'review_decision_snapshot' => $import->review_decision_snapshot,
            'duplicate_score' => $import->duplicate_score,
            'duplicate_review_threshold' => $import->duplicate_review_threshold,
            'duplicate_rule_ids' => $import->duplicate_rule_ids,
            'duplicate_candidates' => $import->duplicate_candidates,
            'sequence_gap_state' => $import->sequence_gap_state,
            'sequence_gap_detected_at' => $import->sequence_gap_detected_at?->toIso8601String(),
            'sequence_gap_grace_expires_at' => $import->sequence_gap_grace_expires_at?->toIso8601String(),
            'policy_versions' => [
                'conflict' => $import->conflict_policy_version,
                'ordering' => $import->ordering_policy_version,
                'duplicate' => $import->duplicate_detection_version,
                'review_payload' => $import->review_payload_schema_version,
            ],
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function roleFor(?User $viewer): string
    {
        if (!$viewer) {
            return 'cashier';
        }

        if (method_exists($viewer, 'isPlatformSupport') && $viewer->isPlatformSupport()) {
            return 'support';
        }

        if ($viewer->hasRole('Owner/Admin') || $viewer->hasRole('Accountant')) {
            return 'audit';
        }

        if ($viewer->hasRole('Branch Manager')) {
            return 'manager';
        }

        return 'cashier';
    }

    private function syncStatus(OfflineSalesImport $import, bool $replay): ?string
    {
        return $replay ? OfflineSalesImport::SYNC_REPLAYED : $import->server_sync_status;
    }

    private function consequenceStatus(OfflineSalesImport $import): array
    {
        return $import->acceptance_consequence_snapshot
            ?: $import->current_consequence_status
            ?: $import->consequence_status_snapshot
            ?: [
                'sale' => 'not_applicable',
                'payment' => 'not_applicable',
                'inventory' => 'not_applicable',
                'variance' => 'not_applicable',
                'loyalty' => 'not_applicable',
                'store_credit' => 'not_applicable',
                'receipt' => 'not_applicable',
                'accounting_outbox' => 'not_applicable',
            ];
    }

    private function messageFor(OfflineSalesImport $import): ?string
    {
        return match ($import->server_sync_status) {
            OfflineSalesImport::SYNC_REVIEW_REQUIRED => 'Needs review. Contact a manager or support.',
            OfflineSalesImport::SYNC_RETRYABLE_FAILED => 'Sync is waiting and will retry automatically.',
            OfflineSalesImport::SYNC_REJECTED => 'This offline record was rejected and will not retry automatically.',
            default => null,
        };
    }
}
