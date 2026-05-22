<?php

namespace App\Services\POS\OfflineReadiness;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\SalesMachineProfile;

class OfflineSettingsValidator
{
    /**
     * Resolve the effective offline sales setting and return a structured validation result.
     *
     * @param Tenant $tenant
     * @param Branch $branch
     * @param SalesMachineProfile $profile
     * @return array{allowed: bool, reason: string, message: string}
     */
    public function validate(Tenant $tenant, Branch $branch, SalesMachineProfile $profile): array
    {
        // 1. Check Tenant-level enablement
        if (!$tenant->offline_sales_enabled) {
            return [
                'allowed' => false,
                'reason' => 'tenant_disabled',
                'message' => 'Controlled Offline Sales is disabled at the tenant level.',
            ];
        }

        // 2. Check Branch-level enablement
        if (!$branch->offline_sales_enabled) {
            return [
                'allowed' => false,
                'reason' => 'branch_disabled',
                'message' => 'Controlled Offline Sales is disabled at the branch level.',
            ];
        }

        // 3. Check Terminal-level enablement (with null fallback inheriting enabled state)
        if ($profile->offline_sales_enabled === false) {
            return [
                'allowed' => false,
                'reason' => 'terminal_disabled',
                'message' => 'Controlled Offline Sales is disabled for this terminal.',
            ];
        }

        // 4. Check if terminal prefix is assigned
        if (empty($profile->offline_sequence_prefix)) {
            return [
                'allowed' => false,
                'reason' => 'missing_prefix',
                'message' => 'No sequence prefix is assigned to this terminal.',
            ];
        }

        // 5. Check sequence status (active, suspended, depleted)
        $status = $profile->offline_sequence_status ?? 'active';

        if ($status === 'suspended') {
            return [
                'allowed' => false,
                'reason' => 'terminal_suspended',
                'message' => 'This terminal sequence has been suspended.',
            ];
        }

        if ($status === 'depleted') {
            return [
                'allowed' => false,
                'reason' => 'terminal_depleted',
                'message' => 'This terminal sequence range is depleted.',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'allowed',
            'message' => 'Offline sales are permitted for this terminal.',
        ];
    }
}
