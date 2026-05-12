<?php

namespace App\Services\Settlement;

use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SettlementSnapshotService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger,
        protected SettlementSummaryQueryService $summaryQueryService,
        protected SettlementVarianceQueryService $varianceQueryService,
    ) {}

    public function create(
        SettlementPeriod $period,
        User $actor,
        string $snapshotType = SettlementSnapshot::TYPE_REVIEW,
    ): SettlementSnapshot {
        $tenantId = $this->tenantContext->getTenantId();
        if (blank($tenantId)) {
            throw new RuntimeException('Tenant context is required to create settlement snapshots.');
        }

        if ($period->tenant_id !== $tenantId) {
            throw new AuthorizationException('Settlement period is outside the active tenant scope.');
        }

        $this->assertSnapshotType($snapshotType);

        $summaryPayload = $this->summaryQueryService->summarize($period, $actor);
        $variancePayload = $this->varianceQueryService->summarize($period, $actor);

        $snapshot = SettlementSnapshot::create([
            'tenant_id' => $period->tenant_id,
            'branch_id' => $period->branch_id,
            'settlement_period_id' => $period->id,
            'snapshot_type' => $snapshotType,
            'summary_payload' => $summaryPayload,
            'variance_payload' => $variancePayload,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'settlement_snapshot_created',
            auditable: $snapshot,
            afterValues: $this->auditPayload($snapshot),
            metadata: [
                'settlement_period_id' => $period->id,
                'snapshot_type' => $snapshot->snapshot_type,
            ],
        );

        return $snapshot->refresh();
    }

    protected function assertSnapshotType(string $snapshotType): void
    {
        if (!in_array($snapshotType, SettlementSnapshot::supportedTypes(), true)) {
            throw ValidationException::withMessages([
                'snapshot_type' => 'Unsupported settlement snapshot type.',
            ]);
        }
    }

    protected function auditPayload(SettlementSnapshot $snapshot): array
    {
        return [
            'settlement_period_id' => $snapshot->settlement_period_id,
            'branch_id' => $snapshot->branch_id,
            'snapshot_type' => $snapshot->snapshot_type,
            'created_by' => $snapshot->created_by,
            'created_at' => $snapshot->created_at?->toISOString(),
        ];
    }
}