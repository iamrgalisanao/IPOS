<?php

namespace App\Http\Controllers\Settlement;

use App\Http\Controllers\Controller;
use App\Models\SettlementPeriod;
use App\Models\User;
use App\Services\Settlement\SettlementExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettlementExportController extends Controller
{
    public function __construct(
        protected SettlementExportService $exportService
    ) {}

    /**
     * Download the Settlement Summary as CSV.
     */
    public function summaryCsv(Request $request, string $periodId): Response
    {
        $period = $this->findVisiblePeriod($periodId, $request->user());
        
        $csv = $this->exportService->exportSummaryToCsv($period, $request->user());

        $filename = "settlement-summary-{$period->id}-" . now()->format('Ymd-His') . ".csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Download the Settlement Summary as PDF.
     */
    public function summaryPdf(Request $request, string $periodId): Response
    {
        $period = $this->findVisiblePeriod($periodId, $request->user());
        
        $pdf = $this->exportService->exportSummaryToPdf($period, $request->user());

        $filename = "settlement-summary-{$period->id}-" . now()->format('Ymd-His') . ".pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Download the Settlement Variance Ledger as CSV.
     */
    public function varianceCsv(Request $request, string $periodId): Response
    {
        $period = $this->findVisiblePeriod($periodId, $request->user());
        
        $csv = $this->exportService->exportVariancesToCsv($period, $request->user());

        $filename = "settlement-variances-{$period->id}-" . now()->format('Ymd-His') . ".csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Download the Accounting Sync Status Log as CSV.
     */
    public function syncStatusCsv(Request $request, string $periodId): Response
    {
        $period = $this->findVisiblePeriod($periodId, $request->user());
        
        $csv = $this->exportService->exportSyncStatusToCsv($period, $request->user());

        $filename = "settlement-accounting-sync-{$period->id}-" . now()->format('Ymd-His') . ".csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function findVisiblePeriod(string $id, User $user): SettlementPeriod
    {
        // Reusing the visibility logic from the controller or just use a simple find for now
        // since SettlementExportService already performs its own assertCanExport check.
        $period = SettlementPeriod::findOrFail($id);
        
        // This findOrFail is safe because SettlementExportService will throw an 
        // AuthorizationException if the user doesn't have access or it's cross-tenant.
        
        return $period;
    }
}
