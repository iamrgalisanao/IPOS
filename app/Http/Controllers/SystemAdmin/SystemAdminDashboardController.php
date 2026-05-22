<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Services\SystemAdminDashboardService;
use Illuminate\Http\JsonResponse;

class SystemAdminDashboardController extends Controller
{
    /**
     * Render the System Admin dashboard UI.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        return \Inertia\Inertia::render('SystemAdmin/Dashboard/Index');
    }

    /**
     * Get the aggregated System Admin dashboard summary payload.
     *
     * @param SystemAdminDashboardService $dashboardService
     * @return JsonResponse
     */
    public function summary(SystemAdminDashboardService $dashboardService): JsonResponse
    {
        $payload = $dashboardService->getSummary();

        return response()->json($payload);
    }
}
