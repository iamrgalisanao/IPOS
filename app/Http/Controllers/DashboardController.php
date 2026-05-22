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
    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if (!$user->hasPermission('view_reports')) {
            if ($user->hasPermission('access_pos')) {
                return redirect()->route('pos.index');
            }

            if ($user->hasPermission('procurement.suppliers.view')) {
                return redirect()->route('procurement.suppliers.index');
            }

            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthorized. Permission required: view_reports');
        }

        $pulse = $this->dashboardService->getPulse(
            $user, 
            $request->query('branch_id')
        );

        return Inertia::render('Dashboard', [
            'pulse' => $pulse,
            'branches' => $user->branches()->select('branches.id', 'branches.name')->get()
        ]);
    }
}
