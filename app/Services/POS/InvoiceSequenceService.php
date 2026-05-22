<?php

namespace App\Services\POS;

use App\Models\SalesMachineProfile;
use Illuminate\Support\Facades\DB;

class InvoiceSequenceService
{
    /**
     * Atomically allocate and return the next gap-free sequential invoice number for a machine profile.
     *
     * Uses pessimistic locking to prevent concurrency gaps or double-increments.
     */
    public function generateNextInvoiceNumber(SalesMachineProfile $profile): string
    {
        return DB::transaction(function () use ($profile) {
            // Lock the SalesMachineProfile row for update
            $lockedProfile = SalesMachineProfile::where('id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Increment sequence
            $lockedProfile->last_invoice_sequence += 1;
            $lockedProfile->save();

            // Format sequential invoice number (e.g. INV-MAIN-POS-01-0000000001)
            $prefix = 'INV';
            $code = $lockedProfile->profile_code ?: 'POS';
            $paddedSequence = str_pad($lockedProfile->last_invoice_sequence, 10, '0', STR_PAD_LEFT);

            return "{$prefix}-{$code}-{$paddedSequence}";
        });
    }
}
