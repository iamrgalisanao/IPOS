<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\Dining\DiningFloorReadModel;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiningFloorMapController extends Controller
{
    public function __construct(
        private readonly DiningFloorReadModel $floorReadModel,
        private readonly BranchContext $branchContext,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function show(Request $request): Response
    {
        return Inertia::render('POS/Terminal/FloorMap', [
            'terminal_context' => $this->terminalContext($request),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $terminal = $request->attributes->get('terminal_profile');

        if (!$terminal instanceof SalesMachineProfile) {
            return response()->json([
                'code' => 'TERMINAL_CONTEXT_INVALID',
                'message' => 'Terminal context is required to load the dining floor map.',
            ], 403);
        }

        return response()->json([
            'data' => $this->floorReadModel->forBranch(
                (string) $this->tenantContext->getTenantId(),
                (string) $this->branchContext->getBranchId(),
                $terminal,
            ),
        ]);
    }

    private function terminalContext(Request $request): array
    {
        $terminal = $request->attributes->get('terminal_profile');
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        return [
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
            ] : null,
            'terminal' => $terminal instanceof SalesMachineProfile ? [
                'id' => $terminal->id,
                'profile_code' => $terminal->profile_code,
                'terminal_identifier' => $terminal->terminal_identifier,
                'machine_identification_number' => $terminal->machine_identification_number,
                'status' => $terminal->status,
                'activation_status' => $terminal->activation_status,
                'activated_device_id' => $terminal->activated_device_id,
            ] : null,
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ] : null,
        ];
    }
}
