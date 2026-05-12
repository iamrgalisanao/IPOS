<?php

namespace App\Services\Settlement;

use App\Models\AccountingOutbox;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SettlementSummaryQueryService
{
    public function summarize(SettlementPeriod $period, User $actor): array
    {
        $this->assertAuthorized($actor);
        $this->assertCanViewPeriod($period, $actor);

        $salesQuery = Sale::query();
        $paymentsQuery = SalePayment::query()->with('paymentMethod:id,code,name');
        $refundsQuery = SaleRefund::query();
        $voidsQuery = SaleVoid::query()->join('sales', 'sales.id', '=', 'sale_voids.sale_id');
        $outboxQuery = AccountingOutbox::query();

        $this->applyScopeAndBoundary($salesQuery, $period, 'confirmed_at');
        $this->applyScopeAndBoundary($paymentsQuery, $period, 'paid_at');
        $this->applyScopeAndBoundary($refundsQuery, $period, 'refunded_at');
        $this->applyScopeAndBoundary($voidsQuery, $period, 'sale_voids.voided_at');
        $this->applyScopeAndBoundary($outboxQuery, $period, 'created_at');

        $grossSalesTotal = $this->decimalString($salesQuery->sum('total'));
        $saleCount = (clone $salesQuery)->count();

        $refundTotal = $this->decimalString($refundsQuery->sum('refund_total'));
        $refundCount = (clone $refundsQuery)->count();

        $voidTotal = $this->decimalString($voidsQuery->sum('sales.total'));
        $voidCount = (clone $voidsQuery)->count('sale_voids.id');

        $netSalesTotal = $this->decimalString(
            bcsub(
                bcsub($grossSalesTotal, $voidTotal, 4),
                $refundTotal,
                4
            )
        );

        $paymentTotal = $this->decimalString($paymentsQuery->sum('amount'));
        $paymentsByMethod = (clone $paymentsQuery)
            ->select('payment_method_id', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method_id')
            ->get()
            ->map(function (SalePayment $payment) {
                return [
                    'payment_method_id' => $payment->payment_method_id,
                    'code' => $payment->paymentMethod?->code,
                    'name' => $payment->paymentMethod?->name,
                    'total' => $this->decimalString($payment->getAttribute('total')),
                ];
            })
            ->values()
            ->all();

        $syncCounts = [
            'pending' => 0,
            'processing' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        foreach ((clone $outboxQuery)
            ->select('sync_status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('sync_status')
            ->get() as $row) {
            $status = (string) $row->sync_status;
            if (array_key_exists($status, $syncCounts)) {
                $syncCounts[$status] = (int) $row->aggregate_count;
            }
        }

        return [
            'period' => [
                'id' => $period->id,
                'tenant_id' => $period->tenant_id,
                'branch_id' => $period->branch_id,
                'period_start_at' => $period->period_start_at?->toISOString(),
                'period_end_at' => $period->period_end_at?->toISOString(),
                'status' => $period->status,
            ],
            'sales' => [
                'gross_sales_total' => $grossSalesTotal,
                'net_sales_total' => $netSalesTotal,
                'sale_count' => $saleCount,
                'void_total' => $voidTotal,
                'void_count' => $voidCount,
                'refund_total' => $refundTotal,
                'refund_count' => $refundCount,
            ],
            'payments' => [
                'total' => $paymentTotal,
                'by_method' => $paymentsByMethod,
            ],
            'accounting_sync' => $syncCounts,
        ];
    }

    protected function assertAuthorized(User $actor): void
    {
        if (!$actor->hasPermission('manage_settlement_periods') && !$actor->hasPermission('view_settlement_periods')) {
            throw new AuthorizationException('Unauthorized. Permission required: view_settlement_periods');
        }
    }

    protected function assertCanViewPeriod(SettlementPeriod $period, User $actor): void
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        if ($period->branch_id === null) {
            throw new AuthorizationException('Branch-scoped users cannot summarize tenant-wide settlement periods.');
        }

        $allowedBranchIds = $actor->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
        if (!in_array($period->branch_id, $allowedBranchIds, true)) {
            throw new AuthorizationException('Branch scope access denied for this settlement summary.');
        }
    }

    protected function applyScopeAndBoundary(Builder $query, SettlementPeriod $period, string $timestampColumn): void
    {
        if ($period->branch_id !== null) {
            $query->where($query->getModel()->getTable() . '.branch_id', $period->branch_id);
        }

        $query
            ->where($timestampColumn, '>=', $period->period_start_at)
            ->where($timestampColumn, '<=', $period->period_end_at);
    }

    protected function decimalString(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
