<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\User;
use Carbon\Carbon;

class PurchaseOrderCsvExportService
{
    /**
     * Export a collection or query of PurchaseOrders to a CSV string.
     *
     * @param \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection $purchaseOrders
     * @param User $actor
     * @return string
     */
    public function export($purchaseOrders, User $actor): string
    {
        $handle = fopen('php://temp', 'r+');

        // CSV Header
        $headers = [
            'po_number',
            'branch',
            'supplier',
            'status',
            'order_date',
            'expected_delivery_date',
            'created_by',
            'approved_by',
            'approved_at',
            'total_estimated_amount',
            'product_code',
            'product_name',
            'ordered_quantity',
            'received_quantity',
            'unit_cost',
            'line_total',
            'generated_at',
            'generated_by',
        ];

        fputcsv($handle, $headers);

        $generatedAt = Carbon::now()->toIso8601String();
        $generatedBy = $actor->name;

        foreach ($purchaseOrders as $po) {
            // Eager load relationships if not loaded
            if (!$po->relationLoaded('lines')) {
                $po->load(['lines.product', 'branch', 'supplier', 'createdBy', 'approvedBy']);
            }

            $branchName = $po->branch?->name ?? 'N/A';
            $supplierName = $po->supplier?->name ?? 'N/A';
            $creatorName = $po->createdBy?->name ?? 'N/A';
            $approverName = $po->approvedBy?->name ?? 'N/A';

            foreach ($po->lines as $line) {
                $row = [
                    $this->sanitizeCell($po->po_number),
                    $this->sanitizeCell($branchName),
                    $this->sanitizeCell($supplierName),
                    $this->sanitizeCell($po->status),
                    $po->order_date ? $po->order_date->toDateString() : '',
                    $po->expected_delivery_date ? $po->expected_delivery_date->toDateString() : '',
                    $this->sanitizeCell($creatorName),
                    $this->sanitizeCell($approverName),
                    $po->approved_at ? $po->approved_at->toIso8601String() : '',
                    number_format((float) $po->total_estimated_amount, 4, '.', ''),
                    $this->sanitizeCell($line->product?->sku ?? 'N/A'),
                    $this->sanitizeCell($line->product?->name ?? 'N/A'),
                    number_format((float) $line->ordered_quantity, 4, '.', ''),
                    number_format((float) $line->received_quantity, 4, '.', ''),
                    number_format((float) $line->unit_cost, 4, '.', ''),
                    number_format((float) $line->line_total, 4, '.', ''),
                    $generatedAt,
                    $this->sanitizeCell($generatedBy),
                ];

                fputcsv($handle, $row);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Sanitize cell values to protect against CSV/Excel injection.
     */
    private function sanitizeCell($value): string
    {
        if (is_null($value)) {
            return '';
        }
        $str = trim((string) $value);
        if (strlen($str) > 0 && in_array($str[0], ['=', '+', '-', '@'])) {
            return "'" . $str;
        }
        return $str;
    }
}
