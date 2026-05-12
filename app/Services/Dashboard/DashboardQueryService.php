<?php

namespace App\Services\Dashboard;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardQueryService
{
    protected TenantContext $tenantContext;

    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Get the operational pulse for the dashboard.
     *
     * @param User $actor
     * @param string|null $branchId Optional branch filter for multi-branch users
     * @return array
     * @throws AuthorizationException
     */
    public function getPulse(User $actor, ?string $branchId = null): array
    {
        $this->assertAuthorized($actor);

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('Tenant context required for dashboard query.');
        }

        // 1. Resolve Scope and Branch
        $scopeData = $this->resolveScope($actor, $branchId);
        $resolvedBranchId = $scopeData['branch_id'];

        // 2. Define Time Window (Asia/Manila Today)
        $now = Carbon::now('Asia/Manila');
        $startAt = $now->copy()->startOfDay();
        $endAt = $now->copy()->addDay()->startOfDay(); // Half-open interval

        // 3. Fetch Metrics
        $sales = $this->getSalesMetrics($tenant->id, $resolvedBranchId, $startAt, $endAt);
        $payments = $this->getPaymentMetrics($tenant->id, $resolvedBranchId, $startAt, $endAt);
        $syncHealth = $this->getSyncHealth($tenant->id, $resolvedBranchId, $startAt, $endAt);
        $inventory = $this->getInventoryMetrics($tenant->id, $resolvedBranchId);
        $settlementContext = $this->getSettlementContext($tenant->id, $resolvedBranchId);
        $shiftContext = $this->getShiftContext($tenant->id, $resolvedBranchId, $actor);

        // 4. Construct Payload
        return [
            'scope' => [
                'mode' => $scopeData['mode'],
                'tenant_id' => $tenant->id,
                'branch_id' => $resolvedBranchId,
                'label' => $scopeData['label'],
            ],
            'window' => [
                'type' => 'today',
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
                'timezone' => 'Asia/Manila',
            ],
            'sales' => $sales,
            'payments' => $payments,
            'accounting_sync' => $syncHealth,
            'inventory' => $inventory,
            'settlement' => $settlementContext,
            'shift' => $shiftContext,
            'freshness' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'source' => 'live_query',
                'cache_status' => null,
            ],
        ];
    }

    protected function resolveScope(User $actor, ?string $branchId): array
    {
        $hasMultiBranch = $actor->hasPermission('view_multi_branch_dashboard');
        
        // Owner/Admin mode
        if ($hasMultiBranch) {
            if ($branchId) {
                // Verify branch belongs to tenant (implicit via global scope usually, but let's be safe)
                $branch = Branch::where('id', $branchId)->first();
                return [
                    'mode' => 'branch',
                    'branch_id' => $branchId,
                    'label' => 'Branch Pulse: ' . ($branch?->name ?? 'Unknown'),
                ];
            }
            return [
                'mode' => 'tenant',
                'branch_id' => null,
                'label' => 'Tenant Pulse (All Branches)',
            ];
        }

        // Branch Manager mode
        $assignedBranchIds = $actor->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
        
        if (empty($assignedBranchIds)) {
            throw new AuthorizationException('No branches assigned to this user.');
        }

        if ($branchId) {
            if (!in_array($branchId, $assignedBranchIds, true)) {
                throw new AuthorizationException('Access denied to unassigned branch.');
            }
            $branch = Branch::where('id', $branchId)->first();
            return [
                'mode' => 'branch',
                'branch_id' => $branchId,
                'label' => 'Branch Pulse: ' . ($branch?->name ?? 'Unknown'),
            ];
        }

        // Default to first assigned branch
        $firstBranchId = $assignedBranchIds[0];
        $branch = Branch::where('id', $firstBranchId)->first();
        return [
            'mode' => 'branch',
            'branch_id' => $firstBranchId,
            'label' => 'Branch Pulse: ' . ($branch?->name ?? 'Unknown'),
        ];
    }

    protected function getSalesMetrics(string $tenantId, ?string $branchId, Carbon $start, Carbon $end): array
    {
        $query = Sale::where('tenant_id', $tenantId)
            ->where('confirmed_at', '>=', $start)
            ->where('confirmed_at', '<', $end); // Half-open

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $grossTotal = $query->sum('total');
        $count = $query->count();

        // Voids
        $voidQuery = SaleVoid::join('sales', 'sales.id', '=', 'sale_voids.sale_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sale_voids.voided_at', '>=', $start)
            ->where('sale_voids.voided_at', '<', $end);

        if ($branchId) {
            $voidQuery->where('sales.branch_id', $branchId);
        }

        $voidTotal = $voidQuery->sum('sales.total');

        // Refunds
        $refundQuery = SaleRefund::where('tenant_id', $tenantId)
            ->where('refunded_at', '>=', $start)
            ->where('refunded_at', '<', $end);

        if ($branchId) {
            $refundQuery->where('branch_id', $branchId);
        }

        $refundTotal = $refundQuery->sum('refund_total');

        // Net Sales = Gross - Voids - Refunds
        $netTotal = bcsub(bcsub($this->decimalString($grossTotal), $this->decimalString($voidTotal), 4), $this->decimalString($refundTotal), 4);

        return [
            'gross_sales_total' => $this->decimalString($grossTotal),
            'net_sales_total' => $this->decimalString($netTotal),
            'refund_total' => $this->decimalString($refundTotal),
            'void_total' => $this->decimalString($voidTotal),
            'sale_count' => $count,
        ];
    }

    protected function getPaymentMetrics(string $tenantId, ?string $branchId, Carbon $start, Carbon $end): array
    {
        $query = SalePayment::where('tenant_id', $tenantId)
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $total = $query->sum('amount');

        $byMethod = (clone $query)
            ->select('payment_method_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->with('paymentMethod:id,code,name')
            ->groupBy('payment_method_id')
            ->get()
            ->map(function ($p) {
                return [
                    'code' => $p->paymentMethod?->code,
                    'name' => $p->paymentMethod?->name,
                    'total' => $this->decimalString($p->total),
                    'count' => (int) $p->count,
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $this->decimalString($total),
            'by_method' => $byMethod,
        ];
    }

    protected function getSyncHealth(string $tenantId, ?string $branchId, Carbon $start, Carbon $end): array
    {
        $query = AccountingOutbox::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $counts = [
            'pending' => 0,
            'processing' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        $results = (clone $query)
            ->select('sync_status', DB::raw('COUNT(*) as count'))
            ->groupBy('sync_status')
            ->get();

        foreach ($results as $row) {
            $status = (string) $row->sync_status;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row->count;
            }
        }

        return $counts;
    }

    protected function getInventoryMetrics(string $tenantId, ?string $branchId): array
    {
        $query = BranchInventory::where('tenant_id', $tenantId)->lowStock();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $lowStockCount = (clone $query)->count();

        $criticalItems = $query->with(['product:id,name,sku', 'branch:id,name'])
            ->limit(10) // Limit to top 10 critical items for the pulse
            ->get()
            ->map(fn ($i) => [
                'product_id' => $i->product_id,
                'sku' => $i->product?->sku,
                'name' => $i->product?->name,
                'branch_id' => $i->branch_id,
                'current_stock' => $this->decimalString($i->current_stock),
                'reorder_level' => $this->decimalString($i->reorder_level),
            ])
            ->all();

        return [
            'low_stock_count' => $lowStockCount,
            'critical_items' => $criticalItems,
        ];
    }

    protected function getSettlementContext(string $tenantId, ?string $branchId): array
    {
        // Latest Locked Period
        $latestLockedQuery = SettlementPeriod::where('tenant_id', $tenantId)
            ->where('status', SettlementPeriod::STATUS_LOCKED);
        
        if ($branchId) {
            $latestLockedQuery->where('branch_id', $branchId);
        }
        
        $latestLocked = $latestLockedQuery->latest('locked_at')->first();

        // Yesterday's Status (Calendar day before today in Manila)
        $yesterdayStart = Carbon::now('Asia/Manila')->subDay()->startOfDay();
        $yesterdayEnd = Carbon::now('Asia/Manila')->subDay()->endOfDay();

        $yesterdayPeriodQuery = SettlementPeriod::where('tenant_id', $tenantId)
            ->where(function ($q) use ($yesterdayStart, $yesterdayEnd) {
                // Find period that overlaps significantly with yesterday or is the latest one before today
                $q->where('period_start_at', '>=', $yesterdayStart)
                  ->where('period_start_at', '<=', $yesterdayEnd);
            });

        if ($branchId) {
            $yesterdayPeriodQuery->where('branch_id', $branchId);
        }

        $yesterdayStatus = $yesterdayPeriodQuery->latest('period_start_at')->value('status');

        return [
            'latest_locked_period_id' => $latestLocked?->id,
            'latest_locked_label' => $latestLocked ? "{$latestLocked->period_start_at->format('M d')} - {$latestLocked->period_end_at->format('M d')}" : null,
            'locked_at' => $latestLocked?->locked_at?->toIso8601String(),
            'period_start_at' => $latestLocked?->period_start_at?->toIso8601String(),
            'period_end_at' => $latestLocked?->period_end_at?->toIso8601String(),
            'has_snapshot' => $latestLocked ? \App\Models\SettlementSnapshot::where('settlement_period_id', $latestLocked->id)->exists() : false,
            'yesterday_status' => $yesterdayStatus,
        ];
    }

    protected function getShiftContext(string $tenantId, ?string $branchId, User $actor): array
    {
        // 1. Active Shift for User (if cashier/branch-scoped)
        $activeShift = \App\Models\Shift::where('tenant_id', $tenantId)
            ->where('cashier_id', $actor->id)
            ->where('status', \App\Models\Shift::STATUS_OPEN)
            ->first();

        // 2. Pending Reviews (for managers)
        $pendingQuery = \App\Models\Shift::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Shift::STATUS_CLOSING_SUBMITTED);
        
        if ($branchId) {
            $pendingQuery->where('branch_id', $branchId);
        }
        
        $pendingCount = $pendingQuery->count();

        return [
            'active_shift_id' => $activeShift?->id,
            'active_shift_status' => $activeShift?->status,
            'active_shift_opened_at' => $activeShift?->opened_at?->toIso8601String(),
            'pending_review_count' => $pendingCount,
            'is_pos_user' => $actor->hasPermission('create_sale'),
        ];
    }

    protected function assertAuthorized(User $actor): void
    {
        if (!$actor->hasPermission('view_reports')) {
            throw new AuthorizationException('Unauthorized. Permission required: view_reports');
        }
    }

    protected function decimalString(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
