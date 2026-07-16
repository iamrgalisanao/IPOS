<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\NegativeStockExceptionReportService;
use Illuminate\Http\Request;

class NegativeStockExceptionReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly NegativeStockExceptionReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Negative Stock Exceptions', 'negative-stock-exceptions', true);
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Negative Stock Exceptions', 'negative-stock-exceptions', true);
    }
}
