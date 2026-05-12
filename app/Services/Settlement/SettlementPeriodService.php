<?php

namespace App\Services\Settlement;

use App\Models\Branch;
use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SettlementPeriodService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger,
    ) {}

    public function create(array $attributes, User $actor): SettlementPeriod
    {
        $this->assertAuthorized($actor);

        $tenantId = $this->tenantContext->getTenantId();
        if (blank($tenantId)) {
            throw new RuntimeException('Tenant context is required to manage settlement periods.');
        }

        $branchId = $this->normalizeNullableString($attributes['branch_id'] ?? null);
        $this->assertCanManageScope($actor, $branchId);
        $this->assertBranchExistsInTenant($branchId);

        $periodStartAt = $this->normalizeDateTime($attributes['period_start_at'] ?? null, 'period_start_at');
        $periodEndAt = $this->normalizeDateTime($attributes['period_end_at'] ?? null, 'period_end_at');
        $this->assertPeriodBounds($periodStartAt, $periodEndAt);
        $this->assertNoOverlap($periodStartAt, $periodEndAt, $branchId);

        $period = SettlementPeriod::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'period_start_at' => $periodStartAt,
            'period_end_at' => $periodEndAt,
            'status' => SettlementPeriod::STATUS_OPEN,
            'opened_by' => $actor->id,
            'opened_at' => now(),
            'closing_notes' => $this->normalizeNullableString($attributes['closing_notes'] ?? null),
            'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
        ]);

        $this->auditLogger->log(
            action: 'settlement_period_opened',
            auditable: $period,
            afterValues: $this->auditPayload($period),
        );

        return $period->refresh();
    }

    public function markInReview(SettlementPeriod $period, User $actor): SettlementPeriod
    {
        return $this->transition($period, SettlementPeriod::STATUS_IN_REVIEW, $actor);
    }

    public function approve(SettlementPeriod $period, User $actor): SettlementPeriod
    {
        return $this->transition($period, SettlementPeriod::STATUS_APPROVED, $actor);
    }

    public function lock(SettlementPeriod $period, User $actor, ?string $closingNotes = null): SettlementPeriod
    {
        $this->assertNoBlockingShiftsForSettlement($period);

        return $this->transition($period, SettlementPeriod::STATUS_LOCKED, $actor, [
            'closing_notes' => $this->normalizeNullableString($closingNotes),
        ]);
    }

    /**
     * Check if a settlement period is locked for a specific timestamp and branch.
     */
    public function isLockedForTimestamp(\Carbon\CarbonInterface $timestamp, ?string $branchId = null): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (blank($tenantId)) {
            return false;
        }

        return SettlementPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->where('period_start_at', '<=', $timestamp)
            ->where('period_end_at', '>', $timestamp) // Half-open interval
            ->where('status', SettlementPeriod::STATUS_LOCKED)
            ->exists();
    }

    /**
     * Prevent locking if open or pending shifts exist in scope.
     */
    public function assertNoBlockingShiftsForSettlement(SettlementPeriod $period): void
    {
        $blockingCount = \App\Models\Shift::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $period->tenant_id)
            ->where(function (Builder $query) use ($period) {
                if ($period->branch_id) {
                    $query->where('branch_id', $period->branch_id);
                }
            })
            ->where(function (Builder $query) use ($period) {
                $query->where(function ($q) use ($period) {
                    $q->where('opened_at', '>=', $period->period_start_at)
                      ->where('opened_at', '<', $period->period_end_at);
                })->orWhere(function ($q) use ($period) {
                    $q->where('closing_submitted_at', '>=', $period->period_start_at)
                      ->where('closing_submitted_at', '<', $period->period_end_at);
                });
            })
            ->whereIn('status', [\App\Models\Shift::STATUS_OPEN, \App\Models\Shift::STATUS_CLOSING_SUBMITTED])
            ->count();

        if ($blockingCount > 0) {
            throw new RuntimeException("Cannot lock settlement period. {$blockingCount} open/pending shifts exist in scope.");
        }
    }

    public function reopen(SettlementPeriod $period, User $actor, string $reason): SettlementPeriod
    {
        return $this->transition($period, SettlementPeriod::STATUS_REOPENED, $actor, [
            'reopen_reason' => $this->normalizeNullableString($reason),
        ]);
    }

    public function returnToReview(SettlementPeriod $period, User $actor): SettlementPeriod
    {
        return $this->transition($period, SettlementPeriod::STATUS_IN_REVIEW, $actor);
    }

    public function findVisible(string $periodId, User $actor): SettlementPeriod
    {
        $this->assertAuthorized($actor);

        $query = SettlementPeriod::query();
        $allowedBranchIds = $this->allowedBranchIds($actor);

        if ($allowedBranchIds !== null) {
            $query->where(function (Builder $builder) use ($allowedBranchIds) {
                $builder->whereNull('branch_id');

                if ($allowedBranchIds !== []) {
                    $builder->orWhereIn('branch_id', $allowedBranchIds);
                }
            });
        }

        return $query->findOrFail($periodId);
    }

    protected function transition(SettlementPeriod $period, string $targetStatus, User $actor, array $extra = []): SettlementPeriod
    {
        $this->assertAuthorized($actor);
        $this->assertPeriodBelongsToActiveTenant($period);
        $this->assertCanManageScope($actor, $period->branch_id);
        $this->assertValidTransition($period->status, $targetStatus);

        if ($targetStatus === SettlementPeriod::STATUS_REOPENED && blank($extra['reopen_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reopen_reason' => 'A reopen reason is required to reopen a locked settlement period.',
            ]);
        }

        $lockSnapshot = null;
        if ($targetStatus === SettlementPeriod::STATUS_LOCKED) {
            $lockSnapshot = $this->latestSnapshotForPeriod($period);

            if (!$lockSnapshot) {
                throw ValidationException::withMessages([
                    'snapshot' => 'Settlement period must have a review snapshot before locking.',
                ]);
            }
        }

        $before = $this->auditPayload($period);
        $updates = ['status' => $targetStatus];

        if ($targetStatus === SettlementPeriod::STATUS_IN_REVIEW) {
            $updates['submitted_by'] = $actor->id;
            $updates['submitted_at'] = now();
        }

        if ($targetStatus === SettlementPeriod::STATUS_APPROVED) {
            $updates['approved_by'] = $actor->id;
            $updates['approved_at'] = now();
        }

        if ($targetStatus === SettlementPeriod::STATUS_LOCKED) {
            $updates['locked_by'] = $actor->id;
            $updates['locked_at'] = now();
            if (array_key_exists('closing_notes', $extra)) {
                $updates['closing_notes'] = $extra['closing_notes'];
            }
        }

        if ($targetStatus === SettlementPeriod::STATUS_REOPENED) {
            $updates['reopened_by'] = $actor->id;
            $updates['reopened_at'] = now();
            $updates['reopen_reason'] = $extra['reopen_reason'];
        }

        $period->forceFill($updates)->save();

        $this->auditLogger->log(
            action: 'settlement_period_' . $targetStatus,
            auditable: $period,
            beforeValues: $before,
            afterValues: $this->auditPayload($period),
            reason: $targetStatus === SettlementPeriod::STATUS_REOPENED ? $period->reopen_reason : null,
            metadata: $targetStatus === SettlementPeriod::STATUS_LOCKED && $lockSnapshot
                ? [
                    'lock_snapshot_id' => $lockSnapshot->id,
                    'lock_snapshot_type' => $lockSnapshot->snapshot_type,
                    'lock_snapshot_created_at' => $lockSnapshot->created_at?->toISOString(),
                ]
                : [],
        );

        return $period->refresh();
    }

    protected function assertPeriodBelongsToActiveTenant(SettlementPeriod $period): void
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (blank($tenantId)) {
            throw new RuntimeException('Tenant context is required to manage settlement periods.');
        }

        if ($period->tenant_id !== $tenantId) {
            throw new AuthorizationException('Settlement period is outside the active tenant scope.');
        }
    }

    protected function assertAuthorized(User $actor): void
    {
        if (!$actor->hasPermission('manage_settlement_periods')) {
            throw new AuthorizationException('Unauthorized. Permission required: manage_settlement_periods');
        }
    }

    protected function assertCanManageScope(User $actor, ?string $branchId): void
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        if ($branchId === null) {
            throw new AuthorizationException('Branch-scoped users cannot manage tenant-wide settlement periods.');
        }

        $allowedBranchIds = $this->allowedBranchIds($actor) ?? [];
        if (!in_array($branchId, $allowedBranchIds, true)) {
            throw new AuthorizationException('Branch scope access denied for this settlement period.');
        }
    }

    protected function allowedBranchIds(User $actor): ?array
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return null;
        }

        return $actor->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function assertBranchExistsInTenant(?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        Branch::query()->whereKey($branchId)->firstOrFail();
    }

    protected function assertPeriodBounds($periodStartAt, $periodEndAt): void
    {
        if ($periodStartAt->greaterThanOrEqualTo($periodEndAt)) {
            throw ValidationException::withMessages([
                'period_end_at' => 'The settlement period end must be after the start.',
            ]);
        }
    }

    protected function assertNoOverlap($periodStartAt, $periodEndAt, ?string $branchId, ?string $exceptId = null): void
    {
        $query = SettlementPeriod::query()
            ->where('period_start_at', '<', $periodEndAt)
            ->where('period_end_at', '>', $periodStartAt);

        if ($branchId === null) {
            $query->whereNull('branch_id');
        } else {
            $query->where('branch_id', $branchId);
        }

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'period' => 'A settlement period already overlaps this scope and time range.',
            ]);
        }
    }

    protected function assertValidTransition(string $currentStatus, string $targetStatus): void
    {
        $allowed = [
            SettlementPeriod::STATUS_OPEN => [SettlementPeriod::STATUS_IN_REVIEW],
            SettlementPeriod::STATUS_IN_REVIEW => [SettlementPeriod::STATUS_APPROVED],
            SettlementPeriod::STATUS_APPROVED => [SettlementPeriod::STATUS_LOCKED],
            SettlementPeriod::STATUS_LOCKED => [SettlementPeriod::STATUS_REOPENED],
            SettlementPeriod::STATUS_REOPENED => [SettlementPeriod::STATUS_IN_REVIEW],
        ];

        if (!in_array($targetStatus, $allowed[$currentStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Invalid settlement period transition from {$currentStatus} to {$targetStatus}.",
            ]);
        }
    }

    protected function normalizeDateTime(mixed $value, string $field)
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                $field => 'This settlement period field is required.',
            ]);
        }

        return now()->parse($value);
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $value ?: null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function latestSnapshotForPeriod(SettlementPeriod $period): ?SettlementSnapshot
    {
        return SettlementSnapshot::query()
            ->where('settlement_period_id', $period->id)
            ->orderByDesc('created_at')
            ->first();
    }

    protected function auditPayload(SettlementPeriod $period): array
    {
        return [
            'branch_id' => $period->branch_id,
            'period_start_at' => $period->period_start_at?->toISOString(),
            'period_end_at' => $period->period_end_at?->toISOString(),
            'status' => $period->status,
            'opened_by' => $period->opened_by,
            'submitted_by' => $period->submitted_by,
            'approved_by' => $period->approved_by,
            'locked_by' => $period->locked_by,
            'reopened_by' => $period->reopened_by,
            'closing_notes' => $period->closing_notes,
            'reopen_reason' => $period->reopen_reason,
        ];
    }
}