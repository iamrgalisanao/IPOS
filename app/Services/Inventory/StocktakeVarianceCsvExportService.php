<?php

namespace App\Services\Inventory;

use App\Models\StocktakeSession;
use App\Models\StocktakeLine;
use Illuminate\Support\Str;

class StocktakeVarianceCsvExportService
{
    /**
     * Generate CSV for stocktake variances.
     *
     * @param StocktakeSession $session
     * @param bool $includeZeroVariance
     * @return string
     */
    public function generate(StocktakeSession $session, bool $includeZeroVariance = false): string
    {
        $handle = fopen('php://temp', 'r+');
        
        // Headers
        fputcsv($handle, [
            'Stocktake Number',
            'Branch',
            'Status',
            'Product Name',
            'SKU',
            'Expected Quantity',
            'Counted Quantity',
            'Variance Quantity',
            'Reason Code',
            'Remarks',
            'Counted By',
            'Counted At',
            'Posted At',
            'Expected At Count Start',
            'Expected At Count Time',
            'Physical Count Variance',
            'Movement After Count',
            'Posted Variance',
            'Posting Outcome',
            'Evidence Quality',
            'Projection Policy Version',
        ]);

        $query = $session->lines()->with(['product', 'counter']);

        if (!$includeZeroVariance) {
            $query->whereRaw('ABS(COALESCE(posted_variance_quantity, variance_quantity, 0)) > 0.0001');
        }

        $query->chunk(100, function ($lines) use ($handle, $session) {
            foreach ($lines as $line) {
                fputcsv($handle, [
                    $session->stocktake_number,
                    $session->branch->name,
                    Str::upper($session->status),
                    $line->product->name,
                    $line->product->sku,
                    $line->expected_quantity,
                    $line->counted_quantity,
                    $line->variance_quantity,
                    $line->reason_code ?? 'N/A',
                    $line->remarks ?? '',
                    $line->counter->name ?? 'System',
                    $line->counted_at ? $line->counted_at->toDateTimeString() : '',
                    $session->posted_at ? $session->posted_at->toDateTimeString() : '',
                    $line->expected_quantity_at_count_start ?? $line->expected_quantity,
                    $line->expected_quantity_at_count_time ?? $line->expected_quantity,
                    $line->physical_count_variance_quantity ?? $line->variance_quantity,
                    $line->movement_after_count_delta ?? '0.0000',
                    $line->posted_variance_quantity ?? $line->variance_quantity,
                    $line->posting_outcome ?? '',
                    $line->posting_evidence_quality ?? 'legacy',
                    $line->projection_policy_version ?? '',
                ]);
            }
        });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Get a safe filename for the export.
     *
     * @param StocktakeSession $session
     * @return string
     */
    public function getFilename(StocktakeSession $session): string
    {
        $safeNumber = Str::slug($session->stocktake_number);
        return "ipos-stocktake-variance-{$safeNumber}.csv";
    }
}
