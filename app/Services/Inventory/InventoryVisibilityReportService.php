<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\InventoryMovement;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InventoryVisibilityReportService
{
    public const EXPIRY_RISK_DAYS = 30;
    public const SLOW_MOVING_DAYS = 30;

    public function build(User $user, array $filters, Collection $branches): array
    {
        $rows = $this->rows($filters, $branches);

        return [
            'summary' => $this->summary($rows),
            'rows' => $rows,
        ];
    }

    public function exportCsv(User $user, array $filters, Collection $branches): string
    {
        $report = $this->build($user, $filters, $branches);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['IPOS Inventory Visibility Report']);
        fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
        fputcsv($handle, ['Branch ID', $filters['branch_id'] ?: 'All visible branches']);
        fputcsv($handle, ['Category ID', $filters['category_id'] ?: 'All']);
        fputcsv($handle, ['Search', $filters['search'] ?: 'All']);
        fputcsv($handle, ['Low Stock Only', $filters['low_stock_only'] ? 'Yes' : 'No']);
        fputcsv($handle, ['Expiry Risk Only', $filters['expiry_risk_only'] ? 'Yes' : 'No']);
        fputcsv($handle, []);

        fputcsv($handle, ['Summary Metric', 'Value']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($handle, [$this->sanitizeCsv($this->label($key)), $this->sanitizeCsv((string) $value)]);
        }

        fputcsv($handle, []);
        fputcsv($handle, [
            'Branch',
            'Product',
            'SKU',
            'Barcode',
            'Category',
            'Current Stock',
            'Reorder Level',
            'Unit',
            'Stock State',
            'Next Expiry Date',
            'Expiry Status',
            'Last Movement Date',
            'Last Sale Date',
            'Movement Status',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCsv($row['branch_name']),
                $this->sanitizeCsv($row['product_name']),
                $this->sanitizeCsv((string) $row['sku']),
                $this->sanitizeCsv((string) $row['barcode']),
                $this->sanitizeCsv((string) $row['category_name']),
                $row['current_stock'],
                $row['reorder_level'],
                $this->sanitizeCsv((string) $row['unit_of_measure']),
                $this->sanitizeCsv($row['stock_state']),
                $row['next_expiry_date'] ?? '',
                $this->sanitizeCsv($row['expiry_status']),
                $row['last_movement_at'] ?? '',
                $row['last_sale_at'] ?? '',
                $this->sanitizeCsv($row['movement_status']),
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Note', 'This report summarizes existing inventory records only. It does not mutate stock, create procurement records, alter stocktake workflows, or change accounting/tax behavior.']);

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    protected function rows(array $filters, Collection $branches): array
    {
        $branchIds = $branches->pluck('id')->map(fn ($id) => (string) $id)->all();
        $query = BranchInventory::query()
            ->active()
            ->whereIn('branch_id', $branchIds)
            ->whereHas('product', fn ($productQuery) => $productQuery->where('is_inventory_tracked', true))
            ->with([
                'branch:id,name',
                'product:id,name,sku,barcode,unit_of_measure,product_category_id,expiry_tracking_enabled',
                'product.category:id,name',
            ]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('product_category_id', $filters['category_id']));
        }

        if (($filters['search'] ?? '') !== '') {
            $search = $filters['search'];
            $query->whereHas('product', function ($productQuery) use ($search) {
                $productQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['low_stock_only'])) {
            $query->whereColumn('current_stock', '<=', 'reorder_level');
        }

        $inventories = $query
            ->orderByRaw('CASE WHEN current_stock < 0 THEN 0 WHEN current_stock <= reorder_level THEN 1 ELSE 2 END')
            ->orderBy('current_stock')
            ->get();

        $productIds = $inventories->pluck('product_id')->map(fn ($id) => (string) $id)->unique()->values();
        $expiryByKey = $this->expiryByBranchProduct($branchIds, $productIds);
        $lastMovementByKey = $this->lastMovementByBranchProduct($branchIds, $productIds);
        $lastSaleByKey = $this->lastSaleByBranchProduct($branchIds, $productIds);
        $expiryRiskCutoff = now()->addDays(self::EXPIRY_RISK_DAYS)->toDateString();

        return $inventories
            ->map(function (BranchInventory $inventory) use ($expiryByKey, $lastMovementByKey, $lastSaleByKey) {
                $key = $this->key($inventory->branch_id, $inventory->product_id);
                $stock = (float) $inventory->current_stock;
                $reorder = (float) ($inventory->reorder_level ?? 0);
                $nextExpiry = $expiryByKey[$key] ?? null;
                $lastMovement = $lastMovementByKey[$key] ?? null;
                $lastSale = $lastSaleByKey[$key] ?? null;

                return [
                    'branch_id' => $inventory->branch_id,
                    'branch_name' => $inventory->branch?->name ?? 'Unknown Branch',
                    'product_id' => $inventory->product_id,
                    'product_name' => $inventory->product?->name ?? 'Unknown Product',
                    'sku' => $inventory->product?->sku,
                    'barcode' => $inventory->product?->barcode,
                    'category_name' => $inventory->product?->category?->name ?? 'Uncategorized',
                    'current_stock' => round($stock, 4),
                    'reorder_level' => round($reorder, 4),
                    'unit_of_measure' => $inventory->product?->unit_of_measure,
                    'stock_state' => $this->stockState($stock, $reorder),
                    'is_low_stock' => $stock <= $reorder,
                    'next_expiry_date' => $nextExpiry,
                    'expiry_status' => $this->expiryStatus($nextExpiry),
                    'last_movement_at' => $lastMovement,
                    'last_sale_at' => $lastSale,
                    'movement_status' => $this->movementStatus($lastSale),
                ];
            })
            ->filter(function (array $row) use ($filters, $expiryRiskCutoff) {
                if (empty($filters['expiry_risk_only'])) {
                    return true;
                }

                return $row['next_expiry_date'] !== null && $row['next_expiry_date'] <= $expiryRiskCutoff;
            })
            ->sortBy(fn (array $row) => sprintf(
                '%d-%d-%s',
                match ($row['stock_state']) {
                    'negative' => 0,
                    'low' => 1,
                    default => 2,
                },
                match ($row['expiry_status']) {
                    'expired' => 0,
                    'soon' => 1,
                    default => 2,
                },
                str($row['product_name'])->lower()->toString(),
            ))
            ->values()
            ->all();
    }

    protected function summary(array $rows): array
    {
        return [
            'total_skus_tracked' => count($rows),
            'skus_below_reorder' => collect($rows)->where('is_low_stock', true)->count(),
            'skus_with_expiry_risk' => collect($rows)->whereIn('expiry_status', ['expired', 'soon'])->count(),
            'slow_moving_or_unsold_skus' => collect($rows)->whereIn('movement_status', ['slow_moving', 'unsold'])->count(),
        ];
    }

    protected function expiryByBranchProduct(array $branchIds, Collection $productIds): array
    {
        return ExpiryLot::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_id', $productIds)
            ->where('quantity_remaining', '>', 0)
            ->whereIn('status', ['active', 'available'])
            ->selectRaw('branch_id, product_id, MIN(expiry_date) as next_expiry_date')
            ->groupBy('branch_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key($row->branch_id, $row->product_id) => $row->next_expiry_date
                    ? Carbon::parse($row->next_expiry_date)->toDateString()
                    : null,
            ])
            ->all();
    }

    protected function lastMovementByBranchProduct(array $branchIds, Collection $productIds): array
    {
        return InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_id', $productIds)
            ->selectRaw('branch_id, product_id, MAX(created_at) as last_movement_at')
            ->groupBy('branch_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key($row->branch_id, $row->product_id) => $row->last_movement_at
                    ? Carbon::parse($row->last_movement_at)->toDateTimeString()
                    : null,
            ])
            ->all();
    }

    protected function lastSaleByBranchProduct(array $branchIds, Collection $productIds): array
    {
        return SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereIn('sale_items.branch_id', $branchIds)
            ->whereIn('sale_items.product_id', $productIds)
            ->where('sales.status', 'paid')
            ->selectRaw('sale_items.branch_id, sale_items.product_id, MAX(COALESCE(sales.reporting_basis_at, sales.confirmed_at, sales.created_at, sale_items.created_at)) as last_sale_at')
            ->groupBy('sale_items.branch_id', 'sale_items.product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key($row->branch_id, $row->product_id) => $row->last_sale_at
                    ? Carbon::parse($row->last_sale_at)->toDateTimeString()
                    : null,
            ])
            ->all();
    }

    protected function stockState(float $stock, float $reorder): string
    {
        if ($stock < 0) {
            return 'negative';
        }

        return $stock <= $reorder ? 'low' : 'normal';
    }

    protected function expiryStatus(?string $expiryDate): string
    {
        if (!$expiryDate) {
            return 'not_tracked';
        }

        if ($expiryDate < now()->toDateString()) {
            return 'expired';
        }

        return $expiryDate <= now()->addDays(self::EXPIRY_RISK_DAYS)->toDateString() ? 'soon' : 'clear';
    }

    protected function movementStatus(?string $lastSaleAt): string
    {
        if (!$lastSaleAt) {
            return 'unsold';
        }

        return Carbon::parse($lastSaleAt)->lt(now()->subDays(self::SLOW_MOVING_DAYS))
            ? 'slow_moving'
            : 'active';
    }

    protected function key(string $branchId, string $productId): string
    {
        return "{$branchId}:{$productId}";
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
