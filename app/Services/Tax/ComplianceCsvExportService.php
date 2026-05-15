<?php

namespace App\Services\Tax;

class ComplianceCsvExportService
{
    /**
     * Generate CSV content from the compliance export package contract.
     *
     * This service converts the prepared package array into a safe CSV format
     * with clear sections for metadata, filters, summary, and notes.
     * It handles escaping and prevents formula injection.
     */
    public function generate(array $package): string
    {
        $handle = fopen('php://temp', 'r+');

        // Header
        fputcsv($handle, ['IPOS Compliance Export']);
        fputcsv($handle, []);

        // Metadata section
        if (isset($package['metadata'])) {
            fputcsv($handle, ['Metadata']);
            foreach ($package['metadata'] as $key => $value) {
                fputcsv($handle, [$this->sanitize($key), $this->sanitize($value)]);
            }
            fputcsv($handle, []);
        }

        // Filters section
        if (isset($package['filters'])) {
            fputcsv($handle, ['Filters']);
            foreach ($package['filters'] as $key => $value) {
                fputcsv($handle, [$this->sanitize($key), $this->sanitize($value)]);
            }
            fputcsv($handle, []);
        }

        // Summary section
        if (isset($package['summary'])) {
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Metric', 'Value']);
            foreach ($package['summary'] as $key => $value) {
                fputcsv($handle, [$this->sanitize($key), $this->sanitize($value)]);
            }
            fputcsv($handle, []);
        }

        // Notes section
        if (isset($package['notes'])) {
            fputcsv($handle, ['Notes']);
            foreach ($package['notes'] as $note) {
                fputcsv($handle, [$this->sanitize($note)]);
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Sanitize value for CSV to prevent formula injection.
     *
     * Risky characters (=, +, -, @) at the start of a cell are prefixed with a single quote.
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
}
