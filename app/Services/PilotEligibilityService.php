<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineReadiness\OfflineSettingsValidator;

class PilotEligibilityService
{
    /**
     * Required compliance fields — mirrors TenantProvisioningController and OnboardingService checks.
     */
    private const COMPLIANCE_FIELDS = [
        'machine_identification_number',
        'machine_serial_number',
        'permit_to_use_number',
        'authority_to_generate_control_number',
        'supplier_accreditation_number',
    ];

    public function __construct(
        protected OfflineSettingsValidator $settingsValidator
    ) {}

    /**
     * Evaluate all pilot eligibility checks for the given tenant, branch, and machine profile.
     *
     * The offline settings checks (tenant/branch/terminal enabled, prefix, sequence status) mirror
     * the logic in OfflineSettingsValidator. That validator short-circuits on first failure, which
     * is appropriate for the POS runtime. Here we evaluate every check independently so the
     * eligibility dashboard can display the full checklist state.
     *
     * @param Tenant $tenant
     * @param Branch|null $branch
     * @param SalesMachineProfile|null $profile
     * @return array{outcome: string, checks: array, blocking_reasons: array, pending_reasons: array}
     */
    public function evaluate(Tenant $tenant, ?Branch $branch, ?SalesMachineProfile $profile): array
    {
        $raw = [];

        // ──────────────────────────────────────────────────────────────────────
        // Blocked-level checks — missing prerequisites prevent any further action
        // ──────────────────────────────────────────────────────────────────────

        $tenantActive = $tenant->status === 'active';
        $raw[] = $this->check(
            'tenant_active',
            'blocked',
            $tenantActive,
            'Tenant is active.',
            "Tenant status is '{$tenant->status}'."
        );

        $branchExists = $branch !== null
            || Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
        $raw[] = $this->check(
            'branch_exists',
            'blocked',
            $branchExists,
            'Branch exists for this tenant.',
            'No branch exists for this tenant.'
        );

        $ownerExists = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('actor_type', 'tenant_user')
            ->where('status', 'active')
            ->exists();
        $raw[] = $this->check(
            'owner_exists',
            'blocked',
            $ownerExists,
            'An active owner or admin account exists.',
            'No active owner or admin account found.'
        );

        $profileExists = $profile !== null
            || ($branch && SalesMachineProfile::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->exists());
        $raw[] = $this->check(
            'machine_profile_exists',
            'blocked',
            $profileExists,
            'A sales machine profile exists for this branch.',
            'No sales machine profile found for this branch.'
        );

        // ──────────────────────────────────────────────────────────────────────
        // Pending-level checks — prerequisites exist but readiness is incomplete
        // ──────────────────────────────────────────────────────────────────────

        // 5. Machine compliance fields
        $complianceComplete = $profile && $this->isComplianceComplete($profile);
        $raw[] = $this->check(
            'machine_profile_compliance_complete',
            'pending',
            $complianceComplete,
            'All required machine compliance fields are complete.',
            'One or more required compliance fields are missing (MIN, MSN, PTU, ATGCN, supplier accreditation number).'
        );

        // 6. Tenant-level offline setting
        $tenantOffline = (bool) $tenant->offline_sales_enabled;
        $raw[] = $this->check(
            'tenant_offline_enabled',
            'pending',
            $tenantOffline,
            'Controlled offline sales is enabled at the tenant level.',
            'Controlled offline sales is disabled at the tenant level.'
        );

        // 7. Branch-level offline setting
        $branchOffline = $branch ? (bool) $branch->offline_sales_enabled : false;
        $raw[] = $this->check(
            'branch_offline_enabled',
            'pending',
            $branchOffline,
            'Controlled offline sales is enabled at the branch level.',
            'Controlled offline sales is disabled at the branch level.'
        );

        // 8. Terminal-level offline setting (null inherits enabled, matching OfflineSettingsValidator logic)
        $terminalOffline = $profile ? ($profile->offline_sales_enabled !== false) : false;
        $raw[] = $this->check(
            'terminal_offline_enabled',
            'pending',
            $terminalOffline,
            'Controlled offline sales is enabled or inherited for this terminal.',
            'Controlled offline sales is explicitly disabled for this terminal.'
        );

        // 9. Offline sequence prefix
        $prefixAssigned = $profile && !blank($profile->offline_sequence_prefix);
        $prefixMsg = $prefixAssigned
            ? "Sequence prefix '{$profile->offline_sequence_prefix}' is assigned."
            : 'No offline sequence prefix is assigned to this terminal.';
        $raw[] = $this->check('offline_prefix_assigned', 'pending', $prefixAssigned, $prefixMsg, $prefixMsg);

        // 10. Sequence status (null defaults to active, matching OfflineSettingsValidator logic)
        $sequenceStatus = $profile ? ($profile->offline_sequence_status ?? 'active') : null;
        $sequenceActive = $sequenceStatus === 'active';
        $raw[] = $this->check(
            'offline_sequence_active',
            'pending',
            $sequenceActive,
            'Offline sequence is active.',
            $sequenceStatus
                ? "Offline sequence status is '{$sequenceStatus}'."
                : 'Offline sequence status is not set.'
        );

        // 11. Required permission assigned to at least one role in this tenant
        $permissionAssigned = Permission::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'manage_offline_sales_settings')
            ->whereHas('roles', fn ($q) => $q->withoutGlobalScopes())
            ->exists();
        $raw[] = $this->check(
            'manage_offline_permission_assigned',
            'pending',
            $permissionAssigned,
            'manage_offline_sales_settings permission is assigned to a role.',
            'No role has been assigned the manage_offline_sales_settings permission.'
        );

        // ──────────────────────────────────────────────────────────────────────
        // Resolve overall outcome
        // ──────────────────────────────────────────────────────────────────────

        $hasBlocked = collect($raw)->contains(fn ($c) => $c['level'] === 'blocked' && $c['status'] === 'fail');
        $hasPending = collect($raw)->contains(fn ($c) => $c['level'] === 'pending' && $c['status'] === 'fail');

        $outcome = match (true) {
            $hasBlocked => 'blocked',
            $hasPending => 'pending',
            default => 'ready',
        };

        $blockingReasons = collect($raw)
            ->filter(fn ($c) => $c['level'] === 'blocked' && $c['status'] === 'fail')
            ->pluck('key')
            ->values()
            ->all();

        $pendingReasons = collect($raw)
            ->filter(fn ($c) => $c['level'] === 'pending' && $c['status'] === 'fail')
            ->pluck('key')
            ->values()
            ->all();

        // Strip internal 'level' from the public payload
        $checks = collect($raw)
            ->map(fn ($c) => ['key' => $c['key'], 'status' => $c['status'], 'message' => $c['message']])
            ->all();

        return [
            'outcome' => $outcome,
            'checks' => $checks,
            'blocking_reasons' => $blockingReasons,
            'pending_reasons' => $pendingReasons,
        ];
    }

    private function check(string $key, string $level, bool $pass, string $passMsg, string $failMsg): array
    {
        return [
            'key' => $key,
            'level' => $level,
            'status' => $pass ? 'pass' : 'fail',
            'message' => $pass ? $passMsg : $failMsg,
        ];
    }

    private function isComplianceComplete(SalesMachineProfile $profile): bool
    {
        foreach (self::COMPLIANCE_FIELDS as $field) {
            if (blank($profile->{$field})) {
                return false;
            }
        }

        return true;
    }
}
