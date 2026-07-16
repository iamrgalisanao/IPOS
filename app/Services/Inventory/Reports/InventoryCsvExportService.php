<?php

namespace App\Services\Inventory\Reports;

use App\Services\AuditLogger;
use App\Models\User;

class InventoryCsvExportService
{
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function make(
        string $title,
        array $headers,
        array $rows,
        array $metadata,
        ?User $actor = null,
        bool $auditExport = false,
    ): string {
        if (count($rows) > self::MAX_ROWS) {
            abort(422, 'EXPORT_SCOPE_TOO_LARGE');
        }

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [$title]);
        foreach ($metadata as $key => $value) {
            fputcsv($handle, [$this->safeText(str($key)->replace('_', ' ')->title()->toString()), $this->safeCell($value)]);
        }
        fputcsv($handle, []);
        fputcsv($handle, array_map(fn ($header) => $this->safeText((string) $header), $headers));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $this->safeCell($row[$header] ?? ''), $headers));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';

        if ($auditExport) {
            $this->auditLogger->log(
                'INVENTORY_REPORT_EXPORTED',
                null,
                null,
                [
                    'report_type' => $metadata['report_type'] ?? $title,
                    'user_id' => $actor?->id,
                    'tenant_id' => $metadata['tenant_id'] ?? null,
                    'branch_scope' => $metadata['branch_scope'] ?? [],
                    'filter_fingerprint' => $metadata['filter_fingerprint'] ?? null,
                    'generated_at' => $metadata['generated_at'] ?? now()->toIso8601String(),
                    'row_count' => count($rows),
                    'watermarks' => $metadata['branch_watermarks'] ?? [],
                    'filename' => $metadata['filename'] ?? null,
                ],
                'inventory_report_export',
                'Audit/integrity inventory report export generated.',
                actor: $actor,
            );
        }

        return $csv;
    }

    private function safeCell(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value)) {
            return $value;
        }

        return $this->safeText(is_array($value) ? json_encode($value) : (string) $value);
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
