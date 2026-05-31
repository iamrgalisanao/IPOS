<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriorPeriodAdjustment extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'sale_id',
        'offline_sales_import_id',
        'original_transaction_at',
        'original_business_date',
        'original_register_z_read_id',
        'adjusted_into_settlement_period_id',
        'reporting_basis_at',
        'reconciled_at',
        'gross_amount',
        'net_amount',
        'vat_amount',
        'adjustment_reason',
        'status',
    ];

    protected $casts = [
        'original_transaction_at' => 'datetime',
        'original_business_date'  => 'date',
        'reporting_basis_at'      => 'datetime',
        'reconciled_at'           => 'datetime',
        'gross_amount'            => 'decimal:4',
        'net_amount'              => 'decimal:4',
        'vat_amount'              => 'decimal:4',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function offlineSalesImport(): BelongsTo
    {
        return $this->belongsTo(OfflineSalesImport::class);
    }

    public function originalRegisterZRead(): BelongsTo
    {
        return $this->belongsTo(RegisterZRead::class, 'original_register_z_read_id');
    }

    public function adjustedIntoSettlementPeriod(): BelongsTo
    {
        return $this->belongsTo(SettlementPeriod::class, 'adjusted_into_settlement_period_id');
    }
}
