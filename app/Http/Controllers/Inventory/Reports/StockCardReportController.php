<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\StockCardReportService;
use Illuminate\Http\Request;

class StockCardReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly StockCardReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Stock Card', 'stock-card');
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Stock Card', 'stock-card');
    }
}
