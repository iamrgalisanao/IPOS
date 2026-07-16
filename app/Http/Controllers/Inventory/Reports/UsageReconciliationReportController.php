<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\UsageReconciliationReportService;
use Illuminate\Http\Request;

class UsageReconciliationReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly UsageReconciliationReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Usage Reconciliation', 'usage-reconciliation', true);
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Usage Reconciliation', 'usage-reconciliation', true);
    }
}
