<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\TerminalHeartbeatRequest;
use App\Models\SalesMachineProfile;
use App\Models\TerminalConfigHeartbeat;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\POS\TerminalLayoutResolver;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class TerminalHeartbeatController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected TerminalLayoutResolver $layoutResolver,
        protected CacheBootstrapService $bootstrapService
    ) {}

    /**
     * POST /api/pos/heartbeat
     *
     * Record a heartbeat from a terminal profile.
     */
    public function store(TerminalHeartbeatRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error'   => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.',
            ], 403);
        }

        // Try getting terminal profile from middleware context first
        $profile = $request->attributes->get('terminal_profile');

        if (!$profile) {
            // Fallback resolution
            $requestedTerminalId = $request->header('X-Terminal-ID')
                ?: $request->input('terminal_id')
                ?: $request->input('sales_machine_profile_id');

            $profile = SalesMachineProfile::where('tenant_id', $tenant->id)
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->where(function ($query) use ($requestedTerminalId) {
                    $query->where('id', $requestedTerminalId)
                          ->orWhere('terminal_identifier', $requestedTerminalId);
                })
                ->first();
        }

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => 'No active terminal profile found.',
            ], 422);
        }

        $validated = $request->validated();
        $reportedAt = isset($validated['reported_at']) ? Carbon::parse($validated['reported_at']) : now();

        $heartbeat = TerminalConfigHeartbeat::updateOrCreate(
            [
                'sales_machine_profile_id' => $profile->id,
            ],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'app_version' => $validated['app_version'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'config_snapshot' => $validated['config_snapshot'] ?? null,
                'last_snapshot_downloaded_at' => isset($validated['last_snapshot_downloaded_at'])
                    ? Carbon::parse($validated['last_snapshot_downloaded_at'])
                    : null,
                'last_successful_sync_at' => isset($validated['last_successful_sync_at'])
                    ? Carbon::parse($validated['last_successful_sync_at'])
                    : null,
                'queue_count' => $validated['queue_count'] ?? 0,
                'connection_state' => $validated['connection_state'] ?? 'online',
                'reported_at' => $reportedAt,
            ]
        );

        return response()->json([
            'success'      => true,
            'heartbeat_id' => $heartbeat->id,
            'reported_at'  => $heartbeat->reported_at->toIso8601String(),
            ...$this->buildDriftResponse($profile, $validated),
        ]);
    }

    /**
     * Compare client-reported layout hash against the server-resolved layout hash
     * and return a structured drift response.
     *
     * Only layout drift is evaluated here; full config drift remains in the admin
     * sync monitor. This keeps the heartbeat response lightweight and focused.
     */
    private function buildDriftResponse(SalesMachineProfile $profile, array $validated): array
    {
        $clientLayoutHash = data_get($validated, 'config_snapshot.layout_version_hash')
            ?? data_get($validated, 'layout_version_hash');

        // Server-resolved layout hash (respects terminal override)
        $serverLayoutHash = $this->layoutResolver->resolveHashForProfile($profile, $this->bootstrapService);
        $serverLayout     = $this->layoutResolver->resolveForProfile($profile);
        $layoutDrift      = $clientLayoutHash !== null && $clientLayoutHash !== $serverLayoutHash;

        return [
            'layout_drift'       => $layoutDrift,
            'server_layout_hash' => $serverLayoutHash,
            'server_layout_name' => $serverLayout?->name,
            'config_drift'       => [
                'has_config_drift'   => $layoutDrift,
                'drifted_components' => $layoutDrift ? ['layout'] : [],
            ],
        ];
    }
}
