<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationDiscrepancyLog extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'reported_gct',
        'calculated_gct',
        'discrepancy_amount',
        'context_type',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'reported_gct'       => 'decimal:4',
        'calculated_gct'     => 'decimal:4',
        'discrepancy_amount' => 'decimal:4',
        'resolved_at'        => 'datetime',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
