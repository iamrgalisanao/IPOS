<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLine extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;
    
    public const REASON_DAMAGED = 'DAMAGED';
    public const REASON_EXPIRED = 'EXPIRED';
    public const REASON_THEFT_LOSS = 'THEFT_LOSS';
    public const REASON_MISCOUNT = 'MISCOUNT';
    public const REASON_RETURN_ADJUSTMENT = 'RETURN_ADJUSTMENT';
    public const REASON_SYSTEM_ERROR = 'SYSTEM_ERROR';
    public const REASON_FOUND_STOCK = 'FOUND_STOCK';
    public const REASON_OTHER = 'OTHER';

    public static function getReasonCodes(): array
    {
        return [
            self::REASON_DAMAGED => 'Damaged Goods',
            self::REASON_EXPIRED => 'Expired Product',
            self::REASON_THEFT_LOSS => 'Theft / Loss',
            self::REASON_MISCOUNT => 'Miscount',
            self::REASON_RETURN_ADJUSTMENT => 'Return Adjustment',
            self::REASON_SYSTEM_ERROR => 'System Error',
            self::REASON_FOUND_STOCK => 'Found Stock',
            self::REASON_OTHER => 'Other (Requires Remarks)',
        ];
    }

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'stocktake_session_id',
        'product_id',
        'expected_quantity',
        'counted_quantity',
        'variance_quantity',
        'reason_code',
        'remarks',
        'counted_by',
        'counted_at',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
        'variance_quantity' => 'decimal:4',
        'counted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class, 'stocktake_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function counter(): BelongsTo
    {
        return $this->countedByUser();
    }
}
