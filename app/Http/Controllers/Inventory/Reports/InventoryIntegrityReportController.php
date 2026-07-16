<?php

namespace App\Http\Controllers\Inventory\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\Reports\Concerns\HandlesInventoryReports;
use App\Services\Inventory\Reports\InventoryIntegrityReportService;
use Illuminate\Http\Request;

class InventoryIntegrityReportController extends Controller
{
    use HandlesInventoryReports;

    public function __construct(private readonly InventoryIntegrityReportService $service) {}

    public function index(Request $request)
    {
        return $this->renderReport($request, $this->service, 'Configuration and Integrity', 'integrity', true);
    }

    public function export(Request $request)
    {
        return $this->exportReport($request, $this->service, 'Configuration and Integrity', 'integrity', true);
    }
}
