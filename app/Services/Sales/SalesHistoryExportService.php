<?php

namespace App\Services\Sales;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesHistoryExportService
{
    /**
     * Generate a CSV string from the sale history builder.
     * Uses streaming-friendly logic and applies safety guardrails.
     */
    public function generate(Builder $query): string
    {
        $handle = fopen('php://temp', 'r+');

        // CSV Header
        fputcsv($handle, [
            'Sale Number',
            'Isolation ID',
            'Timestamp',
            'Status',
            'Branch',
            'Cashier',
            'Subtotal',
            'Tax Total',
            'Discount Total',
            'Total Amount',
            'Payment Summary'
        ]);

        // We load relationships for the export to avoid N+1 and get related names
        $query->with(['branch:id,name', 'user:id,name', 'payments.paymentMethod']);

        $query->chunk(100, function ($sales) use ($handle) {
            foreach ($sales as $sale) {
                fputcsv($handle, [
                    $this->sanitize($sale->sale_number),
                    $this->sanitize($sale->client_request_uuid),
                    $this->sanitize($sale->confirmed_at ?: $sale->created_at),
                    $this->sanitize(strtoupper($sale->status)),
                    $this->sanitize($sale->branch?->name ?: 'Main'),
                    $this->sanitize($sale->user?->name ?: 'System'),
                    $this->sanitize(number_format($sale->subtotal, 2)),
                    $this->sanitize(number_format($sale->tax_total, 2)),
                    $this->sanitize(number_format($sale->discount_total, 2)),
                    $this->sanitize(number_format($sale->total, 2)),
                    $this->sanitize($this->getPaymentSummary($sale))
                ]);
            }
        });

        // Add non-certification note
        fputcsv($handle, []);
        fputcsv($handle, ['Non-Certification: This operational history export is for audit purposes and is not a formal tax certification.']);

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Sanitize value for CSV to prevent formula injection.
     */
    protected function sanitize(mixed $value): string
    {
        if (is_null($value)) {
            return '';
        }

        $str = (string) $value;

        if ($str === '') {
            return '';
        }

        // Prevent formula injection: =, +, -, @
        if (in_array(substr($str, 0, 1), ['=', '+', '-', '@'])) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Get a comma-separated summary of payment methods.
     */
    protected function getPaymentSummary(Sale $sale): string
    {
        return $sale->payments->map(function ($payment) {
            return $payment->paymentMethod?->name ?: 'Manual';
        })->unique()->implode(', ');
    }
}
