<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;

class TenantReadinessService
{
    public const DECISION_READY_FOR_PILOT = 'ready_for_pilot';
    public const DECISION_READY_FOR_OPERATIONS = 'ready_for_operations';
    public const DECISION_BLOCKED = 'blocked';

    public const ALLOWED_DECISIONS = [
        self::DECISION_READY_FOR_PILOT,
        self::DECISION_READY_FOR_OPERATIONS,
        self::DECISION_BLOCKED,
    ];

    private const REQUIRED_COMPLIANCE_FIELDS = [
        'machine_identification_number',
        'machine_serial_number',
        'permit_to_use_number',
        'authority_to_generate_control_number',
        'supplier_accreditation_number',
    ];

    public function __construct(
        protected PilotEligibilityService $pilotEligibilityService
    ) {}

    public function getReadinessSummary(Tenant $tenant): array
    {
        $branches = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        $admins = $this->collectAdmins($tenant);

        $branchRows = [];
        $blockers = [];
        $pendingActions = [];

        foreach ($branches as $branch) {
            $profile = SalesMachineProfile::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('branch_id', $branch->id)
                ->orderBy('created_at')
                ->first();

            $complianceMissingFields = $profile ? $this->missingComplianceFields($profile) : [];

            $pilot = $this->pilotEligibilityService->evaluate($tenant, $branch, $profile);
            $complianceComplete = $profile ? $this->isComplianceComplete($profile) : false;
            $hasBranchAdmin = $this->hasBranchAdmin($tenant, $branch);

            if (!$hasBranchAdmin) {
                $blockers[] = "branch:{$branch->id}:missing_admin";
                $pendingActions[] = "Assign at least one active admin to branch {$branch->name}.";
            }

            if ($profile && !$complianceComplete) {
                $blockers[] = "branch:{$branch->id}:compliance_incomplete";
                $pendingActions[] = "Complete sales machine compliance fields for branch {$branch->name}.";
            }

            if (!$profile) {
                $blockers[] = "branch:{$branch->id}:profile_missing";
                $pendingActions[] = "Register a sales machine profile for branch {$branch->name}.";
            }

            if ($branch->status !== 'active') {
                $blockers[] = "branch:{$branch->id}:inactive";
                $pendingActions[] = "Activate branch {$branch->name}.";
            }

            $branchRows[] = [
                'id' => $branch->id,
                'name' => $branch->name,
                'status' => $branch->status,
                'has_admin' => $hasBranchAdmin,
                'compliance_complete' => $complianceComplete,
                'pilot_ready' => $pilot['outcome'] === 'ready',
                'pilot_outcome' => $pilot['outcome'],
                'pilot_blocking_reasons' => $pilot['blocking_reasons'],
                'pilot_pending_reasons' => $pilot['pending_reasons'],
                'compliance_missing_fields' => $complianceMissingFields,
                'profile' => $profile ? [
                    'id' => $profile->id,
                    'profile_code' => $profile->profile_code,
                    'offline_sales_enabled' => (bool) ($profile->offline_sales_enabled !== false),
                    'offline_sequence_prefix' => $profile->offline_sequence_prefix,
                    'offline_sequence_status' => $profile->offline_sequence_status ?? 'active',
                ] : null,
            ];
        }

        $tenantProfileComplete = !blank($tenant->name) && !blank($tenant->status);
        $subscriptionPlan = ($tenant->subscription_metadata ?? [])['plan'] ?? null;
        $subscriptionPlanAssigned = !blank($subscriptionPlan);
        $featureGateMismatches = $this->featureGateMismatches($tenant);
        $featureGatesAligned = count($featureGateMismatches) === 0;

        $pilotEligibleBranches = collect($branchRows)->filter(fn (array $b) => $b['pilot_outcome'] === 'ready')->count();
        $branchCount = count($branchRows);
        $allBranchesActive = $branchCount > 0 && collect($branchRows)->every(fn (array $b) => $b['status'] === 'active');
        $allBranchesHaveAdmin = $branchCount > 0 && collect($branchRows)->every(fn (array $b) => $b['has_admin'] === true);
        $allProfilesComplianceComplete = $branchCount > 0 && collect($branchRows)->every(fn (array $b) => $b['compliance_complete'] === true);

        if (!$tenantProfileComplete) {
            $blockers[] = 'tenant_profile_incomplete';
            $pendingActions[] = 'Complete tenant profile status and identity details.';
        }

        if (!$subscriptionPlanAssigned) {
            $blockers[] = 'subscription_plan_missing';
            $pendingActions[] = 'Assign a subscription plan to the tenant.';
        }

        if (!$featureGatesAligned) {
            $blockers[] = 'feature_gates_misaligned';
            $pendingActions[] = 'Resolve subscription feature override mismatches against configured tier features.';
        }

        if ($branchCount === 0) {
            $blockers[] = 'branch_missing';
            $pendingActions[] = 'Create at least one branch.';
        }

        $blockers = array_values(array_unique($blockers));
        $pendingActions = array_values(array_unique($pendingActions));

        $readinessState = $this->calculateReadinessState(
            hasBlockers: count($blockers) > 0,
            allBranchesActive: $allBranchesActive,
            allBranchesHaveAdmin: $allBranchesHaveAdmin,
            allProfilesComplianceComplete: $allProfilesComplianceComplete,
            pilotEligibleBranches: $pilotEligibleBranches,
            branchCount: $branchCount,
            tenantActive: $tenant->status === 'active'
        );

        $complianceDetail = $this->buildComplianceDetail(
            tenant: $tenant,
            branchRows: $branchRows,
            tenantProfileComplete: $tenantProfileComplete,
            subscriptionPlan: $subscriptionPlan,
            subscriptionPlanAssigned: $subscriptionPlanAssigned,
            featureGateMismatches: $featureGateMismatches,
            branchCount: $branchCount
        );

        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'tenant_status' => $tenant->status,
            'subscription_plan' => $subscriptionPlan,
            'branches' => $branchRows,
            'admins' => $admins,
            'blockers' => $blockers,
            'pending_actions' => $pendingActions,
            'readiness_state' => $readinessState,
            'compliance_detail' => $complianceDetail,
            'checks' => [
                'tenant_profile_complete' => $tenantProfileComplete,
                'subscription_plan_assigned' => $subscriptionPlanAssigned,
                'branch_count' => $branchCount,
                'all_branches_active' => $allBranchesActive,
                'all_branches_have_admin' => $allBranchesHaveAdmin,
                'all_profiles_compliance_complete' => $allProfilesComplianceComplete,
                'feature_gates_aligned' => $featureGatesAligned,
                'pilot_eligibility_ready_branches' => $pilotEligibleBranches,
            ],
        ];
    }

    public function evaluateSignOffDecision(Tenant $tenant, string $decision, ?string $notes = null): array
    {
        $summary = $this->getReadinessSummary($tenant);
        $calculatedState = $summary['readiness_state'];
        $blockers = $summary['blockers'];
        $hasBlockers = count($blockers) > 0;
        $notesPresent = !blank($notes);

        $allowed = true;
        $message = null;

        if (!in_array($decision, self::ALLOWED_DECISIONS, true)) {
            $allowed = false;
            $message = 'Invalid readiness decision.';
        } elseif ($decision === self::DECISION_BLOCKED) {
            if (!$notesPresent) {
                $allowed = false;
                $message = 'Blocked readiness decisions require notes.';
            }
        } elseif ($hasBlockers) {
            $allowed = false;
            $message = 'Tenant readiness cannot be signed off while blockers remain.';
        } elseif ($decision === self::DECISION_READY_FOR_OPERATIONS && $calculatedState !== self::DECISION_READY_FOR_OPERATIONS) {
            $allowed = false;
            $message = 'Tenant readiness is not eligible for operations sign-off.';
        } elseif (
            $decision === self::DECISION_READY_FOR_PILOT
            && !in_array($calculatedState, [self::DECISION_READY_FOR_PILOT, self::DECISION_READY_FOR_OPERATIONS], true)
        ) {
            $allowed = false;
            $message = 'Tenant readiness is not eligible for pilot sign-off.';
        }

        return [
            'allowed' => $allowed,
            'message' => $message,
            'decision' => $decision,
            'readiness_state_calculated' => $calculatedState,
            'blockers' => $blockers,
            'summary' => $summary,
            'snapshot' => $this->buildSignOffSnapshot($summary),
        ];
    }

    public function buildSignOffSnapshot(array $summary): array
    {
        return [
            'tenant_id' => $summary['tenant_id'],
            'tenant_name' => $summary['tenant_name'],
            'tenant_status' => $summary['tenant_status'],
            'subscription_plan' => $summary['subscription_plan'],
            'readiness_state' => $summary['readiness_state'],
            'checks' => $summary['checks'],
            'blockers' => $summary['blockers'],
            'pending_actions' => $summary['pending_actions'],
            'compliance_detail' => $summary['compliance_detail'] ?? null,
            'branches' => $summary['branches'],
            'admins' => $summary['admins'],
        ];
    }

    private function buildComplianceDetail(
        Tenant $tenant,
        array $branchRows,
        bool $tenantProfileComplete,
        ?string $subscriptionPlan,
        bool $subscriptionPlanAssigned,
        array $featureGateMismatches,
        int $branchCount
    ): array {
        $tenantChecks = [
            $this->makeDetailCheck(
                code: 'tenant_profile_complete',
                passed: $tenantProfileComplete,
                source: 'tenants.name,status',
                entityType: 'tenant',
                entityId: $tenant->id,
                passMessage: 'Tenant profile status and identity details are complete.',
                failMessage: 'Tenant profile status or identity details are incomplete.',
                remediation: $tenantProfileComplete ? null : 'Complete tenant profile status and identity details.'
            ),
            $this->makeDetailCheck(
                code: 'subscription_plan_assigned',
                passed: $subscriptionPlanAssigned,
                source: 'tenants.subscription_metadata.plan',
                entityType: 'tenant',
                entityId: $tenant->id,
                passMessage: 'Subscription plan is assigned.',
                failMessage: 'Subscription plan is missing.',
                remediation: $subscriptionPlanAssigned ? null : 'Assign a subscription plan to the tenant.',
                extra: [
                    'plan' => $subscriptionPlan,
                ]
            ),
            $this->makeDetailCheck(
                code: 'feature_gates_aligned',
                passed: count($featureGateMismatches) === 0,
                source: 'tenants.subscription_metadata.features,config/subscriptions.php',
                entityType: 'tenant',
                entityId: $tenant->id,
                passMessage: 'Subscription feature overrides are aligned with configured tier features.',
                failMessage: 'Subscription feature overrides include values outside configured tier features.',
                remediation: count($featureGateMismatches) === 0
                    ? null
                    : 'Resolve subscription feature override mismatches against configured tier features.',
                extra: [
                    'mismatched_features' => $featureGateMismatches,
                ]
            ),
            $this->makeDetailCheck(
                code: 'branch_exists',
                passed: $branchCount > 0,
                source: 'branches.tenant_id',
                entityType: 'tenant',
                entityId: $tenant->id,
                passMessage: 'Tenant has at least one branch.',
                failMessage: 'Tenant has no branches.',
                remediation: $branchCount > 0 ? null : 'Create at least one branch.',
                extra: [
                    'branch_count' => $branchCount,
                ]
            ),
        ];

        $branchChecks = array_map(function (array $branch): array {
            $profile = $branch['profile'];
            $missingFields = $branch['compliance_missing_fields'] ?? [];
            $hasProfile = $profile !== null;
            $compliancePassed = $hasProfile && count($missingFields) === 0;

            $checks = [
                $this->makeDetailCheck(
                    code: 'branch_active',
                    passed: $branch['status'] === 'active',
                    source: 'branches.status',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Branch is active.',
                    failMessage: 'Branch is inactive.',
                    remediation: $branch['status'] === 'active' ? null : "Activate branch {$branch['name']}.",
                ),
                $this->makeDetailCheck(
                    code: 'branch_admin_coverage',
                    passed: $branch['has_admin'] === true,
                    source: 'users.roles,branch_user assignments',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Branch has an active admin.',
                    failMessage: 'Branch is missing an active admin.',
                    remediation: $branch['has_admin'] === true
                        ? null
                        : "Assign at least one active admin to branch {$branch['name']}.",
                ),
                $this->makeDetailCheck(
                    code: 'machine_profile_present',
                    passed: $hasProfile,
                    source: 'sales_machine_profiles.branch_id',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Sales machine profile is registered for branch.',
                    failMessage: 'Sales machine profile is missing for branch.',
                    remediation: $hasProfile ? null : "Register a sales machine profile for branch {$branch['name']}.",
                    extra: [
                        'sales_machine_profile_id' => $profile['id'] ?? null,
                    ]
                ),
                $this->makeDetailCheck(
                    code: 'machine_profile_compliance',
                    passed: $compliancePassed,
                    source: 'sales_machine_profiles.required_compliance_fields',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Sales machine compliance fields are complete.',
                    failMessage: $hasProfile
                        ? 'Sales machine profile compliance fields are incomplete.'
                        : 'Sales machine profile compliance cannot be evaluated because profile is missing.',
                    remediation: $compliancePassed
                        ? null
                        : "Complete sales machine compliance fields for branch {$branch['name']}.",
                    extra: [
                        'sales_machine_profile_id' => $profile['id'] ?? null,
                        'missing_fields' => $missingFields,
                    ]
                ),
            ];

            $pilotBlocking = $branch['pilot_blocking_reasons'] ?? [];
            $pilotPending = $branch['pilot_pending_reasons'] ?? [];
            if (count($pilotBlocking) > 0) {
                $checks[] = $this->makeDetailCheck(
                    code: 'pilot_eligibility',
                    passed: false,
                    source: 'PilotEligibilityService',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Branch is pilot eligible.',
                    failMessage: 'Branch has pilot blocking reasons.',
                    remediation: "Resolve pilot eligibility blockers for branch {$branch['name']}.",
                    severity: 'blocker',
                    status: 'failed',
                    extra: [
                        'pilot_outcome' => $branch['pilot_outcome'],
                        'blocking_reasons' => $pilotBlocking,
                        'pending_reasons' => $pilotPending,
                    ]
                );
            } elseif (count($pilotPending) > 0) {
                $checks[] = $this->makeDetailCheck(
                    code: 'pilot_eligibility',
                    passed: false,
                    source: 'PilotEligibilityService',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Branch is pilot eligible.',
                    failMessage: 'Branch has pilot pending reasons.',
                    remediation: "Resolve pilot pending reasons for branch {$branch['name']}.",
                    severity: 'warning',
                    status: 'pending',
                    extra: [
                        'pilot_outcome' => $branch['pilot_outcome'],
                        'blocking_reasons' => $pilotBlocking,
                        'pending_reasons' => $pilotPending,
                    ]
                );
            } else {
                $checks[] = $this->makeDetailCheck(
                    code: 'pilot_eligibility',
                    passed: true,
                    source: 'PilotEligibilityService',
                    entityType: 'branch',
                    entityId: $branch['id'],
                    passMessage: 'Branch is pilot eligible.',
                    failMessage: 'Branch is not pilot eligible.',
                    remediation: null,
                    extra: [
                        'pilot_outcome' => $branch['pilot_outcome'],
                        'blocking_reasons' => $pilotBlocking,
                        'pending_reasons' => $pilotPending,
                    ]
                );
            }

            return [
                'branch_id' => $branch['id'],
                'branch_name' => $branch['name'],
                'checks' => $checks,
            ];
        }, $branchRows);

        return [
            'tenant' => $tenantChecks,
            'branches' => array_values($branchChecks),
        ];
    }

    private function makeDetailCheck(
        string $code,
        bool $passed,
        string $source,
        string $entityType,
        int|string $entityId,
        string $passMessage,
        string $failMessage,
        ?string $remediation,
        ?string $severity = null,
        ?string $status = null,
        array $extra = []
    ): array {
        $resolvedStatus = $status ?? ($passed ? 'passed' : 'failed');
        $resolvedSeverity = $severity ?? ($passed ? 'info' : 'blocker');

        return array_merge([
            'code' => $code,
            'reason_code' => $code,
            'status' => $resolvedStatus,
            'severity' => $resolvedSeverity,
            'source' => $source,
            'entity' => [
                'type' => $entityType,
                'id' => $entityId,
            ],
            'message' => $passed ? $passMessage : $failMessage,
            'remediation' => $passed ? null : $remediation,
        ], $extra);
    }

    private function calculateReadinessState(
        bool $hasBlockers,
        bool $allBranchesActive,
        bool $allBranchesHaveAdmin,
        bool $allProfilesComplianceComplete,
        int $pilotEligibleBranches,
        int $branchCount,
        bool $tenantActive
    ): string {
        if ($hasBlockers || !$tenantActive || $branchCount === 0) {
            return 'blocked';
        }

        if ($allBranchesActive && $allBranchesHaveAdmin && $allProfilesComplianceComplete && $pilotEligibleBranches === $branchCount) {
            return 'ready_for_operations';
        }

        if ($pilotEligibleBranches > 0) {
            return 'ready_for_pilot';
        }

        return 'blocked';
    }

    private function collectAdmins(Tenant $tenant): array
    {
        $users = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('actor_type', 'tenant_user')
            ->where('status', 'active')
            ->with([
                'roles' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('email')
            ->get();

        return $users->map(function (User $user) {
            return [
                'id' => $user->id,
                'email' => $user->email,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->name ?? $user->email),
                'roles' => $user->roles->pluck('name')->values()->all(),
            ];
        })->values()->all();
    }

    private function hasBranchAdmin(Tenant $tenant, Branch $branch): bool
    {
        $users = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('actor_type', 'tenant_user')
            ->where('status', 'active')
            ->with([
                'roles' => fn ($query) => $query->withoutGlobalScopes()
                    ->with(['permissions' => fn ($permissionQuery) => $permissionQuery->withoutGlobalScopes()]),
                'branches' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->get();

        foreach ($users as $user) {
            $roleNames = $user->roles->pluck('name')->map(fn (string $name) => strtolower($name));
            $isAdminRole = $roleNames->contains(fn (string $name) => str_contains($name, 'owner') || str_contains($name, 'admin'));
            if (!$isAdminRole) {
                continue;
            }

            $hasMultiBranchPermission = $user->roles
                ->flatMap(fn ($role) => $role->permissions)
                ->contains(fn ($permission) => $permission->name === 'view_multi_branch_dashboard');

            if ($hasMultiBranchPermission) {
                return true;
            }

            if ($user->branches->contains(fn (Branch $assigned) => $assigned->id === $branch->id)) {
                return true;
            }
        }

        return false;
    }

    private function featureGatesAligned(Tenant $tenant): bool
    {
        return count($this->featureGateMismatches($tenant)) === 0;
    }

    private function featureGateMismatches(Tenant $tenant): array
    {
        $metadata = $tenant->subscription_metadata ?? [];
        $plan = $metadata['plan'] ?? config('subscriptions.default_tier', 'basic');
        $tierFeatures = array_keys(config("subscriptions.tiers.{$plan}.features", []));
        $overrideFeatures = array_keys($metadata['features'] ?? []);

        $mismatches = [];

        foreach ($overrideFeatures as $feature) {
            if (!in_array($feature, $tierFeatures, true)) {
                $mismatches[] = $feature;
            }
        }

        return array_values(array_unique($mismatches));
    }

    private function isComplianceComplete(SalesMachineProfile $profile): bool
    {
        return count($this->missingComplianceFields($profile)) === 0;
    }

    private function missingComplianceFields(SalesMachineProfile $profile): array
    {
        $missingFields = [];

        foreach (self::REQUIRED_COMPLIANCE_FIELDS as $field) {
            if (blank($profile->{$field})) {
                $missingFields[] = $field;
            }
        }

        return $missingFields;
    }
}
