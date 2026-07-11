<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RegisterActivationController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected CacheBootstrapService $bootstrapService
    ) {}

    /**
     * POST /api/pos/activate
     *
     * Activate a device binding for a SalesMachineProfile.
     */
    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activation_code' => ['required', 'string', 'min:8', 'max:20'],
            'device_id'       => ['required', 'string', 'max:255'],
        ]);

        $tokenHash = hash('sha256', $validated['activation_code']);

        $profile = SalesMachineProfile::where('activation_token_hash', $tokenHash)
            ->where('activation_token_expires_at', '>', now())
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired activation code.',
            ], 422);
        }

        if (in_array($profile->activation_status, [SalesMachineProfile::STATUS_SUSPENDED, SalesMachineProfile::STATUS_REVOKED])) {
            return response()->json([
                'success' => false,
                'message' => "Terminal is {$profile->activation_status} and cannot be activated.",
            ], 403);
        }

        // Complete activation
        $profile->update([
            'activated_at'                => now(),
            'activated_device_id'         => $validated['device_id'],
            'activation_status'           => SalesMachineProfile::STATUS_ACTIVE,
            'activation_token_hash'       => null,
            'activation_token_expires_at' => null,
            'last_activated_ip'           => $request->ip(),
        ]);

        // Load contexts for config snapshot bootstrap
        $tenant = $profile->tenant;
        $branch = $profile->branch;

        $bootstrapPayload = $this->bootstrapService->generatePayload($tenant, $branch, null, $profile);

        // Generate a pseudo-token for terminal API calls
        $terminalAuthToken = 'pseudo-terminal-token-' . bin2hex(random_bytes(16));

        return response()->json([
            'success'                  => true,
            'terminal_auth_token'      => $terminalAuthToken,
            'sales_machine_profile_id' => $profile->id,
            'tenant_id'                => $tenant->id,
            'branch_id'                => $branch->id,
            'profile_code'             => $profile->profile_code,
            'terminal_identifier'      => $profile->terminal_identifier,
            'config_snapshot'          => $bootstrapPayload['config_snapshot'] ?? null,
            'config_snapshot_hashes'   => [
                'catalog'         => $bootstrapPayload['catalog_version_hash'] ?? null,
                'tax'             => $bootstrapPayload['tax_configuration_version_hash'] ?? null,
                'layout'          => $bootstrapPayload['layout_version_hash'] ?? null,
                'discount_rules'  => $bootstrapPayload['discount_rules_version_hash'] ?? null,
                'payment_methods' => $bootstrapPayload['payment_methods_version_hash'] ?? null,
                'terminal_policy' => $bootstrapPayload['terminal_policy_version_hash'] ?? null,
                'printer_profile' => $bootstrapPayload['printer_profile_version_hash'] ?? null,
                'config_snapshot' => $bootstrapPayload['config_snapshot_hash'] ?? null,
            ],
            'heartbeat_schedule'       => '*/5 * * * *',
            'offline_policy'           => [
                'allow_offline' => (bool) $profile->offline_sales_enabled,
            ],
        ]);
    }
}
