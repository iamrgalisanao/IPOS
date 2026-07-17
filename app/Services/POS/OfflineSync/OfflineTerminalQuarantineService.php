<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\OfflineTerminalEpochQuarantine;
use App\Models\SalesMachineProfile;
use Illuminate\Database\QueryException;

class OfflineTerminalQuarantineService
{
    public function isEpochQuarantined(SalesMachineProfile $profile, ?string $terminalBindingEpoch): bool
    {
        if (!$terminalBindingEpoch) {
            return false;
        }

        return OfflineTerminalEpochQuarantine::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where('terminal_binding_epoch', $terminalBindingEpoch)
            ->where('quarantine_status', OfflineTerminalEpochQuarantine::STATUS_ACTIVE)
            ->exists();
    }

    public function quarantineEpoch(
        SalesMachineProfile $profile,
        OfflineSalesImport $sourceImport,
        string $reasonCode
    ): OfflineTerminalEpochQuarantine {
        try {
            return OfflineTerminalEpochQuarantine::firstOrCreate(
                [
                    'tenant_id' => $profile->tenant_id,
                    'sales_machine_profile_id' => $profile->id,
                    'terminal_binding_epoch' => (string) $sourceImport->terminal_binding_epoch,
                    'quarantine_status' => OfflineTerminalEpochQuarantine::STATUS_ACTIVE,
                ],
                [
                    'branch_id' => $profile->branch_id,
                    'quarantine_reason' => $reasonCode,
                    'source_offline_import_id' => $sourceImport->id,
                    'quarantined_at' => now(),
                ]
            );
        } catch (QueryException) {
            return OfflineTerminalEpochQuarantine::query()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->where('terminal_binding_epoch', $sourceImport->terminal_binding_epoch)
                ->where('quarantine_status', OfflineTerminalEpochQuarantine::STATUS_ACTIVE)
                ->firstOrFail();
        }
    }
}
