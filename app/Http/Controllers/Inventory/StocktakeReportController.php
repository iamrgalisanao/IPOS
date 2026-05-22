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
        $stats = [
            'total_lines' => $lines->count(),
            'counted_lines' => $lines->whereNotNull('counted_quantity')->count(),
            'zero_variance' => $lines->filter(fn($l) => abs($l->variance_quantity) < 0.0001)->count(),
            'positive_variance' => $lines->filter(fn($l) => $l->variance_quantity > 0.0001)->count(),
            'negative_variance' => $lines->filter(fn($l) => $l->variance_quantity < -0.0001)->count(),
            'total_positive_adjustment' => $lines->where('variance_quantity', '>', 0)->sum('variance_quantity'),
            'total_negative_adjustment' => $lines->where('variance_quantity', '<', 0)->sum('variance_quantity'),
            'net_adjustment' => $lines->sum('variance_quantity'),
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
