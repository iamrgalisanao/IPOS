<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\StocktakeSession;
use App\Services\Inventory\StocktakeVarianceCsvExportService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StocktakeReportController extends Controller
{
    protected $csvService;
    protected $tenantContext;
    protected $branchContext;

    public function __construct(
        StocktakeVarianceCsvExportService $csvService,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ) {
        $this->csvService = $csvService;
        $this->tenantContext = $tenantContext;
        $this->branchContext = $branchContext;
    }

    /**
     * Display a print-friendly summary of the stocktake.
     */
    public function summary(StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession);

        $stocktakeSession->load(['branch', 'startedByUser', 'reviewer', 'approver', 'poster']);
        
        $lines = $stocktakeSession->lines()
            ->with(['product', 'counter'])
            ->get();

        // Summary Statistics
        $varianceValue = fn ($line) => $line->posted_variance_quantity ?? $line->variance_quantity;
        $linesWithVariance = $lines->filter(fn ($line) => $varianceValue($line) !== null);

        $stats = [
            'total_lines' => $lines->count(),
            'counted_lines' => $lines->whereNotNull('counted_quantity')->count(),
            'zero_variance' => $linesWithVariance->filter(fn($l) => abs((float) $varianceValue($l)) < 0.0001)->count(),
            'positive_variance' => $linesWithVariance->filter(fn($l) => (float) $varianceValue($l) > 0.0001)->count(),
            'negative_variance' => $linesWithVariance->filter(fn($l) => (float) $varianceValue($l) < -0.0001)->count(),
            'total_positive_adjustment' => $linesWithVariance->sum(fn ($line) => max(0, (float) $varianceValue($line))),
            'total_negative_adjustment' => $linesWithVariance->sum(fn ($line) => min(0, (float) $varianceValue($line))),
            'net_adjustment' => $linesWithVariance->sum(fn ($line) => (float) $varianceValue($line)),
        ];

        return Inertia::render('Inventory/Stocktake/Summary', [
            'session' => $stocktakeSession,
            'lines' => $lines,
            'stats' => $stats
        ]);
    }

    /**
     * Export variances as CSV.
     */
    public function exportVarianceCsv(Request $request, StocktakeSession $stocktakeSession)
    {
        $this->authorizeAccess($stocktakeSession, true); // Strict check for CSV

        $includeZero = $request->boolean('include_zero');
        $csv = $this->csvService->generate($stocktakeSession, $includeZero);
        $filename = $this->csvService->getFilename($stocktakeSession);

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Centralized authorization for reports.
     */
    protected function authorizeAccess(StocktakeSession $session, bool $isCsv = false)
    {
        $tenantId = $this->tenantContext->getTenantId();
        $branchId = $this->branchContext->getBranchId();

        // 1. Tenant/Branch Isolation
        if ($session->tenant_id !== $tenantId || ($branchId && $session->branch_id !== $branchId)) {
            abort(403, 'Unauthorized access to this stocktake report.');
        }

        // 2. Blind Count Boundary
        // If the report exposes expected/variance, only authorized roles can view it.
        // Counters should only see the counting UI, not the summary/variance reports.
        $user = auth()->user();
        $hasReviewAccess = $user->hasAnyPermission([
            'inventory.stocktake.review',
            'inventory.stocktake.post',
            'inventory.stocktake.approve'
        ]);

        if (!$hasReviewAccess && $session->status === StocktakeSession::STATUS_COUNTING) {
            abort(403, 'Summary reports are not available during the counting phase for your role.');
        }

        // CSV variance export is strictly for reviewers/managers
        if ($isCsv && !$hasReviewAccess) {
            abort(403, 'You do not have permission to export variance data.');
        }
    }
}
