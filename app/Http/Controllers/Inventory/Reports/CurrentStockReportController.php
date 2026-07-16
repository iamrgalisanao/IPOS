<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\CurrentStockReportService;
use Illuminate\Http\Request;

class CurrentStockReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly CurrentStockReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Current Stock', 'current-stock');
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Current Stock', 'current-stock');
    }
}
