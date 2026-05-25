<?php

namespace App\Services\Sales;

use App\Models\ProductCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ProductMixReportService
{
    public function __construct(
        protected SalesHistoryQueryService $salesHistoryQuery,
    ) {}

    public function build(User $user, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $builder = $this->productRowsBuilder($user, $filters);
        $rows = $this->rows($builder);

        return [
            'filters' => $this->displayFilters($filters),
            'summary' => $this->summary($rows),
            'rows' => $rows,
            'filter_options' => [
                'branches' => $this->branchOptions($user),
                'categories' => $this->categoryOptions(),
                'statuses' => ['paid', 'created', 'pending', 'draft', 'voided', 'refunded'],
            ],
        ];
    }

    public function exportCsv(User $user, array $filters): string
    {
        $report = $this->build($user, $filters);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['IPOS Product Mix Report']);
        fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
        fputcsv($handle, ['Start Date', $report['filters']['start_date'] ?: 'All']);
        fputcsv($handle, ['End Date', $report['filters']['end_date'] ?: 'All']);
        fputcsv($handle, ['Branch ID', $report['filters']['branch_id'] ?: 'All visible branches']);
        fputcsv($handle, ['Category ID', $report['filters']['category_id'] ?: 'All']);
        fputcsv($handle, ['Product Search', $report['filters']['product_search'] ?: 'All']);
        fputcsv($handle, ['Status', $report['filters']['status'] ?: 'All']);
        fputcsv($handle, []);

        fputcsv($handle, ['Summary Metric', 'Value']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($handle, [$this->sanitizeCsv($this->label($key)), $this->sanitizeCsv((string) $value)]);
        }

        fputcsv($handle, []);
        fputcsv($handle, [
            'Product',
            'SKU',
            'Category',
            'Quantity Sold',
            'Gross Sales',
            'Discounts',
            'Net Sales',
            'Refund/Void Quantity',
            'Average Selling Price',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['product_name']),
                $this->sanitizeCsv((string) $row['sku']),
                $this->sanitizeCsv((string) $row['category_name']),
                $row['quantity_sold'],
                $row['gross_sales'],
                $row['discounts'],
                $row['net_sales'],
                $row['refund_void_quantity'],
                $row['average_selling_price'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Note', 'This report summarizes existing sale item records only. It does not change transactions, tax, settlement, accounting, inventory, or product catalog state.']);

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

    protected function productRowsBuilder(User $user, array $filters): Builder
    {
        $salesFilters = collect($filters)
            ->only(['start_date', 'end_date', 'branch_id', 'status'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $saleIds = $this->salesHistoryQuery
            ->getBuilder($user, $salesFilters)
            ->reorder()
            ->select('sales.id');

        $query = \App\Models\SaleItem::query()
            ->whereIn('sale_items.sale_id', $saleIds)
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->select('sale_items.product_id')
            ->selectRaw('MAX(sale_items.product_name) as product_name')
            ->selectRaw('MAX(sale_items.sku) as sku')
            ->selectRaw('MAX(product_categories.name) as category_name')
            ->selectRaw("COALESCE(SUM(CASE WHEN sales.status IN ('voided', 'refunded') OR sales.is_reversal IS TRUE THEN 0 ELSE sale_items.quantity END), 0) as quantity_sold")
            ->selectRaw("COALESCE(SUM(CASE WHEN sales.status IN ('voided', 'refunded') OR sales.is_reversal IS TRUE THEN 0 ELSE sale_items.subtotal END), 0) as gross_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN sales.status IN ('voided', 'refunded') OR sales.is_reversal IS TRUE THEN 0 ELSE sale_items.discount_amount END), 0) as discounts")
            ->selectRaw("COALESCE(SUM(CASE WHEN sales.status IN ('voided', 'refunded') OR sales.is_reversal IS TRUE THEN 0 ELSE sale_items.line_total END), 0) as net_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN sales.status IN ('voided', 'refunded') OR sales.is_reversal IS TRUE THEN ABS(sale_items.quantity) ELSE 0 END), 0) as refund_void_quantity")
            ->groupBy('sale_items.product_id');

        if (! empty($filters['category_id'])) {
            $query->where('products.product_category_id', $filters['category_id']);
        }

        if (! empty($filters['product_search'])) {
            $search = $filters['product_search'];
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('sale_items.product_name', 'like', "%{$search}%")
                    ->orWhere('sale_items.sku', 'like', "%{$search}%")
                    ->orWhere('sale_items.barcode', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('net_sales')->orderBy('product_name');
    }

    protected function rows(Builder $builder): array
    {
        return $builder
            ->get()
            ->map(function ($row) {
                $quantitySold = (float) $row->quantity_sold;
                $netSales = round((float) $row->net_sales, 4);

                return [
                    'product_id' => $row->product_id,
                    'product_name' => (string) $row->product_name,
                    'sku' => $row->sku,
                    'category_name' => $row->category_name ?: 'Uncategorized',
                    'quantity_sold' => round($quantitySold, 4),
                    'gross_sales' => round((float) $row->gross_sales, 4),
                    'discounts' => round((float) $row->discounts, 4),
                    'net_sales' => $netSales,
                    'refund_void_quantity' => round((float) $row->refund_void_quantity, 4),
                    'average_selling_price' => $quantitySold > 0 ? round($netSales / $quantitySold, 4) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    protected function summary(array $rows): array
    {
        $totalQuantity = array_sum(array_column($rows, 'quantity_sold'));
        $totalGross = array_sum(array_column($rows, 'gross_sales'));
        $totalNet = array_sum(array_column($rows, 'net_sales'));
        $topSelling = collect($rows)->sortByDesc('quantity_sold')->first();
        $highestRevenue = collect($rows)->sortByDesc('net_sales')->first();

        return [
            'total_quantity_sold' => round((float) $totalQuantity, 4),
            'total_gross_sales' => round((float) $totalGross, 4),
            'total_net_sales' => round((float) $totalNet, 4),
            'unique_products_sold' => count($rows),
            'top_selling_product' => $topSelling['product_name'] ?? null,
            'highest_revenue_product' => $highestRevenue['product_name'] ?? null,
        ];
    }

    protected function displayFilters(array $filters): array
    {
        return [
            'start_date' => isset($filters['start_date']) ? substr((string) $filters['start_date'], 0, 10) : '',
            'end_date' => isset($filters['end_date']) ? substr((string) $filters['end_date'], 0, 10) : '',
            'branch_id' => $filters['branch_id'] ?? '',
            'category_id' => $filters['category_id'] ?? '',
            'product_search' => $filters['product_search'] ?? '',
            'status' => $filters['status'] ?? 'paid',
        ];
    }

    protected function categoryOptions(): array
    {
        return ProductCategory::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'code' => $category->code,
            ])
            ->values()
            ->all();
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

    protected function sanitizeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    protected function label(string $key): string
    {
        return str($key)->replace('_', ' ')->title()->toString();
    }
}
