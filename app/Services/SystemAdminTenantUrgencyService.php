<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantReadinessSignOff;

class SystemAdminTenantUrgencyService
{
    public function __construct(
        protected TenantReadinessService $readinessService
    ) {}

    /**
     * Evaluate the advisory urgency band for a given tenant.
     *
     * @param Tenant $tenant
     * @return array
     */
    public function evaluate(Tenant $tenant): array
    {
        $summary = $this->readinessService->getReadinessSummary($tenant);
        return $this->evaluateFromReadinessSummary($tenant, $summary);
    }

    /**
     * Calculate the urgency based on a pre-fetched readiness summary.
     *
     * @param Tenant $tenant
     * @param array $summary
     * @return array
     */
    public function evaluateFromReadinessSummary(Tenant $tenant, array $summary): array
    {
        $reasons = [];
        
        $readinessState = $summary['readiness_state'] ?? 'blocked';
        $blockers = $summary['blockers'] ?? [];
        $pendingActions = $summary['pending_actions'] ?? [];
        
        $blockerCount = count($blockers);
        $pendingActionCount = count($pendingActions);

        $signals = [
            'readiness_state' => $readinessState,
            'blocker_count' => $blockerCount,
            'pending_action_count' => $pendingActionCount,
            'days_since_creation' => $tenant->created_at ? (int) $tenant->created_at->diffInDays(now()) : null,
            'days_since_last_sign_off' => null,
        ];

        // Safely retrieve the last readiness sign-off to provide additional context
        $lastSignOff = TenantReadinessSignOff::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->first();

        if ($lastSignOff && $lastSignOff->created_at) {
            $signals['days_since_last_sign_off'] = (int) $lastSignOff->created_at->diffInDays(now());
        }

        if ($readinessState === 'blocked' || $blockerCount > 0) {
            $urgencyBand = 'critical';
            
            if ($readinessState === 'blocked') {
                $reasons[] = 'Tenant is in a blocked readiness state.';
            }
            if ($blockerCount > 0) {
                $reasons[] = "Tenant has {$blockerCount} critical compliance or setup blocker(s).";
            }
            
            // Highlight stagnation for blocked tenants
            if ($signals['days_since_creation'] !== null && $signals['days_since_creation'] > 30) {
                $reasons[] = "Tenant has remained blocked for over 30 days since creation.";
            }
        } elseif ($readinessState === 'ready_for_pilot' || $pendingActionCount > 0) {
            $urgencyBand = 'caution';
            
            if ($readinessState === 'ready_for_pilot') {
                $reasons[] = 'Tenant is currently ready for pilot and requires monitoring.';
            }
            if ($pendingActionCount > 0) {
                $reasons[] = "Tenant has {$pendingActionCount} pending action(s) to address.";
            }
            
            if ($signals['days_since_last_sign_off'] !== null && $signals['days_since_last_sign_off'] > 14) {
                $reasons[] = "More than 14 days have passed since the last readiness sign-off.";
            }
        } else {
            $urgencyBand = 'low';
            $reasons[] = 'Tenant is fully ready for operations with no blockers or pending actions.';
        }

        $score = 10; // Default low
        if ($urgencyBand === 'critical') {
            $score = min(100, 80 + ($blockerCount * 5));
        } elseif ($urgencyBand === 'caution') {
            $score = min(79, 40 + ($pendingActionCount * 5));
            if ($signals['days_since_last_sign_off'] !== null && $signals['days_since_last_sign_off'] > 14) {
                $score += 10;
            }
        }

        return [
            'urgency_band' => $urgencyBand,
            'score' => $score,
            'reasons' => $reasons,
            'signals' => $signals,
        ];
    }
}
