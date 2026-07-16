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
    public const OUTCOME_NO_CORRECTION = 'no_correction_required';
    public const OUTCOME_POSITIVE_CORRECTION = 'positive_correction';
    public const OUTCOME_NEGATIVE_CORRECTION = 'negative_correction';

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
        'expected_quantity_at_count_start',
        'count_start_movement_sequence',
        'count_start_stock_snapshot_at',
        'counted_quantity',
        'variance_quantity',
        'raw_count_start_difference',
        'count_snapshot_uuid',
        'count_snapshot_schema_version',
        'physically_counted_at',
        'count_recorded_at',
        'counted_inventory_revision',
        'counted_movement_sequence',
        'expected_quantity_at_count_time',
        'physical_count_variance_quantity',
        'movement_during_count_delta',
        'movement_during_count_summary',
        'movement_during_count_sequence_from',
        'movement_during_count_sequence_to',
        'movement_during_count_count',
        'movement_after_count_delta',
        'movement_after_count_summary',
        'movement_after_count_sequence_from',
        'movement_after_count_sequence_to',
        'movement_after_count_count',
        'expected_quantity_at_posting',
        'posting_inventory_revision_before',
        'posting_inventory_revision_after',
        'counted_quantity_projected_to_posting',
        'posted_variance_quantity',
        'posting_outcome',
        'projection_policy_version',
        'reason_schema_version',
        'posting_movement_id',
        'posting_movement_sequence',
        'posting_evidence_quality',
        'posting_snapshot',
        'reason_code',
        'remarks',
        'counted_by',
        'counted_at',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'expected_quantity_at_count_start' => 'decimal:4',
        'count_start_movement_sequence' => 'integer',
        'count_start_stock_snapshot_at' => 'datetime',
        'counted_quantity' => 'decimal:4',
        'variance_quantity' => 'decimal:4',
        'raw_count_start_difference' => 'decimal:4',
        'count_snapshot_schema_version' => 'integer',
        'physically_counted_at' => 'datetime',
        'count_recorded_at' => 'datetime',
        'counted_inventory_revision' => 'integer',
        'counted_movement_sequence' => 'integer',
        'expected_quantity_at_count_time' => 'decimal:4',
        'physical_count_variance_quantity' => 'decimal:4',
        'movement_during_count_delta' => 'decimal:4',
        'movement_during_count_summary' => 'array',
        'movement_during_count_sequence_from' => 'integer',
        'movement_during_count_sequence_to' => 'integer',
        'movement_during_count_count' => 'integer',
        'movement_after_count_delta' => 'decimal:4',
        'movement_after_count_summary' => 'array',
        'movement_after_count_sequence_from' => 'integer',
        'movement_after_count_sequence_to' => 'integer',
        'movement_after_count_count' => 'integer',
        'expected_quantity_at_posting' => 'decimal:4',
        'posting_inventory_revision_before' => 'integer',
        'posting_inventory_revision_after' => 'integer',
        'counted_quantity_projected_to_posting' => 'decimal:4',
        'posted_variance_quantity' => 'decimal:4',
        'projection_policy_version' => 'integer',
        'reason_schema_version' => 'integer',
        'posting_movement_sequence' => 'integer',
        'posting_snapshot' => 'array',
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
