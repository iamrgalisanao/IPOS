<?php

namespace App\Services\POS;

use App\Models\RegisterZRead;
use App\Models\SalesMachineProfile;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ZReadGenerationService
{
    /**
     * Atomically generate a Register Z-Read ledger entry and update the machine profile's GCT.
     *
     * @param SalesMachineProfile $profile The machine profile for which the Z-read is generated
     * @param User $user The user (cashier/manager) executing the Z-read
     * @param string $zReadDate The business date window (e.g., '2026-05-19')
     * @param string|null $shiftId Optional register shift ID to filter sales
     * @return RegisterZRead
     * @throws \RuntimeException
     */
    public function generateZRead(SalesMachineProfile $profile, User $user, string $zReadDate, ?string $shiftId = null): RegisterZRead
    {
        return DB::transaction(function () use ($profile, $user, $zReadDate, $shiftId) {
            // 1. Lock the sales_machine_profiles row using lockForUpdate()
            $lockedProfile = SalesMachineProfile::where('id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Fetch eligible completed sales that have not been included in a Z-read yet
            $query = Sale::where('sales_machine_profile_id', $lockedProfile->id)
                ->where('is_training_mode', false)
                ->whereNull('register_z_read_id');

            if ($shiftId) {
                // If a shift is specified, filter sales that have payments in this shift
                $query->whereHas('payments', function ($q) use ($shiftId) {
                    $q->where('shift_id', $shiftId);
                });
            } else {
                // Otherwise, filter by z_read_date matching reporting_basis_at
                $query->whereDate('reporting_basis_at', $zReadDate);
            }

            $sales = $query->orderBy('principal_invoice_number', 'asc')->get();

            if ($sales->isEmpty()) {
                throw new \RuntimeException('No eligible completed sales found for the specified period to generate a Z-read.');
            }

            // 3. Perform cumulative calculations for BIR baseline totals
            $grossSales = 0.00;
            $vatableSales = 0.00;
            $vatExemptSales = 0.00;
            $zeroRatedSales = 0.00;
            $nonVatSales = 0.00;
            $vatAmount = 0.00;
            $statutoryDiscount = 0.00;
            $commercialDiscount = 0.00;
            $otherAdjustment = 0.00;
            $voidSales = 0.00;
            $refundSales = 0.00;

            $firstInvoiceNumber = null;
            $lastInvoiceNumber = null;

            foreach ($sales as $sale) {
                // Capture first and last invoice numbers in sequence
                if ($sale->principal_invoice_number) {
                    if ($firstInvoiceNumber === null) {
                        $firstInvoiceNumber = $sale->principal_invoice_number;
                    }
                    $lastInvoiceNumber = $sale->principal_invoice_number;
                }

                if ($sale->status === 'voided') {
                    $voidSales += (float) $sale->total;
                } else {
                    $grossSales += (float) $sale->gross_sales_amount;
                    $vatableSales += (float) $sale->vatable_sales_amount;
                    $vatExemptSales += (float) $sale->vat_exempt_sales_amount;
                    $zeroRatedSales += (float) $sale->zero_rated_sales_amount;
                    $nonVatSales += (float) $sale->non_vat_sales_amount;
                    $vatAmount += (float) $sale->vat_amount;
                    $statutoryDiscount += (float) $sale->statutory_discount_total;
                    $commercialDiscount += (float) $sale->commercial_discount_total;
                    $otherAdjustment += (float) $sale->other_adjustment_total;
                }

                // Cumulative refunds linked to this sale
                $refundSum = SaleRefund::where('sale_id', $sale->id)->sum('refund_total');
                $refundSales += (float) $refundSum;
            }

            // 4. Calculate previous and current grand cumulative totals
            $grandCumulativeTotalBefore = (float) $lockedProfile->grand_cumulative_total;
            // GCT increases atomically by the gross sales amount of this Z-read
            $grandCumulativeTotalAfter = $grandCumulativeTotalBefore + $grossSales;

            // 5. Update GCT and increment z_read_counter atomically on the SalesMachineProfile
            $lockedProfile->grand_cumulative_total = $grandCumulativeTotalAfter;
            $lockedProfile->z_read_counter += 1;
            $lockedProfile->save();

            // 6. Construct tamper-evident cryptographic hash
            $payload = json_encode([
                'sales_machine_profile_id' => $lockedProfile->id,
                'z_read_sequence' => $lockedProfile->z_read_counter,
                'z_read_date' => $zReadDate,
                'grand_cumulative_total_before' => $grandCumulativeTotalBefore,
                'grand_cumulative_total_after' => $grandCumulativeTotalAfter,
                'gross_sales_amount' => $grossSales,
                'transaction_count' => $sales->count(),
            ]);
            $tamperEvidentHash = hash_hmac('sha256', $payload, 'ipos_secure_compliance_key');

            // 7. Insert the immutable RegisterZRead ledger entry
            $zRead = RegisterZRead::create([
                'tenant_id' => $lockedProfile->tenant_id,
                'branch_id' => $lockedProfile->branch_id,
                'sales_machine_profile_id' => $lockedProfile->id,
                'user_id' => $user->id,
                'z_read_sequence' => $lockedProfile->z_read_counter,
                'z_read_date' => $zReadDate,
                'grand_cumulative_total_before' => $grandCumulativeTotalBefore,
                'grand_cumulative_total_after' => $grandCumulativeTotalAfter,
                'gross_sales_amount' => $grossSales,
                'vatable_sales_amount' => $vatableSales,
                'vat_exempt_sales_amount' => $vatExemptSales,
                'zero_rated_sales_amount' => $zeroRatedSales,
                'non_vat_sales_amount' => $nonVatSales,
                'vat_amount' => $vatAmount,
                'statutory_discount_total' => $statutoryDiscount,
                'commercial_discount_total' => $commercialDiscount,
                'other_adjustment_total' => $otherAdjustment,
                'void_sales_amount' => $voidSales,
                'refund_sales_amount' => $refundSales,
                'transaction_count' => $sales->count(),
                'reset_counter' => $lockedProfile->reset_counter,
                'first_invoice_number' => $firstInvoiceNumber,
                'last_invoice_number' => $lastInvoiceNumber,
                'reporting_basis_at' => now(),
                'is_training_mode' => false,
                'raw_journal_payload' => json_encode($sales->toArray()),
                'tamper_evident_hash' => $tamperEvidentHash,
            ]);

            // 8. Shift/Period locking: Associate the included sales with the RegisterZRead
            foreach ($sales as $sale) {
                // Directly set the foreign key to lock the sale record
                $sale->register_z_read_id = $zRead->id;
                $sale->save();
            }

            return $zRead;
        });
    }
}
