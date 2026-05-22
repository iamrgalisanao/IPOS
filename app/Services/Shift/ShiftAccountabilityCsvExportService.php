<?php

namespace App\Services\Shift;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ShiftAccountabilityCsvExportService
{
    /**
     * Generate a streamed CSV response for the cashier accountability report payload.
     *
     * @param array $payload
     * @return StreamedResponse
     */
    public function export(array $payload): StreamedResponse
    {
        $shiftId = $payload['shift']['id'];
        $filename = "ipos-cashier-accountability-{$shiftId}.csv";

        // Headers representing the Story 17.1 Locked Schema
        $headers = [
            'shift_id',
            'branch_name',
            'cashier_name',
            'opened_at',
            'closed_at',
            'status',
            'opening_cash',
            'cash_in',
            'cash_out',
            'cash_sales',
            'non_cash_sales',
            'gross_sales',
            'discounts',
            'refunds',
            'voids',
            'net_sales',
            'expected_cash',
            'declared_cash',
            'cash_variance',
            'drawer_event_count',
            'generated_at',
            'generated_by'
        ];

        // Format dates cleanly
        $openedAt = $payload['timeline']['opened_at'];
        $closedAt = $payload['timeline']['closed_at'] ?? $payload['timeline']['closing_submitted_at'] ?? '';

        // Extract values
        $row = [
            $payload['shift']['id'],
            $this->sanitizeCell($payload['branch']['name'] ?? ''),
            $this->sanitizeCell($payload['cashier']['name'] ?? ''),
            $openedAt,
            $closedAt,
            $this->sanitizeCell($payload['shift']['status'] ?? ''),
            $payload['drawer_summary']['opening_cash'],
            $payload['drawer_summary']['cash_in'],
            $payload['drawer_summary']['cash_out'],
            $payload['payment_mix']['cash_sales'],
            $payload['payment_mix']['non_cash_sales'],
            $payload['sales_summary']['gross_sales'],
            $payload['sales_summary']['discounts'],
            $payload['sales_summary']['refunds'],
            $payload['sales_summary']['voids'],
            $payload['sales_summary']['net_sales'],
            $payload['cash_variance']['expected_cash'],
            $payload['cash_variance']['declared_cash'] ?? '0.0000',
            $payload['cash_variance']['variance'] ?? '0.0000',
            (string) ($payload['drawer_summary']['drawer_event_count'] ?? 0),
            $payload['metadata']['generated_at'],
            $this->sanitizeCell($payload['metadata']['generated_by'] ?? '')
        ];

        $responseHeaders = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->sanitizeFilename($filename) . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($headers, $row) {
            $file = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for proper Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($file, $headers);
            fputcsv($file, $row);
            
            fclose($file);
        }, 200, $responseHeaders);
    }

    /**
     * Sanitize string values for CSV Excel Injection vulnerability.
     */
    protected function sanitizeCell(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 0) {
            $firstChar = $value[0];
            if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
                return "'" . $value;
            }
        }
        return $value;
    }

    /**
     * Sanitize filenames to prevent path traversal or special character failures.
     */
    protected function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_.]/', '', $filename);
    }
}
