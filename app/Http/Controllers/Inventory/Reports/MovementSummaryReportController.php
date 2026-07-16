<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\MovementSummaryReportService;
use Illuminate\Http\Request;

class MovementSummaryReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly MovementSummaryReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Movement Summary', 'movement-summary');
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Movement Summary', 'movement-summary');
    }
}
