<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\ReconciliationExceptionReportService;
use Illuminate\Http\Request;

class ReconciliationExceptionReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly ReconciliationExceptionReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Reconciliation Exceptions', 'reconciliation-exceptions', true);
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Reconciliation Exceptions', 'reconciliation-exceptions', true);
    }
}
