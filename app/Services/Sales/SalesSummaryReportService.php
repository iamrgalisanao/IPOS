<?php

namespace App\Services\Sales;

use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SalesSummaryReportService
{
    public function __construct(
        protected SalesHistoryQueryService $salesHistoryQuery,
    ) {}

    public function build(User $user, array $filters): array
    {
        $queryFilters = $this->queryFilters($filters);
        $builder = $this->salesHistoryQuery->getBuilder($user, $queryFilters);

        return [
            'filters' => $this->displayFilters($filters),
            'kpis' => $this->kpis($builder),
            'payment_breakdown' => $this->paymentBreakdown($builder),
            'status_breakdown' => $this->statusBreakdown($builder),
            'recent_transactions' => $this->recentTransactions($builder),
            'filter_options' => $this->filterOptions($user),
        ];
    }

    public function exportCsv(User $user, array $filters): string
    {
        $report = $this->build($user, $filters);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['IPOS Sales Summary Report']);
        fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
        fputcsv($handle, ['Start Date', $report['filters']['start_date'] ?: 'All']);
        fputcsv($handle, ['End Date', $report['filters']['end_date'] ?: 'All']);
        fputcsv($handle, ['Branch ID', $report['filters']['branch_id'] ?: 'All visible branches']);
        fputcsv($handle, ['Status', $report['filters']['status'] ?: 'All']);
        fputcsv($handle, ['Payment Method ID', $report['filters']['payment_method_id'] ?: 'All']);
        fputcsv($handle, ['Cashier ID', $report['filters']['cashier_id'] ?: 'All']);
        fputcsv($handle, []);

        fputcsv($handle, ['KPI', 'Value']);
        foreach ($report['kpis'] as $key => $value) {
            fputcsv($handle, [$this->sanitizeCsv($this->label($key)), $this->sanitizeCsv((string) $value)]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Payment Method', 'Payment Count', 'Total Amount']);
        foreach ($report['payment_breakdown'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['payment_method_name']),
                $row['payment_count'],
                $row['total_amount'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Status', 'Transaction Count', 'Total Amount']);
        foreach ($report['status_breakdown'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['status']),
                $row['transaction_count'],
                $row['total_amount'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Note', 'This report summarizes existing sales records only. It does not change tax, settlement, accounting, receipt, or transaction state.']);

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    public function queryFilters(array $filters): array
    {
        $queryFilters = $filters;

        if (! empty($filters['start_date'])) {
            $queryFilters['start_date'] = Carbon::parse($filters['start_date'])->startOfDay()->toDateTimeString();
        }

        if (! empty($filters['end_date'])) {
            $queryFilters['end_date'] = Carbon::parse($filters['end_date'])->endOfDay()->toDateTimeString();
        }

        return $queryFilters;
    }

    protected function displayFilters(array $filters): array
    {
        return [
            'start_date' => $filters['start_date'] ?? '',
            'end_date' => $filters['end_date'] ?? '',
            'branch_id' => $filters['branch_id'] ?? '',
            'status' => $filters['status'] ?? '',
            'payment_method_id' => $filters['payment_method_id'] ?? '',
            'cashier_id' => $filters['cashier_id'] ?? '',
        ];
    }

    protected function kpis(Builder $builder): array
    {
        $row = (clone $builder)
            ->reorder()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(COALESCE(gross_sales_amount, subtotal + discount_total, total)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(total), 0) as net_sales')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count")
            ->selectRaw("SUM(CASE WHEN status IN ('created', 'pending', 'draft') THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN status IN ('voided', 'refunded') OR is_reversal IS TRUE THEN 1 ELSE 0 END) as void_refund_count")
            ->selectRaw('COALESCE(SUM(discount_total), 0) as discount_total')
            ->first();

        $transactionCount = (int) ($row->transaction_count ?? 0);
        $netSales = round((float) ($row->net_sales ?? 0), 4);

        return [
            'gross_sales' => round((float) ($row->gross_sales ?? 0), 4),
            'net_sales' => $netSales,
            'transaction_count' => $transactionCount,
            'paid_count' => (int) ($row->paid_count ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'void_refund_count' => (int) ($row->void_refund_count ?? 0),
            'discount_total' => round((float) ($row->discount_total ?? 0), 4),
            'average_transaction_value' => $transactionCount > 0 ? round($netSales / $transactionCount, 4) : 0.0,
        ];
    }

    protected function paymentBreakdown(Builder $builder): array
    {
        $saleIds = (clone $builder)->reorder()->select('sales.id');

        return SalePayment::query()
            ->whereIn('sale_payments.sale_id', $saleIds)
            ->leftJoin('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->selectRaw("COALESCE(payment_methods.name, sale_payments.provider, 'Unspecified') as payment_method_name")
            ->selectRaw('COUNT(*) as payment_count')
            ->selectRaw('COALESCE(SUM(sale_payments.amount), 0) as total_amount')
            ->groupBy('payment_method_name')
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn ($row) => [
                'payment_method_name' => (string) $row->payment_method_name,
                'payment_count' => (int) $row->payment_count,
                'total_amount' => round((float) $row->total_amount, 4),
            ])
            ->values()
            ->all();
    }

    protected function statusBreakdown(Builder $builder): array
    {
        return (clone $builder)
            ->reorder()
            ->select('status')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_amount')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'transaction_count' => (int) $row->transaction_count,
                'total_amount' => round((float) $row->total_amount, 4),
            ])
            ->values()
            ->all();
    }

    protected function recentTransactions(Builder $builder): array
    {
        return (clone $builder)
            ->limit(10)
            ->get()
            ->map(fn ($sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'status' => $sale->status,
                'branch_name' => $sale->branch?->name,
                'cashier_name' => $sale->user?->name,
                'total' => round((float) $sale->total, 4),
                'timestamp' => optional($sale->reporting_basis_at ?? $sale->confirmed_at ?? $sale->created_at)->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    protected function filterOptions(User $user): array
    {
        $visibleSales = $this->salesHistoryQuery->getBuilder($user, []);

        $branchOptions = (clone $visibleSales)
            ->reorder()
            ->whereNotNull('branch_id')
            ->with('branch:id,name')
            ->select('branch_id')
            ->distinct()
            ->get()
            ->map(fn ($sale) => $sale->branch ? [
                'id' => $sale->branch->id,
                'name' => $sale->branch->name,
            ] : null)
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();

        $cashierOptions = (clone $visibleSales)
            ->reorder()
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->select('user_id')
            ->distinct()
            ->get()
            ->map(fn ($sale) => $sale->user ? [
                'id' => $sale->user->id,
                'name' => $sale->user->name,
            ] : null)
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'branches' => $branchOptions,
            'cashiers' => $cashierOptions,
            'payment_methods' => $this->paymentMethodOptions(),
            'statuses' => ['paid', 'created', 'pending', 'draft', 'voided', 'refunded'],
        ];
    }

    protected function paymentMethodOptions(): array
    {
        return PaymentMethod::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
            ])
            ->values()
            ->all();
    }

    protected function sanitizeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    protected function label(string $key): string
    {
        return str($key)->replace('_', ' ')->title()->toString();
    }
}
