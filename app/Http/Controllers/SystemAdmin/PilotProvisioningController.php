<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\PilotEligibilityService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PilotProvisioningController extends Controller
{
    public function __construct(
        protected PilotEligibilityService $eligibilityService,
        protected AuditLogger $auditLogger,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Return a read-only pilot eligibility checklist for the given tenant.
     *
     * Query parameters (both optional):
     *   - branch_id:  resolve a specific branch; defaults to the tenant's first branch
     *   - profile_id: resolve a specific machine profile; defaults to the branch's first profile
     *
     * Returns a JSON payload containing:
     *   - outcome:          ready | pending | blocked
     *   - checks[]:         individual checklist items with key, status, message
     *   - blocking_reasons: keys of failed blocked-level checks
     *   - pending_reasons:  keys of failed pending-level checks
     *
     * This endpoint performs no mutations. It does not enable or disable offline sales.
     */
    public function eligibility(Request $request, Tenant $company): JsonResponse
    {
        $branch = $this->resolveBranch($request, $company);
        $profile = $this->resolveProfile($request, $company, $branch);

        $result = $this->eligibilityService->evaluate($company, $branch, $profile);

        return response()->json([
            'tenant' => [
                'id' => $company->id,
                'name' => $company->name,
            ],
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
            ] : null,
            'profile' => $profile ? [
                'id' => $profile->id,
                'profile_code' => $profile->profile_code,
            ] : null,
            'outcome' => $result['outcome'],
            'checks' => $result['checks'],
            'blocking_reasons' => $result['blocking_reasons'],
            'pending_reasons' => $result['pending_reasons'],
        ]);
    }

    /**
     * Enable controlled offline sales for a specific branch/terminal in pilot scope.
     *
     * Applies only the flags requested in the body (tenant/branch/terminal level).
     * Runs inside a transaction with a pre-write and post-write eligibility check.
     * If the post-write outcome is not 'ready', the transaction is rolled back.
     *
     * Body (JSON):
     *   - branch_id:       required — target branch
     *   - profile_id:      required — target machine profile
     *   - enable_tenant:   optional boolean — set offline_sales_enabled on the tenant
     *   - enable_branch:   optional boolean — set offline_sales_enabled on the branch
     *   - enable_terminal: optional boolean — set offline_sales_enabled on the profile
     */
    public function enable(Request $request, Tenant $company): JsonResponse
    {
        $request->validate([
            'branch_id'       => ['required', 'string'],
            'profile_id'      => ['required', 'string'],
            'enable_tenant'   => ['sometimes', 'boolean'],
            'enable_branch'   => ['sometimes', 'boolean'],
            'enable_terminal' => ['sometimes', 'boolean'],
        ]);

        $branch = Branch::withoutGlobalScopes()
            ->where('tenant_id', $company->id)
            ->where('id', $request->input('branch_id'))
            ->firstOrFail();

        $profile = SalesMachineProfile::withoutGlobalScopes()
            ->where('tenant_id', $company->id)
            ->where('id', $request->input('profile_id'))
            ->firstOrFail();

        $outcomeBeforeResult = $this->eligibilityService->evaluate($company, $branch, $profile);
        $outcomeBefore = $outcomeBeforeResult['outcome'];

        $enabledAt = null;
        $outcomeAfterResult = null;

        try {
            DB::transaction(function () use (
                $company, $branch, $profile, $request, &$outcomeAfterResult, &$enabledAt
            ) {
                if ($request->boolean('enable_tenant')) {
                    $company->update(['offline_sales_enabled' => true]);
                    $company->refresh();
                }
                if ($request->boolean('enable_branch')) {
                    $branch->update(['offline_sales_enabled' => true]);
                    $branch->refresh();
                }
                if ($request->boolean('enable_terminal')) {
                    $profile->update(['offline_sales_enabled' => true]);
                    $profile->refresh();
                }

                $outcomeAfterResult = $this->eligibilityService->evaluate($company, $branch, $profile);

                if ($outcomeAfterResult['outcome'] !== 'ready') {
                    // Trigger rollback — we'll re-throw after audit
                    throw new \RuntimeException('post_write_not_ready');
                }

                $enabledAt = now()->toIso8601String();
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'post_write_not_ready') {
                $this->platformAudit(
                    'pilot_enable_rejected',
                    $company,
                    $branch,
                    $profile,
                    [
                        'outcome_before' => $outcomeBefore,
                        'outcome_after' => $outcomeAfterResult['outcome'] ?? 'unknown',
                        'pending_reasons' => $outcomeAfterResult['pending_reasons'] ?? [],
                        'blocking_reasons' => $outcomeAfterResult['blocking_reasons'] ?? [],
                    ]
                );

                return response()->json([
                    'success'          => false,
                    'error'            => 'not_ready',
                    'outcome'          => $outcomeAfterResult['outcome'],
                    'checks'           => $outcomeAfterResult['checks'],
                    'blocking_reasons' => $outcomeAfterResult['blocking_reasons'],
                    'pending_reasons'  => $outcomeAfterResult['pending_reasons'],
                ], 422);
            }
            throw $e;
        }

        $this->platformAudit(
            'pilot_enabled',
            $company,
            $branch,
            $profile,
            [
                'outcome_before' => $outcomeBefore,
                'outcome_after'  => 'ready',
                'enabled_at'     => $enabledAt,
            ]
        );

        return response()->json([
            'success'     => true,
            'outcome'     => 'ready',
            'enabled_at'  => $enabledAt,
            'checks'      => $outcomeAfterResult['checks'],
        ]);
    }

    /**
     * Disable controlled offline sales at a specific level (tenant/branch/terminal).
     *
     * Body (JSON):
     *   - branch_id:  required
     *   - profile_id: required
     *   - level:      required — 'tenant' | 'branch' | 'terminal'
     */
    public function disable(Request $request, Tenant $company): JsonResponse
    {
        $request->validate([
            'branch_id'  => ['required', 'string'],
            'profile_id' => ['required', 'string'],
            'level'      => ['required', 'string', 'in:tenant,branch,terminal'],
        ]);

        $branch = Branch::withoutGlobalScopes()
            ->where('tenant_id', $company->id)
            ->where('id', $request->input('branch_id'))
            ->firstOrFail();

        $profile = SalesMachineProfile::withoutGlobalScopes()
            ->where('tenant_id', $company->id)
            ->where('id', $request->input('profile_id'))
            ->firstOrFail();

        $level = $request->input('level');
        $outcomeBefore = $this->eligibilityService->evaluate($company, $branch, $profile)['outcome'];
        $disabledAt = null;

        DB::transaction(function () use ($company, $branch, $profile, $level, &$disabledAt) {
            if ($level === 'tenant') {
                $company->update(['offline_sales_enabled' => false]);
            } elseif ($level === 'branch') {
                $branch->update(['offline_sales_enabled' => false]);
            } elseif ($level === 'terminal') {
                $profile->update(['offline_sales_enabled' => false]);
            }
            $disabledAt = now()->toIso8601String();
        });

        $this->platformAudit(
            'pilot_disabled',
            $company,
            $branch,
            $profile,
            [
                'level'          => $level,
                'outcome_before' => $outcomeBefore,
                'disabled_at'    => $disabledAt,
            ]
        );

        return response()->json([
            'success'     => true,
            'level'       => $level,
            'disabled_at' => $disabledAt,
        ]);
    }

    /**
     * Record a pilot provisioning audit event with the platform actor (no tenant context required).
     */
    private function platformAudit(
        string $action,
        Tenant $company,
        Branch $branch,
        SalesMachineProfile $profile,
        array $metadata
    ): void {
        $this->tenantContext->setTenant($company);

        try {
            $this->auditLogger->log(
                action: $action,
                metadata: array_merge($metadata, [
                    'tenant_id'  => $company->id,
                    'branch_id'  => $branch->id,
                    'profile_id' => $profile->id,
                ])
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function resolveBranch(Request $request, Tenant $company): ?Branch
    {
        if ($request->filled('branch_id')) {
            return Branch::withoutGlobalScopes()
                ->where('tenant_id', $company->id)
                ->where('id', $request->query('branch_id'))
                ->firstOrFail();
        }

        return Branch::withoutGlobalScopes()
            ->where('tenant_id', $company->id)
            ->orderBy('created_at')
            ->first();
    }

    private function resolveProfile(Request $request, Tenant $company, ?Branch $branch): ?SalesMachineProfile
    {
        if ($request->filled('profile_id')) {
            return SalesMachineProfile::withoutGlobalScopes()
                ->where('tenant_id', $company->id)
                ->where('id', $request->query('profile_id'))
                ->firstOrFail();
        }

        if (!$branch) {
            return null;
        }

        return SalesMachineProfile::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->orderBy('created_at')
            ->first();
    }
}
