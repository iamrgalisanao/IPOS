<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardQueryService $dashboardService
    ) {}

    /**
     * Handle the incoming dashboard request.
     */
    public function index(Request $request): Response
    {
        $pulse = $this->dashboardService->getPulse(
            $request->user(), 
            $request->query('branch_id')
        );

        return Inertia::render('Dashboard', [
            'pulse' => $pulse,
            'branches' => $request->user()->branches()->select('branches.id', 'branches.name')->get()
        ]);
    }
}
