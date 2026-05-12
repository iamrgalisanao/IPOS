<?php

namespace App\Http\Controllers\Settlement;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SettlementPeriod;
use App\Models\User;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\Settlement\SettlementSummaryQueryService;
use App\Services\Settlement\SettlementVarianceQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettlementReviewController extends Controller
{
    public function __construct(
        protected SettlementPeriodService $periodService,
        protected SettlementSummaryQueryService $summaryService,
        protected SettlementVarianceQueryService $varianceService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeView($request);

        $periods = $this->visiblePeriods($request->user())
            ->paginate(15)
            ->withQueryString();

        $periods->through(fn (SettlementPeriod $period) => $this->serializePeriodSummary($period));

        return Inertia::render('Settlement/Periods/Index', [
            'periods' => $periods,
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function show(Request $request, string $period): Response
    {
        $this->authorizeView($request);

        $periodModel = $this->findVisiblePeriod($period, $request->user());
        abort_if(!$periodModel, 404);

        $periodModel->load([
            'branch:id,name',
            'snapshots.creator:id,name',
            'latestSnapshot.creator:id,name',
        ]);

        $summary = $this->summaryService->summarize($periodModel, $request->user());
        $variance = $this->varianceService->summarize($periodModel, $request->user());
        $latestSnapshot = $periodModel->latestSnapshot;

        return Inertia::render('Settlement/Periods/Show', [
            'period' => $this->serializePeriodDetail($periodModel),
            'summary' => $summary,
            'variance' => $variance,
            'snapshots' => $this->serializeSnapshots($periodModel),
            'lockReadiness' => $this->lockReadiness($periodModel, $request->user(), $latestSnapshot !== null),
            'actions' => $this->actionVisibility($periodModel, $request->user(), $latestSnapshot !== null),
            'permissions' => [
                'can_export_reports' => $request->user()?->hasPermission('export_reports'),
                'can_export_accounting' => $request->user()?->hasPermission('export_accounting_reports'),
            ],
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function approve(Request $request, string $period): RedirectResponse
    {
        $this->authorizeManage($request);

        $periodModel = $this->periodService->findVisible($period, $request->user());
        abort_if($periodModel->status !== SettlementPeriod::STATUS_IN_REVIEW, 422, 'Only in-review settlement periods can be approved.');

        $approved = $this->periodService->approve($periodModel, $request->user());

        return redirect()
            ->route('settlement.periods.show', $approved->id)
            ->with('status', 'Settlement period approved.');
    }

    public function lock(Request $request, string $period): RedirectResponse
    {
        $this->authorizeManage($request);

        $periodModel = $this->periodService->findVisible($period, $request->user());
        abort_if($periodModel->status !== SettlementPeriod::STATUS_APPROVED, 422, 'Only approved settlement periods can be locked.');

        $locked = $this->periodService->lock($periodModel, $request->user());

        return redirect()
            ->route('settlement.periods.show', $locked->id)
            ->with('status', 'Settlement period locked.');
    }

    public function reopen(Request $request, string $period): RedirectResponse
    {
        $this->authorizeManage($request);

        $periodModel = $this->periodService->findVisible($period, $request->user());
        abort_if($periodModel->status !== SettlementPeriod::STATUS_LOCKED, 422, 'Only locked settlement periods can be reopened.');

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $reopened = $this->periodService->reopen($periodModel, $request->user(), $validated['reason']);

        return redirect()
            ->route('settlement.periods.show', $reopened->id)
            ->with('status', 'Settlement period reopened.');
    }

    protected function authorizeView(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission('view_settlement_periods') || $request->user()?->hasPermission('manage_settlement_periods'),
            403,
            'Unauthorized. Permission required: view_settlement_periods'
        );
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission('manage_settlement_periods'),
            403,
            'Unauthorized. Permission required: manage_settlement_periods'
        );
    }

    protected function visiblePeriods(User $user): Builder
    {
        $query = SettlementPeriod::query()
            ->with(['branch:id,name', 'latestSnapshot'])
            ->withCount('snapshots')
            ->orderByDesc('period_end_at')
            ->orderByDesc('created_at');

        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return $query;
        }

        $branchIds = $user->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();

        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotNull('branch_id')
            ->whereIn('branch_id', $branchIds);
    }

    protected function findVisiblePeriod(string $periodId, User $user): ?SettlementPeriod
    {
        $query = $this->visiblePeriods($user)->whereKey($periodId);

        return $query->first();
    }

    protected function serializePeriodSummary(SettlementPeriod $period): array
    {
        $latestSnapshot = $period->latestSnapshot;

        return [
            'id' => $period->id,
            'tenant_id' => $period->tenant_id,
            'branch_id' => $period->branch_id,
            'branch_name' => $period->branch?->name,
            'scope_label' => $period->branch?->name ?: 'Tenant-wide',
            'period_start_at' => $period->period_start_at?->toISOString(),
            'period_end_at' => $period->period_end_at?->toISOString(),
            'status' => $period->status,
            'opened_at' => $period->opened_at?->toISOString(),
            'approved_at' => $period->approved_at?->toISOString(),
            'locked_at' => $period->locked_at?->toISOString(),
            'snapshot_count' => $period->snapshots_count ?? 0,
            'latest_snapshot_created_at' => $latestSnapshot?->created_at?->toISOString(),
            'latest_variance_count' => $latestSnapshot?->variance_payload['summary']['total_variance_count'] ?? null,
        ];
    }

    protected function serializePeriodDetail(SettlementPeriod $period): array
    {
        return $this->serializePeriodSummary($period) + [
            'opened_by' => $period->opened_by,
            'submitted_by' => $period->submitted_by,
            'locked_by' => $period->locked_by,
            'reopened_by' => $period->reopened_by,
            'closing_notes' => $period->closing_notes,
            'reopen_reason' => $period->reopen_reason,
        ];
    }

    protected function serializeSnapshots(SettlementPeriod $period): array
    {
        return $period->snapshots->map(fn ($snapshot) => [
            'id' => $snapshot->id,
            'snapshot_type' => $snapshot->snapshot_type,
            'created_at' => $snapshot->created_at?->toISOString(),
            'created_by' => $snapshot->created_by,
            'created_by_name' => $snapshot->creator?->name,
            'summary_total_variance_count' => $snapshot->variance_payload['summary']['total_variance_count'] ?? null,
        ])->values()->all();
    }

    protected function lockReadiness(SettlementPeriod $period, User $user, bool $hasSnapshot): array
    {
        $hasManagePermission = $user->hasPermission('manage_settlement_periods');
        $canManageScope = $user->hasPermission('view_multi_branch_dashboard')
            || ($period->branch_id !== null && $user->branches()->where('branch_id', $period->branch_id)->exists());

        $statusIsApproved = $period->status === SettlementPeriod::STATUS_APPROVED;
        $canLock = $hasSnapshot && $statusIsApproved && $hasManagePermission && $canManageScope;

        return [
            'has_snapshot' => $hasSnapshot,
            'status_is_approved' => $statusIsApproved,
            'has_manage_permission' => $hasManagePermission,
            'has_scope_access' => $canManageScope,
            'can_lock' => $canLock,
            'reason' => $canLock
                ? 'Ready to lock.'
                : $this->lockReadinessReason($hasSnapshot, $statusIsApproved, $hasManagePermission, $canManageScope),
        ];
    }

    protected function actionVisibility(SettlementPeriod $period, User $user, bool $hasSnapshot): array
    {
        $canManageActions = $user->hasPermission('manage_settlement_periods')
            && ($user->hasPermission('view_multi_branch_dashboard')
                || ($period->branch_id !== null && $user->branches()->where('branch_id', $period->branch_id)->exists()));

        $canApprove = $canManageActions && $period->status === SettlementPeriod::STATUS_IN_REVIEW;
        $canLock = $canManageActions && $period->status === SettlementPeriod::STATUS_APPROVED;
        $canReopen = $canManageActions && $period->status === SettlementPeriod::STATUS_LOCKED;
        $lockRequiresSnapshot = $canLock && !$hasSnapshot;

        return [
            'can_manage_actions' => $canManageActions,
            'can_approve' => $canApprove,
            'can_lock' => $canLock,
            'can_reopen' => $canReopen,
            'lock_requires_snapshot' => $lockRequiresSnapshot,
            'approve_label' => $canApprove ? 'Approve period' : null,
            'lock_label' => $canLock ? ($lockRequiresSnapshot ? 'Snapshot required before locking' : 'Lock period') : null,
            'reopen_label' => $canReopen ? 'Reopen period' : null,
        ];
    }

    protected function lockReadinessReason(bool $hasSnapshot, bool $statusIsApproved, bool $hasManagePermission, bool $hasScopeAccess): string
    {
        if (!$hasManagePermission) {
            return 'Locking requires settlement management permission.';
        }

        if (!$hasScopeAccess) {
            return 'Current user cannot manage this settlement scope.';
        }

        if (!$hasSnapshot) {
            return 'A review snapshot is required before locking.';
        }

        if (!$statusIsApproved) {
            return 'Settlement period must be approved before locking.';
        }

        return 'Ready to lock.';
    }
}
