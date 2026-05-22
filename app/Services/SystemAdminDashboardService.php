<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantReadinessSignOff;

class SystemAdminDashboardService
{
    public function __construct(
        protected TenantReadinessService $readinessService,
        protected SystemAdminTenantUrgencyService $urgencyService
    ) {}

    public function getSummary(): array
    {
        $tenants = Tenant::withoutGlobalScopes()->get();
        
        $readinessCounts = [
            'blocked' => 0,
            'ready_for_pilot' => 0,
            'ready_for_operations' => 0,
        ];
        
        $complianceCounts = [
            'tenants_missing_profile' => 0,
            'tenants_missing_plan' => 0,
            'tenants_mismatched_features' => 0,
            'tenants_no_branches' => 0,
            'branches_inactive' => 0,
            'branches_missing_admin' => 0,
            'branches_missing_profile' => 0,
            'branches_incomplete_compliance' => 0,
        ];
        
        $pilotCounts = [
            'branches_ready' => 0,
            'branches_pending' => 0,
            'branches_blocked' => 0,
        ];

        $urgencyCounts = [
            'low' => 0,
            'caution' => 0,
            'critical' => 0,
        ];

        $tenantUrgency = [];
        
        foreach ($tenants as $tenant) {
            $summary = $this->readinessService->getReadinessSummary($tenant);
            
            // Calculate urgency
            $urgency = $this->urgencyService->evaluateFromReadinessSummary($tenant, $summary);
            $band = $urgency['urgency_band'];
            if (isset($urgencyCounts[$band])) {
                $urgencyCounts[$band]++;
            } else {
                $urgencyCounts[$band] = 1;
            }

            $tenantUrgency[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'urgency_band' => $band,
                'score' => $urgency['score'] ?? 0,
                'reasons' => $urgency['reasons'] ?? [],
                'signals' => $urgency['signals'] ?? [],
            ];
            
            $state = $summary['readiness_state'];
            if (isset($readinessCounts[$state])) {
                $readinessCounts[$state]++;
            } else {
                $readinessCounts[$state] = 1;
            }
            
            $detail = $summary['compliance_detail'] ?? [];
            $tenantChecks = $detail['tenant'] ?? [];
            foreach ($tenantChecks as $check) {
                if ($check['status'] === 'failed') {
                    if ($check['code'] === 'tenant_profile_complete') $complianceCounts['tenants_missing_profile']++;
                    if ($check['code'] === 'subscription_plan_assigned') $complianceCounts['tenants_missing_plan']++;
                    if ($check['code'] === 'feature_gates_aligned') $complianceCounts['tenants_mismatched_features']++;
                    if ($check['code'] === 'branch_exists') $complianceCounts['tenants_no_branches']++;
                }
            }
            
            $branchesChecks = $detail['branches'] ?? [];
            foreach ($branchesChecks as $branchData) {
                $bChecks = $branchData['checks'] ?? [];
                foreach ($bChecks as $check) {
                    if ($check['code'] === 'pilot_eligibility') {
                        if ($check['status'] === 'passed') {
                            $pilotCounts['branches_ready']++;
                        } elseif ($check['status'] === 'pending') {
                            $pilotCounts['branches_pending']++;
                        } elseif ($check['status'] === 'failed') {
                            $pilotCounts['branches_blocked']++;
                        }
                    } else {
                        if ($check['status'] === 'failed') {
                            if ($check['code'] === 'branch_active') $complianceCounts['branches_inactive']++;
                            if ($check['code'] === 'branch_admin_coverage') $complianceCounts['branches_missing_admin']++;
                            if ($check['code'] === 'machine_profile_present') $complianceCounts['branches_missing_profile']++;
                            if ($check['code'] === 'machine_profile_compliance') $complianceCounts['branches_incomplete_compliance']++;
                        }
                    }
                }
            }
        }
        
        $recentSignOffs = TenantReadinessSignOff::withoutGlobalScopes()
            ->with(['tenant', 'signer'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (TenantReadinessSignOff $signOff) {
                return [
                    'id' => $signOff->id,
                    'tenant_id' => $signOff->tenant_id,
                    'tenant_name' => $signOff->tenant->name ?? 'Unknown',
                    'signed_off_state' => $signOff->signed_off_state,
                    'notes' => $signOff->notes,
                    'signer_name' => $signOff->signer->name ?? $signOff->signer->email ?? 'Unknown',
                    'created_at' => $signOff->created_at->toIso8601String(),
                ];
            })->values()->all();
            
        return [
            'readiness_counts' => $readinessCounts,
            'compliance_counts' => $complianceCounts,
            'pilot_counts' => $pilotCounts,
            'urgency_counts' => $urgencyCounts,
            'tenant_urgency' => $tenantUrgency,
            'recent_sign_offs' => $recentSignOffs,
        ];
    }
}
