<?php

namespace App\Services\POS\Reconciliation;

use App\Models\OfflineSalesImport;
use App\Models\PriorPeriodAdjustment;
use App\Models\RegisterZRead;
use App\Models\Sale;
use App\Models\SettlementPeriod;
use App\Models\SalesMachineProfile;
use App\Models\ReconciliationDiscrepancyLog;
use Illuminate\Support\Carbon;

class LateSyncReconciliationService
{
    /**
     * Detect if an offline import's transaction timestamp falls into a date covered by a finalized RegisterZRead.
     */
    public function isLateSync(OfflineSalesImport $import): bool
    {
        $originalBusinessDate = $this->getOriginalBusinessDate($import);
        if (!$originalBusinessDate) {
            return false;
        }

        return RegisterZRead::where('sales_machine_profile_id', $import->sales_machine_profile_id)
            ->whereDate('z_read_date', $originalBusinessDate)
            ->exists();
    }

    /**
     * Get the original business date from the import.
     */
    public function getOriginalBusinessDate(OfflineSalesImport $import): ?string
    {
        $submittedAt = $import->submitted_at ?? $import->raw_payload['submitted_at'] ?? null;
        if (!$submittedAt) {
            return null;
        }
        return Carbon::parse($submittedAt)->toDateString();
    }

    /**
     * Get the original Z-report that covers this import's business date.
     */
    public function getOriginalZRead(OfflineSalesImport $import): ?RegisterZRead
    {
        $originalBusinessDate = $this->getOriginalBusinessDate($import);
        if (!$originalBusinessDate) {
            return null;
        }

        return RegisterZRead::where('sales_machine_profile_id', $import->sales_machine_profile_id)
            ->whereDate('z_read_date', $originalBusinessDate)
            ->first();
    }

    /**
     * Find the branch's active open (or reopened) settlement period.
     */
    public function getBranchActiveOpenSettlementPeriod(string $branchId): ?SettlementPeriod
    {
        return SettlementPeriod::where('branch_id', $branchId)
            ->whereIn('status', [SettlementPeriod::STATUS_OPEN, SettlementPeriod::STATUS_REOPENED])
            ->first();
    }

    /**
     * Create a PriorPeriodAdjustment ledger entry.
     */
    public function createPriorPeriodAdjustment(
        OfflineSalesImport $import,
        Sale $sale,
        ?string $originalZReadId,
        ?string $adjustedSettlementPeriodId,
        mixed $reportingBasisAt
    ): PriorPeriodAdjustment {
        $originalBusinessDate = $this->getOriginalBusinessDate($import);
        $originalTransactionAt = $import->submitted_at ?? $import->raw_payload['submitted_at'] ?? now();

        $gross = (float) $sale->total;
        $vat = (float) $sale->vat_amount;
        $net = $gross - $vat;

        return PriorPeriodAdjustment::create([
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'sales_machine_profile_id' => $sale->sales_machine_profile_id,
            'sale_id' => $sale->id,
            'offline_sales_import_id' => $import->id,
            'original_transaction_at' => $originalTransactionAt,
            'original_business_date' => $originalBusinessDate,
            'original_register_z_read_id' => $originalZReadId,
            'adjusted_into_settlement_period_id' => $adjustedSettlementPeriodId,
            'reporting_basis_at' => $reportingBasisAt,
            'reconciled_at' => now(),
            'gross_amount' => $gross,
            'net_amount' => $net,
            'vat_amount' => $vat,
            'adjustment_reason' => 'Late sync offline sale adjustment',
            'status' => 'posted',
        ]);
    }

    /**
     * Check if a reported GCT matches the server GCT, and log a discrepancy if it does not.
     */
    public function checkAndLogGctDiscrepancy(
        SalesMachineProfile $profile,
        float $reportedGct,
        string $contextType = 'sync'
    ): ?ReconciliationDiscrepancyLog {
        $calculatedGct = (float) $profile->grand_cumulative_total;
        $discrepancy = abs($reportedGct - $calculatedGct);

        if ($discrepancy > 0.0001) {
            return ReconciliationDiscrepancyLog::create([
                'tenant_id' => $profile->tenant_id,
                'branch_id' => $profile->branch_id,
                'sales_machine_profile_id' => $profile->id,
                'reported_gct' => $reportedGct,
                'calculated_gct' => $calculatedGct,
                'discrepancy_amount' => $discrepancy,
                'context_type' => $contextType,
            ]);
        }

        return null;
    }
}
