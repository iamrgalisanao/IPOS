<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineTerminalJournal extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    // Lifecycle states
    const STATUS_PROVISIONAL = 'provisional';
    const STATUS_RECONCILED  = 'reconciled';
    const STATUS_VOIDED      = 'voided';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'journal_date',
        'status',
        'provisional_gross_total',
        'provisional_item_count',
        'reconciliation_notes',
        'reconciled_at',
    ];

    protected $casts = [
        'journal_date'            => 'date',
        'provisional_gross_total' => 'decimal:4',
        'provisional_item_count'  => 'integer',
        'reconciled_at'           => 'datetime',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }
}
