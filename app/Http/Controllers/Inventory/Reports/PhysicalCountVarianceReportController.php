<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\PhysicalCountVarianceReportService;
use Illuminate\Http\Request;

class PhysicalCountVarianceReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly PhysicalCountVarianceReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Physical Count Variance', 'physical-count-variance');
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Physical Count Variance', 'physical-count-variance');
    }
}
