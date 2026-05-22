<?php

namespace App\Services\Procurement;

use App\Models\PurchaseReceiving;
use App\Models\User;
use Carbon\Carbon;

class PurchaseReceivingCsvExportService
{
    /**
     * Export a collection or query of PurchaseReceivings to a CSV string.
     *
     * @param \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection $receivings
     * @param User $actor
     * @return string
     */
    public function export($receivings, User $actor): string
    {
        $handle = fopen('php://temp', 'r+');

        // CSV Header
        $headers = [
            'receiving_number',
            'branch',
            'supplier',
            'purchase_order_number',
            'status',
            'delivery_ref_number',
            'received_at',
            'posted_at',
            'received_by',
            'posted_by',
            'total_received_amount',
            'product_code',
            'product_name',
            'ordered_quantity',
            'received_quantity',
            'unit_cost',
            'line_total',
            'lot_number',
            'expiry_date',
            'generated_at',
            'generated_by',
        ];

        fputcsv($handle, $headers);

        $generatedAt = Carbon::now()->toIso8601String();
        $generatedBy = $actor->name;

        foreach ($receivings as $receiving) {
            // Eager load relationships if not loaded
            if (!$receiving->relationLoaded('lines')) {
                $receiving->load(['lines.product', 'branch', 'supplier', 'purchaseOrder', 'receivedBy', 'postedBy']);
            }

            $branchName = $receiving->branch?->name ?? 'N/A';
            $supplierName = $receiving->supplier?->name ?? 'N/A';
            $poNumber = $receiving->purchaseOrder?->po_number ?? 'N/A';
            $receivedByName = $receiving->receivedBy?->name ?? 'N/A';
            $postedByName = $receiving->postedBy?->name ?? 'N/A';

            foreach ($receiving->lines as $line) {
                $row = [
                    $this->sanitizeCell($receiving->receiving_number),
                    $this->sanitizeCell($branchName),
                    $this->sanitizeCell($supplierName),
                    $this->sanitizeCell($poNumber),
                    $this->sanitizeCell($receiving->status),
                    $this->sanitizeCell($receiving->delivery_ref_number ?? ''),
                    $receiving->received_at ? $receiving->received_at->toIso8601String() : '',
                    $receiving->posted_at ? $receiving->posted_at->toIso8601String() : '',
                    $this->sanitizeCell($receivedByName),
                    $this->sanitizeCell($postedByName),
                    number_format((float) $receiving->total_received_amount, 4, '.', ''),
                    $this->sanitizeCell($line->product?->sku ?? 'N/A'),
                    $this->sanitizeCell($line->product?->name ?? 'N/A'),
                    number_format((float) $line->ordered_quantity, 4, '.', ''),
                    number_format((float) $line->received_quantity, 4, '.', ''),
                    number_format((float) $line->unit_cost, 4, '.', ''),
                    number_format((float) $line->line_total, 4, '.', ''),
                    $this->sanitizeCell($line->lot_number ?? ''),
                    $line->expiry_date ? $line->expiry_date->toDateString() : '',
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
