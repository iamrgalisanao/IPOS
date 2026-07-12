<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegisterActivationController extends Controller
{
    public function __construct(
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

        $tokenHash = hash('sha256', strtoupper($validated['activation_code']));

        $activation = DB::transaction(function () use ($tokenHash, $validated, $request) {
            $profile = SalesMachineProfile::withoutGlobalScopes()
                ->where('activation_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (!$profile
                || !$profile->activation_token_expires_at
                || $profile->activation_token_expires_at->isPast()
                || $profile->activation_status !== SalesMachineProfile::STATUS_PENDING_ACTIVATION
                || $profile->status !== 'active'
            ) {
                return null;
            }

            $profile->update([
                'activated_at'                => now(),
                'activated_device_id'         => $validated['device_id'],
                'activation_status'           => SalesMachineProfile::STATUS_ACTIVE,
                'activation_token_hash'       => null,
                'activation_token_expires_at' => null,
                'last_activated_ip'           => $request->ip(),
            ]);

            $tenant = Tenant::withoutGlobalScopes()->findOrFail($profile->tenant_id);
            $branch = Branch::withoutGlobalScopes()->findOrFail($profile->branch_id);
            $bootstrapPayload = $this->bootstrapService->generatePayload($tenant, $branch, null, $profile);

            return compact('profile', 'tenant', 'branch', 'bootstrapPayload');
        });

        if (!$activation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid, expired, or unavailable activation code.',
            ], 422);
        }

        ['profile' => $profile, 'tenant' => $tenant, 'branch' => $branch, 'bootstrapPayload' => $bootstrapPayload] = $activation;

        return response()->json([
            'success' => true,
            'terminal' => [
                'sales_machine_profile_id' => $profile->id,
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'profile_code' => $profile->profile_code,
                'terminal_identifier' => $profile->terminal_identifier,
            ],
            'bootstrap_payload' => $bootstrapPayload,
        ]);
    }
}
