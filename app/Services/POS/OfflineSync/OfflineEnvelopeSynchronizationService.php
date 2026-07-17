<?php

namespace App\Services\POS\OfflineSync;

use App\Exceptions\HiddenOfflineResourceException;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncAttempt;
use App\Models\OfflineSyncBatch;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Services\Accounting\AccountingOutboxService;
use App\Services\AuditLogger;
use App\Services\POS\OfflineReadiness\OfflineSettingsValidator;
use App\Services\POS\SaleCreationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfflineEnvelopeSynchronizationService
{
    public const CONTRACT_VERSION = 'epic-41-sync-v1';
    private const FINGERPRINT_ALGORITHM = 'sha256-canonical-json';
    private const FINGERPRINT_SCHEMA_VERSION = 1;

    public function __construct(
        protected OfflineSettingsValidator $settingsValidator,
        protected SaleCreationService $saleCreationService,
        protected AccountingOutboxService $outboxService,
        protected AuditLogger $auditLogger,
        protected OfflineSyncOrderingService $orderingService,
        protected OfflineSuspectedDuplicateService $duplicateService,
        protected OfflineSyncReviewStateService $reviewStateService,
        protected OfflineTerminalQuarantineService $quarantineService,
        protected OfflinePolicyDriftService $policyDriftService,
        protected OfflineSyncStatusProjectionService $projectionService,
        protected OfflineEnvelopePolicyValidator $policyValidator,
    ) {}

    public function synchronizeBatch(SalesMachineProfile $profile, array $batchPayload, User $user): array
    {
        $tenant = $profile->tenant()->withoutGlobalScopes()->first();
        $branch = $profile->branch()->withoutGlobalScopes()->first();
        $validation = $this->settingsValidator->validate($tenant, $branch, $profile);

        if (!$validation['allowed']) {
            throw new \RuntimeException('OFFLINE_NOT_ENABLED: ' . $validation['reason']);
        }

        $rawImports = $batchPayload['imports'] ?? [];
        $batch = OfflineSyncBatch::firstOrCreate(
            [
                'tenant_id' => $profile->tenant_id,
                'sales_machine_profile_id' => $profile->id,
                'batch_reference' => $batchPayload['batch_reference'],
            ],
            [
                'branch_id' => $profile->branch_id,
                'status' => OfflineSyncBatch::STATUS_PROCESSING,
                'submitted_import_count' => count($rawImports),
                'sync_started_at' => now(),
            ]
        );

        if ($batch->status !== OfflineSyncBatch::STATUS_PROCESSING) {
            $batch->update([
                'status' => OfflineSyncBatch::STATUS_PROCESSING,
                'sync_started_at' => $batch->sync_started_at ?? now(),
                'submitted_import_count' => max((int) $batch->submitted_import_count, count($rawImports)),
            ]);
        }

        $results = [];

        foreach ($rawImports as $rawImport) {
            $results[] = $this->synchronizeEnvelope($profile, $batch, $rawImport, $user);
        }

        $failed = collect($results)
            ->whereIn('sync_status', [
                OfflineSalesImport::SYNC_REJECTED,
                OfflineSalesImport::SYNC_REVIEW_REQUIRED,
                OfflineSalesImport::SYNC_RETRYABLE_FAILED,
            ])
            ->count();

        $batch->update([
            'status' => OfflineSyncBatch::STATUS_COMPLETED,
            'processed_count' => count($results),
            'failed_count' => $failed,
            'sync_completed_at' => now(),
        ]);

        return [
            'batch_id' => $batch->id,
            'batch_reference' => $batch->batch_reference,
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $batch->fresh()->status,
            'submitted' => count($rawImports),
            'processed' => count($results),
            'failed' => $failed,
            'imports' => $results,
            'results' => $results,
        ];
    }

    public function lookupStatus(SalesMachineProfile $profile, string $offlineTransactionUuid, ?User $viewer = null): ?array
    {
        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $profile->tenant_id)
            ->where('offline_transaction_uuid', $offlineTransactionUuid)
            ->where('sales_machine_profile_id', $profile->id)
            ->first();

        return $import ? $this->responseFor($import, replay: false, viewer: $viewer) : null;
    }

    private function synchronizeEnvelope(
        SalesMachineProfile $profile,
        OfflineSyncBatch $batch,
        array $rawImport,
        User $user
    ): array {
        $offlineUuid = $rawImport['offline_transaction_uuid'] ?? null;
        $localSequence = (string) ($rawImport['local_sequence'] ?? $rawImport['offline_sequence_number'] ?? '');
        $serverFingerprint = $this->computeServerFingerprint($rawImport);
        $fingerprintResolution = $this->resolveClientFingerprint($rawImport);

        if (!$offlineUuid) {
            return $this->preMutationRejectedResult($rawImport, 'rejected_missing_offline_transaction_uuid');
        }

        if (empty($rawImport['terminal_binding_epoch'])) {
            return $this->preMutationRejectedResult($rawImport, 'rejected_missing_terminal_binding_epoch');
        }

        if ($fingerprintResolution['reason'] !== null) {
            return $this->preMutationRejectedResult($rawImport, $fingerprintResolution['reason']);
        }

        if (($rawImport['tenant_id'] ?? $profile->tenant_id) !== $profile->tenant_id) {
            $this->auditLogger->log('offline_sync_cross_tenant_blocked', metadata: [
                'offline_transaction_uuid' => $offlineUuid,
                'terminal_id' => $rawImport['terminal_id'] ?? $rawImport['sales_machine_profile_id'] ?? null,
                'masked_submitted_tenant_id' => isset($rawImport['tenant_id']) ? '[masked]' : null,
            ]);

            throw new HiddenOfflineResourceException('Offline resource was not found.');
        }

        $clientFingerprint = $fingerprintResolution['fingerprint'];
        $attempt = null;

        try {
            return DB::transaction(function () use (
                $profile,
                $batch,
                $rawImport,
                $user,
                $offlineUuid,
                $localSequence,
                $serverFingerprint,
                $clientFingerprint,
                &$attempt
            ) {
                $import = $this->insertOrLockEnvelope(
                    profile: $profile,
                    batch: $batch,
                    rawImport: $rawImport,
                    offlineUuid: $offlineUuid,
                    localSequence: $localSequence,
                    serverFingerprint: $serverFingerprint
                );

                if ($import->sales_machine_profile_id !== $profile->id
                    || $import->branch_id !== $profile->branch_id
                    || $import->tenant_id !== $profile->tenant_id) {
                    return $this->preMutationRejectedResult($rawImport, 'rejected_terminal_identity_conflict');
                }

                $attempt = $this->recordAttempt($import, $rawImport, 'processing');

                if ($import->server_payload_fingerprint
                    && $import->server_payload_fingerprint !== $serverFingerprint) {
                    return $this->markDrift($import, $attempt, 'rejected_fingerprint_drift');
                }

                if (!hash_equals((string) $clientFingerprint, $serverFingerprint)) {
                    return $this->markDrift($import, $attempt, 'rejected_fingerprint_drift');
                }

                if (in_array($import->server_sync_status, [
                    OfflineSalesImport::SYNC_ACCEPTED,
                    OfflineSalesImport::SYNC_REJECTED,
                    OfflineSalesImport::SYNC_REVIEW_REQUIRED,
                ], true)) {
                    $import->update(['last_replayed_at' => now()]);
                    $attempt->update([
                        'result_status' => OfflineSalesImport::SYNC_REPLAYED,
                        'http_status' => 200,
                        'response_finished_at' => now(),
                    ]);

                    $this->auditLogger->log('offline_sync_envelope_replayed', $import, null, null, null, null, [
                        'offline_transaction_uuid' => $offlineUuid,
                        'original_sync_status' => $import->server_sync_status,
                    ]);

                    return $this->responseFor($import->fresh(), replay: true, viewer: $user);
                }

                if ($this->quarantineService->isEpochQuarantined($profile, $import->terminal_binding_epoch)) {
                    return $this->applyDecisionAndRespond(
                        $import,
                        $attempt,
                        OfflineSyncConflictDecision::review(
                            reasonCode: 'review_terminal_epoch_quarantined',
                            conflictFamily: 'terminal_state',
                            reviewSeverity: 'critical',
                            cashExposure: OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status),
                            suggestedActionCode: 'contact_security_support',
                            blocksSuccessors: true,
                            metadata: [
                                'assigned_team' => 'security',
                                'terminal_state' => 'epoch_quarantined',
                            ]
                        ),
                        $profile,
                        $user
                    );
                }

                $terminalDecision = $this->classifyTerminalState($profile, $import, $rawImport);
                if ($terminalDecision !== null) {
                    return $this->applyDecisionAndRespond($import, $attempt, $terminalDecision, $profile, $user);
                }

                $orderingDecision = $this->orderingService->classify($profile, $import, $rawImport);
                if ($orderingDecision !== null) {
                    return $this->applyDecisionAndRespond($import, $attempt, $orderingDecision, $profile, $user);
                }

                $policyDriftDecision = $this->policyDriftService->classify($profile, $import, $rawImport);
                if ($policyDriftDecision !== null) {
                    return $this->applyDecisionAndRespond($import, $attempt, $policyDriftDecision, $profile, $user);
                }

                $duplicateDecision = $this->duplicateService->classify($profile, $import, $rawImport);
                if ($duplicateDecision !== null) {
                    return $this->applyDecisionAndRespond($import, $attempt, $duplicateDecision, $profile, $user);
                }

                $policyFailure = $this->validateEnvelopePolicy($profile, $rawImport);
                if ($policyFailure !== null) {
                    return $this->markRejectedOrReview($import, $attempt, $policyFailure, $rawImport);
                }

                $sale = $this->createSaleFromEnvelope($profile, $import, $rawImport, $user);
                $payments = $this->recordCashPayment($sale, $rawImport, $user);
                $this->recordAccountingOutbox($sale, $payments);

                if (!$sale->is_training_mode) {
                    \App\Jobs\Inventory\ProcessSaleInventoryDeductionJob::dispatch($sale->id)->afterCommit();
                }

                $consequenceStatus = [
                    'sale' => 'committed',
                    'payment' => 'committed',
                    'inventory' => $sale->is_training_mode ? 'not_applicable' : 'queued',
                    'variance' => 'not_applicable',
                    'loyalty' => $sale->customer_financial_account_id ? 'queued' : 'not_applicable',
                    'store_credit' => 'not_applicable',
                    'receipt' => 'available',
                    'accounting_outbox' => $sale->is_training_mode ? 'not_applicable' : 'queued',
                ];

                $import->update([
                    'status' => OfflineSalesImport::STATUS_POSTED,
                    'server_sync_status' => OfflineSalesImport::SYNC_ACCEPTED,
                    'original_sync_status' => OfflineSalesImport::SYNC_ACCEPTED,
                    'reconciled_sale_id' => $sale->id,
                    'official_invoice_number' => $sale->principal_invoice_number,
                    'reconciled_at' => now(),
                    'accepted_at' => now(),
                    'server_payload_fingerprint' => $serverFingerprint,
                    'consequence_status_snapshot' => $consequenceStatus,
                    'acceptance_consequence_snapshot' => $consequenceStatus,
                    'current_consequence_status' => $consequenceStatus,
                ]);

                $attempt->update([
                    'result_status' => OfflineSalesImport::SYNC_ACCEPTED,
                    'http_status' => 200,
                    'response_finished_at' => now(),
                ]);

                $this->auditLogger->log('offline_sync_envelope_accepted', $import->fresh(), null, null, null, null, [
                    'offline_transaction_uuid' => $offlineUuid,
                    'sale_id' => $sale->id,
                    'official_invoice_number' => $sale->principal_invoice_number,
                    'consequence_status' => $consequenceStatus,
                ]);

                return $this->responseFor($import->fresh(), replay: false, viewer: $user);
            }, 3);
        } catch (HiddenOfflineResourceException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            return $this->markFailureOutsideTransaction(
                $profile,
                $batch,
                $rawImport,
                $offlineUuid,
                $localSequence,
                $serverFingerprint,
                OfflineSalesImport::SYNC_REVIEW_REQUIRED,
                'validation_failed',
                $exception->getMessage()
            );
        } catch (\Throwable $exception) {
            return $this->markFailureOutsideTransaction(
                $profile,
                $batch,
                $rawImport,
                $offlineUuid,
                $localSequence,
                $serverFingerprint,
                OfflineSalesImport::SYNC_RETRYABLE_FAILED,
                'server_exception',
                $exception->getMessage()
            );
        }
    }

    private function insertOrLockEnvelope(
        SalesMachineProfile $profile,
        OfflineSyncBatch $batch,
        array $rawImport,
        string $offlineUuid,
        string $localSequence,
        string $serverFingerprint
    ): OfflineSalesImport {
        $existing = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $profile->tenant_id)
            ->where('offline_transaction_uuid', $offlineUuid)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return OfflineSalesImport::create([
                'tenant_id' => $profile->tenant_id,
                'branch_id' => $profile->branch_id,
                'sales_machine_profile_id' => $profile->id,
                'batch_id' => $batch->id,
                'offline_sequence_number' => $rawImport['offline_sequence_number'] ?? $localSequence,
                'offline_transaction_uuid' => $offlineUuid,
                'terminal_binding_epoch' => $rawImport['terminal_binding_epoch'] ?? null,
                'local_sequence' => $localSequence ?: null,
                'payload_hash' => $rawImport['payload_hash'] ?? $serverFingerprint,
                'sync_contract_version' => self::CONTRACT_VERSION,
                'server_payload_fingerprint' => $serverFingerprint,
                'fingerprint_algorithm' => self::FINGERPRINT_ALGORITHM,
                'fingerprint_schema_version' => self::FINGERPRINT_SCHEMA_VERSION,
                'raw_payload' => $rawImport,
                'status' => OfflineSalesImport::STATUS_PENDING,
                'server_sync_status' => null,
                'cash_status' => $rawImport['cash_status'] ?? 'collected',
                'resolution_status' => $rawImport['resolution_status'] ?? 'none',
                'submitted_at' => $rawImport['submitted_at'] ?? now(),
                'first_seen_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = OfflineSalesImport::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('offline_transaction_uuid', $offlineUuid)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function resolveClientFingerprint(array $rawImport): array
    {
        $current = $rawImport['business_payload_fingerprint'] ?? null;
        $legacy = $rawImport['payload_hash'] ?? null;

        if ($current && $legacy && !hash_equals((string) $current, (string) $legacy)) {
            return [
                'fingerprint' => null,
                'reason' => 'rejected_fingerprint_evidence_conflict',
            ];
        }

        $fingerprint = $current ?: $legacy;

        if (!$fingerprint) {
            return [
                'fingerprint' => null,
                'reason' => 'rejected_missing_required_evidence',
            ];
        }

        return [
            'fingerprint' => (string) $fingerprint,
            'reason' => null,
        ];
    }

    private function preMutationRejectedResult(array $rawImport, string $reason): array
    {
        return array_filter([
            'offline_transaction_uuid' => $rawImport['offline_transaction_uuid'] ?? null,
            'offline_sequence_number' => $rawImport['offline_sequence_number'] ?? null,
            'status' => OfflineSalesImport::SYNC_REJECTED,
            'sync_status' => OfflineSalesImport::SYNC_REJECTED,
            'original_sync_status' => OfflineSalesImport::SYNC_REJECTED,
            'consequence_status' => $this->emptyConsequenceStatus(),
            'reason' => $reason,
            'contract_version' => self::CONTRACT_VERSION,
        ], fn ($value) => $value !== null);
    }

    private function markFailureOutsideTransaction(
        SalesMachineProfile $profile,
        OfflineSyncBatch $batch,
        array $rawImport,
        string $offlineUuid,
        string $localSequence,
        string $serverFingerprint,
        string $syncStatus,
        string $reason,
        string $message
    ): array {
        return DB::transaction(function () use (
            $profile,
            $batch,
            $rawImport,
            $offlineUuid,
            $localSequence,
            $serverFingerprint,
            $syncStatus,
            $reason,
            $message
        ) {
            $import = $this->insertOrLockEnvelope(
                profile: $profile,
                batch: $batch,
                rawImport: $rawImport,
                offlineUuid: $offlineUuid,
                localSequence: $localSequence,
                serverFingerprint: $serverFingerprint
            );

            if (in_array($import->server_sync_status, [
                OfflineSalesImport::SYNC_ACCEPTED,
                OfflineSalesImport::SYNC_REJECTED,
                OfflineSalesImport::SYNC_REVIEW_REQUIRED,
            ], true)) {
                return $this->responseFor($import->fresh(), replay: true);
            }

            $attempt = $this->recordAttempt($import, $rawImport, $syncStatus);
            $consequenceStatus = $this->emptyConsequenceStatus();

            $import->update([
                'status' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED
                    ? OfflineSalesImport::STATUS_PENDING
                    : OfflineSalesImport::STATUS_CONFLICT,
                'server_sync_status' => $syncStatus,
                'original_sync_status' => $syncStatus,
                'retryable_error_code' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED ? $reason : null,
                'review_reason' => $syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED ? $reason : null,
                'current_consequence_status' => $consequenceStatus,
                'consequence_status_snapshot' => $consequenceStatus,
                'review_required_at' => $syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED ? now() : null,
            ]);

            $attempt->update([
                'result_status' => $syncStatus,
                'http_status' => 200,
                'transient_error_code' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED ? $reason : null,
                'error_message' => $message,
                'retryable' => $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED,
                'response_finished_at' => now(),
            ]);

            $this->auditLogger->log(
                $syncStatus === OfflineSalesImport::SYNC_RETRYABLE_FAILED
                    ? 'offline_sync_envelope_retryable_failed'
                    : 'offline_sync_envelope_review_required',
                $import->fresh(),
                metadata: ['reason' => $reason]
            );

            return $this->responseFor($import->fresh(), replay: false);
        }, 3);
    }

    private function recordAttempt(OfflineSalesImport $import, array $rawImport, string $status): OfflineSyncAttempt
    {
        return OfflineSyncAttempt::create([
            'tenant_id' => $import->tenant_id,
            'branch_id' => $import->branch_id,
            'offline_sales_import_id' => $import->id,
            'offline_transaction_uuid' => $import->offline_transaction_uuid,
            'sync_attempt_id' => $rawImport['sync_attempt_id'] ?? null,
            'lease_id' => $rawImport['lease_id'] ?? null,
            'attempt_generation' => $rawImport['attempt_generation'] ?? null,
            'worker' => 'offline-envelope-sync',
            'request_started_at' => now(),
            'result_status' => $status,
            'retryable' => false,
            'metadata' => [
                'queue_state_revision' => $rawImport['queue_state_revision'] ?? null,
                'terminal_binding_epoch' => $rawImport['terminal_binding_epoch'] ?? null,
            ],
        ]);
    }

    private function validateEnvelopePolicy(SalesMachineProfile $profile, array $rawImport): ?string
    {
        return $this->policyValidator->reasonFor($profile, $rawImport);
    }

    private function classifyTerminalState(
        SalesMachineProfile $profile,
        OfflineSalesImport $import,
        array $rawImport
    ): ?OfflineSyncConflictDecision {
        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status);

        if (($rawImport['branch_id'] ?? $profile->branch_id) !== $profile->branch_id) {
            return $cashExposure === 'collected'
                ? OfflineSyncConflictDecision::review(
                    reasonCode: 'review_cross_branch_terminal',
                    conflictFamily: 'identity',
                    reviewSeverity: 'high',
                    cashExposure: $cashExposure,
                    suggestedActionCode: 'support_review_cross_branch_terminal',
                    metadata: ['terminal_state' => 'cross_branch']
                )
                : OfflineSyncConflictDecision::rejected('rejected_cross_tenant_or_branch', 'identity', $cashExposure);
        }

        if (($rawImport['terminal_compromised'] ?? false) === true
            || ($rawImport['terminal_state'] ?? null) === 'compromised') {
            return OfflineSyncConflictDecision::review(
                reasonCode: 'review_terminal_compromised',
                conflictFamily: 'terminal_state',
                reviewSeverity: 'critical',
                cashExposure: $cashExposure,
                suggestedActionCode: 'security_review_terminal_quarantine',
                blocksSuccessors: true,
                metadata: [
                    'assigned_team' => 'security',
                    'terminal_state' => 'compromised',
                ]
            );
        }

        if (in_array($profile->activation_status, [
            SalesMachineProfile::STATUS_REVOKED,
            SalesMachineProfile::STATUS_SUSPENDED,
            SalesMachineProfile::STATUS_EXPIRED,
        ], true)) {
            return $cashExposure === 'collected'
                ? OfflineSyncConflictDecision::review(
                    reasonCode: 'review_terminal_revoked_after_capture',
                    conflictFamily: 'terminal_state',
                    reviewSeverity: 'high',
                    cashExposure: $cashExposure,
                    suggestedActionCode: 'support_review_terminal_state',
                    blocksSuccessors: true,
                    metadata: ['terminal_state' => $profile->activation_status]
                )
                : OfflineSyncConflictDecision::rejected('rejected_terminal_inactive', 'terminal_state', $cashExposure);
        }

        return null;
    }

    private function applyDecisionAndRespond(
        OfflineSalesImport $import,
        OfflineSyncAttempt $attempt,
        OfflineSyncConflictDecision $decision,
        ?SalesMachineProfile $profile = null,
        ?User $viewer = null
    ): array {
        $consequenceStatus = $this->emptyConsequenceStatus();
        $fresh = $this->reviewStateService->apply($import, $attempt, $decision, $consequenceStatus);

        if ($decision->reasonCode === 'review_terminal_compromised' && $profile !== null) {
            $this->quarantineService->quarantineEpoch($profile, $fresh, $decision->reasonCode);
        }

        $this->auditDecision($fresh, $decision);

        return $this->responseFor($fresh, replay: false, viewer: $viewer);
    }

    private function auditDecision(OfflineSalesImport $import, OfflineSyncConflictDecision $decision): void
    {
        $event = match ($decision->decision) {
            'retryable_failed' => $decision->reasonCode === 'retry_sequence_gap_waiting'
                ? 'offline_sync_sequence_gap_detected'
                : 'offline_sync_conflict_retryable',
            'rejected' => 'offline_sync_envelope_rejected',
            default => match ($decision->reasonCode) {
                'review_suspected_duplicate_capture' => 'offline_sync_duplicate_suspected',
                'review_sequence_gap' => 'offline_sync_sequence_gap_escalated',
                'review_terminal_compromised' => 'offline_sync_terminal_quarantined',
                default => 'offline_sync_review_opened',
            },
        };

        $this->auditLogger->log($event, $import, metadata: [
            'offline_transaction_uuid' => $import->offline_transaction_uuid,
            'decision' => $decision->toArray(),
        ]);
    }

    private function markDrift(OfflineSalesImport $import, OfflineSyncAttempt $attempt, string $reason): array
    {
        $consequenceStatus = $this->emptyConsequenceStatus();

        $import->update([
            'status' => OfflineSalesImport::STATUS_REJECTED,
            'server_sync_status' => OfflineSalesImport::SYNC_REJECTED,
            'original_sync_status' => OfflineSalesImport::SYNC_REJECTED,
            'rejection_reason' => $reason,
            'consequence_status_snapshot' => $consequenceStatus,
            'current_consequence_status' => $consequenceStatus,
            'rejected_at' => now(),
        ]);

        $attempt->update([
            'result_status' => OfflineSalesImport::SYNC_REJECTED,
            'http_status' => 200,
            'response_finished_at' => now(),
        ]);

        $this->auditLogger->log('offline_sync_fingerprint_drift_rejected', $import->fresh());

        return $this->responseFor($import->fresh(), replay: false);
    }

    private function markRejectedOrReview(
        OfflineSalesImport $import,
        OfflineSyncAttempt $attempt,
        string $reason,
        array $rawImport
    ): array {
        $cashCollected = ($rawImport['cash_status'] ?? 'collected') === 'collected';
        $syncStatus = $cashCollected
            ? OfflineSalesImport::SYNC_REVIEW_REQUIRED
            : OfflineSalesImport::SYNC_REJECTED;
        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? $import->cash_status);
        $decision = $syncStatus === OfflineSalesImport::SYNC_REVIEW_REQUIRED
            ? OfflineSyncConflictDecision::review(
                reasonCode: $reason,
                conflictFamily: 'policy',
                reviewSeverity: $cashExposure === 'collected' ? 'high' : 'medium',
                cashExposure: $cashExposure,
                suggestedActionCode: 'manager_review_offline_policy'
            )
            : OfflineSyncConflictDecision::rejected($reason, 'policy', $cashExposure);

        return $this->applyDecisionAndRespond($import, $attempt, $decision);
    }

    private function createSaleFromEnvelope(
        SalesMachineProfile $profile,
        OfflineSalesImport $import,
        array $rawImport,
        User $user
    ): Sale {
        $actorId = $rawImport['user_id'] ?? $rawImport['cashier_id'] ?? $user->id;
        $items = collect($rawImport['items'] ?? [])->map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
        ])->values()->all();

        $result = $this->saleCreationService->createFromPayload(
            tenantId: $profile->tenant_id,
            branchId: $profile->branch_id,
            userId: $actorId,
            clientRequestUuid: (string) $import->offline_transaction_uuid,
            rawItems: $items,
            statutoryDiscount: [],
            isTrainingMode: false,
            terminalId: $profile->id,
        );

        if (!in_array($result['status'], ['created', 'duplicate_seen'], true) || empty($result['sale'])) {
            throw new \RuntimeException('Offline sale creation failed: ' . ($result['status'] ?? 'unknown'));
        }

        /** @var Sale $sale */
        $sale = $result['sale'];
        $sale->update([
            'source' => 'offline_sync',
            'offline_sales_import_id' => $import->id,
            'offline_sequence_number' => $import->offline_sequence_number,
            'offline_submitted_at' => $import->submitted_at,
            'offline_local_created_at' => $rawImport['local_created_at'] ?? $rawImport['terminal_timestamp'] ?? null,
            'offline_posted_at' => now(),
        ]);

        return $sale->fresh('items');
    }

    private function recordCashPayment(Sale $sale, array $rawImport, User $user): \Illuminate\Support\Collection
    {
        if ($sale->payments()->exists()) {
            return $sale->payments()->get();
        }

        $payments = $rawImport['payments'] ?? [];
        if (empty($payments)) {
            throw ValidationException::withMessages([
                'payments' => ['Offline synchronization requires terminal-submitted cash payment evidence.'],
            ]);
        }

        $total = '0.0000';
        $created = collect();

        foreach ($payments as $payment) {
            $method = PaymentMethod::where('tenant_id', $sale->tenant_id)
                ->where('id', $payment['payment_method_id'])
                ->active()
                ->first();

            if (!$method || !$this->isCashMethod($method)) {
                throw ValidationException::withMessages([
                    'payments' => ['Offline synchronization only accepts cash payments.'],
                ]);
            }

            $amount = number_format((float) $payment['amount'], 4, '.', '');
            $total = bcadd($total, $amount, 4);

            $created->push(SalePayment::create([
                'tenant_id' => $sale->tenant_id,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'shift_id' => $rawImport['cashier_shift_id'] ?? null,
                'payment_method_id' => $method->id,
                'payment_type' => $method->type,
                'amount' => $amount,
                'reference_number' => $payment['reference_number'] ?? null,
                'status' => 'recorded',
                'paid_at' => $rawImport['submitted_at'] ?? now(),
                'created_by' => $rawImport['user_id'] ?? $user->id,
            ]));
        }

        if (bccomp($total, (string) $sale->total, 4) !== 0) {
            throw ValidationException::withMessages([
                'payments' => ['Offline cash payment total must match the sale total.'],
            ]);
        }

        $sale->update(['status' => 'paid']);

        return $created;
    }

    private function recordAccountingOutbox(Sale $sale, \Illuminate\Support\Collection $payments): void
    {
        if ($sale->is_training_mode) {
            return;
        }

        try {
            $this->outboxService->recordEvent('sale_paid', $sale, [
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'subtotal' => (string) $sale->subtotal,
                'tax_total' => (string) $sale->tax_total,
                'discount_total' => (string) $sale->discount_total,
                'total' => (string) $sale->total,
                'paid_at' => (string) now(),
                'items' => $sale->items->map(fn ($item) => [
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                ])->toArray(),
                'payments' => $payments->map(fn (SalePayment $payment) => [
                    'id' => $payment->id,
                    'method' => $payment->payment_method_id,
                    'amount' => (string) $payment->amount,
                    'reference' => $payment->reference_number,
                ])->toArray(),
            ]);
        } catch (QueryException $exception) {
            // Accounting outbox is protected by a unique effect key. Replays may
            // observe the existing command, but must not enqueue another one.
            if (!str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw $exception;
            }
        }
    }

    private function computeServerFingerprint(array $rawImport): string
    {
        $material = Arr::only($rawImport, [
            'tenant_id',
            'branch_id',
            'terminal_id',
            'sales_machine_profile_id',
            'terminal_binding_epoch',
            'offline_transaction_uuid',
            'offline_sequence_number',
            'local_sequence',
            'user_id',
            'cashier_id',
            'cashier_shift_id',
            'drawer_session_id',
            'items',
            'client_subtotal',
            'client_tax_total',
            'client_total',
            'payment_method',
            'payments',
            'catalog_version_hash',
            'tax_configuration_version_hash',
            'payment_methods_version_hash',
            'terminal_policy_version_hash',
            'submitted_at',
            'terminal_timestamp',
            'timezone',
        ]);

        return hash('sha256', $this->canonicalJson($material));
    }

    private function isCashMethod(PaymentMethod $method): bool
    {
        return $method->isCash() || strtolower((string) $method->type) === 'cash';
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_decode($this->canonicalJson($value), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function responseFor(OfflineSalesImport $import, bool $replay, ?User $viewer = null): array
    {
        return $this->projectionService->project($import, $replay, $viewer);
    }

    private function emptyConsequenceStatus(): array
    {
        return [
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
}
