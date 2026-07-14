<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\StoreDiningTableRequest;
use App\Http\Requests\Dining\StoreServiceAreaRequest;
use App\Http\Requests\Dining\UpdateActivationRequest;
use App\Http\Requests\Dining\UpdateDiningLayoutRequest;
use App\Http\Requests\Dining\UpdateDiningTableRequest;
use App\Http\Requests\Dining\UpdateServiceAreaRequest;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\ServiceArea;
use App\Services\Dining\DiningLayoutMetadataValidator;
use App\Services\Dining\DiningLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ServiceAreaController extends Controller
{
    public function __construct(
        private readonly DiningLayoutService $layoutService,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('Admin/ServiceAreas/Index', $this->indexProps(
            $request,
            $request->query('branch_id')
        ));
    }

    public function show(Request $request, ServiceArea $serviceArea): Response
    {
        $this->authorizeManage($request);
        $this->layoutService->assertBranchAccess($serviceArea->branch, $request->user());

        return Inertia::render('Admin/ServiceAreas/Index', $this->indexProps(
            $request,
            $serviceArea->branch_id,
            $serviceArea->id
        ));
    }

    public function store(StoreServiceAreaRequest $request): RedirectResponse|JsonResponse
    {
        $area = $this->layoutService->createServiceArea($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return response()->json(['service_area' => $area], 201);
        }

        return redirect()
            ->route('admin.service-areas.show', $area)
            ->with('success', 'Service area created.');
    }

    public function update(UpdateServiceAreaRequest $request, ServiceArea $serviceArea): RedirectResponse|JsonResponse
    {
        $area = $this->layoutService->updateServiceArea($serviceArea, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return response()->json(['service_area' => $area]);
        }

        return back()->with('success', 'Service area updated.');
    }

    public function destroy(Request $request, ServiceArea $serviceArea): RedirectResponse|JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $this->layoutService->deleteServiceArea($serviceArea, $request->user());
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(null, 204);
        }

        return redirect()
            ->route('admin.service-areas.index')
            ->with('success', 'Service area deleted.');
    }

    public function activation(UpdateActivationRequest $request, ServiceArea $serviceArea): RedirectResponse|JsonResponse
    {
        try {
            $area = $this->layoutService->setServiceAreaActivation(
                $serviceArea,
                (bool) $request->validated('is_active'),
                $request->user()
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['service_area' => $area]);
        }

        return back()->with('success', 'Service area activation updated.');
    }

    public function storeTable(StoreDiningTableRequest $request, ServiceArea $serviceArea): RedirectResponse|JsonResponse
    {
        try {
            $table = $this->layoutService->createDiningTable($serviceArea, $request->validated(), $request->user());
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['dining_table' => $table], 201);
        }

        return back()->with('success', 'Dining table created.');
    }

    public function updateTable(
        UpdateDiningTableRequest $request,
        ServiceArea $serviceArea,
        DiningTable $diningTable
    ): RedirectResponse|JsonResponse {
        $table = $this->layoutService->updateDiningTable(
            $serviceArea,
            $diningTable,
            $request->validated(),
            $request->user()
        );

        if ($request->expectsJson()) {
            return response()->json(['dining_table' => $table]);
        }

        return back()->with('success', 'Dining table updated.');
    }

    public function destroyTable(
        Request $request,
        ServiceArea $serviceArea,
        DiningTable $diningTable
    ): RedirectResponse|JsonResponse {
        $this->authorizeManage($request);

        try {
            $this->layoutService->deleteDiningTable($serviceArea, $diningTable, $request->user());
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(null, 204);
        }

        return back()->with('success', 'Dining table deleted.');
    }

    public function tableActivation(
        UpdateActivationRequest $request,
        ServiceArea $serviceArea,
        DiningTable $diningTable
    ): RedirectResponse|JsonResponse {
        try {
            $table = $this->layoutService->setDiningTableActivation(
                $serviceArea,
                $diningTable,
                (bool) $request->validated('is_active'),
                $request->user()
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['dining_table' => $table]);
        }

        return back()->with('success', 'Dining table activation updated.');
    }

    public function layout(UpdateDiningLayoutRequest $request, ServiceArea $serviceArea): RedirectResponse|JsonResponse
    {
        try {
            $area = $this->layoutService->saveLayout($serviceArea, $request->validated(), $request->user());
        } catch (ConflictHttpException $exception) {
            return $this->conflict($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['service_area' => $area]);
        }

        return back()->with('success', 'Dining layout saved.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('pos-layouts.manage'), 403);
    }

    private function indexProps(Request $request, ?string $branchId = null, ?string $selectedAreaId = null): array
    {
        $branches = Branch::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'branch_code'])
            ->filter(fn (Branch $branch) => $request->user()->canAccessBranch($branch))
            ->values();

        $branchId = $branchId ?: $branches->first()?->id;
        if ($branchId && !$branches->contains('id', $branchId)) {
            $branchId = $branches->first()?->id;
        }

        $areas = ServiceArea::query()
            ->with(['tables' => fn ($query) => $query->orderBy('table_number')])
            ->when(
                $branchId,
                fn ($query) => $query->where('branch_id', $branchId),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->orderBy('name')
            ->get();

        return [
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'selectedAreaId' => $selectedAreaId,
            'serviceAreas' => $areas,
            'defaults' => [
                'layout_metadata' => DiningLayoutMetadataValidator::defaultLayout(),
                'position_metadata' => DiningLayoutMetadataValidator::defaultPosition(),
            ],
        ];
    }

    private function conflict(Request $request, ConflictHttpException $exception): RedirectResponse|JsonResponse
    {
        $message = $exception->getMessage();
        $decoded = json_decode($message, true);

        if ($request->expectsJson()) {
            return response()->json(
                is_array($decoded)
                    ? $decoded
                    : ['code' => 'DINING_LAYOUT_CONFLICT', 'message' => $message],
                409
            );
        }

        return back()->withErrors([
            'conflict' => is_array($decoded) ? ($decoded['message'] ?? 'Dining layout conflict.') : $message,
        ]);
    }
}
