<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningTable extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, SoftDeletes;

    public const STATE_AVAILABLE = 'available';
    public const STATE_RESERVED = 'reserved';
    public const STATE_CLEANING = 'cleaning';

    public const SHAPE_RECTANGLE = 'rectangle';
    public const SHAPE_SQUARE = 'square';
    public const SHAPE_CIRCLE = 'circle';
    public const SHAPE_OVAL = 'oval';

    public const LABEL_CENTER = 'center';
    public const LABEL_TOP = 'top';
    public const LABEL_BOTTOM = 'bottom';

    public const DEFAULT_POSITION_METADATA = [
        'x' => 0,
        'y' => 0,
        'width' => 120,
        'height' => 80,
        'rotation' => 0,
        'shape' => self::SHAPE_RECTANGLE,
        'label_position' => self::LABEL_CENTER,
        'z_index' => 1,
    ];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'service_area_id',
        'table_number',
        'capacity',
        'operational_state',
        'position_metadata',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'position_metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
