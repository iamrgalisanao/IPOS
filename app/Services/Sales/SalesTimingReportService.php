<?php

namespace App\Services\Sales;

use App\Models\User;
use Carbon\Carbon;

class SalesTimingReportService
{
    public function __construct(
        protected SalesHistoryQueryService $salesHistoryQuery,
    ) {}

    public function build(User $user, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $sales = $this->scopedSales($user, $filters);
        $hourlyRows = $this->hourlyRows($sales);
        $weekdayRows = $this->weekdayRows($sales);

        return [
            'filters' => $this->displayFilters($filters),
            'summary' => $this->summary($hourlyRows, $weekdayRows),
            'hourly_rows' => $hourlyRows,
            'weekday_rows' => $weekdayRows,
            'filter_options' => [
                'branches' => $this->branchOptions($user),
                'cashiers' => $this->cashierOptions($user),
                'statuses' => ['paid', 'created', 'pending', 'draft', 'voided', 'refunded'],
            ],
        ];
    }

    public function exportCsv(User $user, array $filters): string
    {
        $report = $this->build($user, $filters);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['IPOS Sales by Hour / Weekday Report']);
        fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
        fputcsv($handle, ['Start Date', $report['filters']['start_date'] ?: 'All']);
        fputcsv($handle, ['End Date', $report['filters']['end_date'] ?: 'All']);
        fputcsv($handle, ['Branch ID', $report['filters']['branch_id'] ?: 'All visible branches']);
        fputcsv($handle, ['Status', $report['filters']['status'] ?: 'All']);
        fputcsv($handle, ['Cashier ID', $report['filters']['cashier_id'] ?: 'All']);
        fputcsv($handle, []);

        fputcsv($handle, ['Summary Metric', 'Value']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($handle, [$this->sanitizeCsv($this->label($key)), $this->sanitizeCsv((string) $value)]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Hour Block', 'Transaction Count', 'Gross Sales', 'Net Sales', 'Average Transaction Value']);
        foreach ($report['hourly_rows'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['hour_label']),
                $row['transaction_count'],
                $row['gross_sales'],
                $row['net_sales'],
                $row['average_transaction_value'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Weekday', 'Transaction Count', 'Gross Sales', 'Net Sales', 'Average Transaction Value']);
        foreach ($report['weekday_rows'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['weekday_label']),
                $row['transaction_count'],
                $row['gross_sales'],
                $row['net_sales'],
                $row['average_transaction_value'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Note', 'This report summarizes existing sales records only. It does not forecast demand, schedule staff, change transactions, tax, settlement, accounting, or compliance output.']);

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    public function normalizeFilters(array $filters): array
    {
        $normalized = $filters;

        if (! array_key_exists('status', $normalized)) {
            $normalized['status'] = 'paid';
        }

        if (! empty($normalized['start_date'])) {
            $normalized['start_date'] = Carbon::parse($normalized['start_date'])->startOfDay()->toDateTimeString();
        }

        if (! empty($normalized['end_date'])) {
            $normalized['end_date'] = Carbon::parse($normalized['end_date'])->endOfDay()->toDateTimeString();
        }

        return $normalized;
    }

    protected function scopedSales(User $user, array $filters)
    {
        $queryFilters = collect($filters)
            ->only(['start_date', 'end_date', 'branch_id', 'status', 'cashier_id'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        return $this->salesHistoryQuery
            ->getBuilder($user, $queryFilters)
            ->reorder()
            ->get([
                'id',
                'reporting_basis_at',
                'confirmed_at',
                'created_at',
                'gross_sales_amount',
                'subtotal',
                'discount_total',
                'total',
            ]);
    }

    protected function hourlyRows($sales): array
    {
        $buckets = collect(range(0, 23))->mapWithKeys(fn ($hour) => [
            $hour => $this->emptyTimingBucket([
                'hour' => $hour,
                'hour_label' => sprintf('%02d:00-%02d:59', $hour, $hour),
            ]),
        ])->all();

        foreach ($sales as $sale) {
            $timestamp = $this->timestamp($sale);
            $hour = (int) $timestamp->format('G');
            $this->addSale($buckets[$hour], $sale);
        }

        return collect($buckets)
            ->map(fn ($bucket) => $this->finalizeBucket($bucket))
            ->values()
            ->all();
    }

    protected function weekdayRows($sales): array
    {
        $labels = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];

        $buckets = collect($labels)->mapWithKeys(fn ($label, $day) => [
            $day => $this->emptyTimingBucket([
                'weekday' => $day,
                'weekday_label' => $label,
            ]),
        ])->all();

        foreach ($sales as $sale) {
            $timestamp = $this->timestamp($sale);
            $weekday = (int) $timestamp->isoWeekday();
            $this->addSale($buckets[$weekday], $sale);
        }

        return collect($buckets)
            ->map(fn ($bucket) => $this->finalizeBucket($bucket))
            ->values()
            ->all();
    }

    protected function summary(array $hourlyRows, array $weekdayRows): array
    {
        $activeHours = collect($hourlyRows)->filter(fn ($row) => $row['transaction_count'] > 0);
        $activeWeekdays = collect($weekdayRows)->filter(fn ($row) => $row['transaction_count'] > 0);
        $peakHour = $activeHours->sortByDesc('net_sales')->first();
        $lowestHour = $activeHours->sortBy('net_sales')->first();
        $peakWeekday = $activeWeekdays->sortByDesc('net_sales')->first();

        return [
            'peak_sales_hour' => $peakHour['hour_label'] ?? null,
            'peak_sales_weekday' => $peakWeekday['weekday_label'] ?? null,
            'lowest_sales_hour' => $lowestHour['hour_label'] ?? null,
            'total_transactions' => (int) array_sum(array_column($hourlyRows, 'transaction_count')),
            'total_net_sales' => round((float) array_sum(array_column($hourlyRows, 'net_sales')), 4),
        ];
    }

    protected function displayFilters(array $filters): array
    {
        return [
            'start_date' => isset($filters['start_date']) ? substr((string) $filters['start_date'], 0, 10) : '',
            'end_date' => isset($filters['end_date']) ? substr((string) $filters['end_date'], 0, 10) : '',
            'branch_id' => $filters['branch_id'] ?? '',
            'status' => $filters['status'] ?? 'paid',
            'cashier_id' => $filters['cashier_id'] ?? '',
        ];
    }

    protected function branchOptions(User $user): array
    {
        return $this->salesHistoryQuery
            ->getBuilder($user, [])
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
    }

    protected function cashierOptions(User $user): array
    {
        return $this->salesHistoryQuery
            ->getBuilder($user, [])
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
    }

    protected function emptyTimingBucket(array $extra): array
    {
        return array_merge([
            'transaction_count' => 0,
            'gross_sales' => 0.0,
            'net_sales' => 0.0,
            'average_transaction_value' => 0.0,
        ], $extra);
    }

    protected function addSale(array &$bucket, $sale): void
    {
        $bucket['transaction_count']++;
        $bucket['gross_sales'] += (float) ($sale->gross_sales_amount ?? ((float) $sale->subtotal + (float) $sale->discount_total));
        $bucket['net_sales'] += (float) $sale->total;
    }

    protected function finalizeBucket(array $bucket): array
    {
        $bucket['gross_sales'] = round((float) $bucket['gross_sales'], 4);
        $bucket['net_sales'] = round((float) $bucket['net_sales'], 4);
        $bucket['average_transaction_value'] = $bucket['transaction_count'] > 0
            ? round($bucket['net_sales'] / $bucket['transaction_count'], 4)
            : 0.0;

        return $bucket;
    }

    protected function timestamp($sale): Carbon
    {
        return Carbon::parse($sale->reporting_basis_at ?? $sale->confirmed_at ?? $sale->created_at);
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
