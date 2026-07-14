<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiningTicketItem extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SENT_TO_KITCHEN = 'sent_to_kitchen';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_SERVED = 'served';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_MOVED = 'moved';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'dining_ticket_id',
        'product_id',
        'seat_number',
        'quantity',
        'unit_price_centavos',
        'line_total_centavos',
        'status',
        'source_item_id',
        'course_no',
        'fire_group',
        'hold_until',
        'preparation_station_id',
        'promotion_allocation_snapshot',
    ];

    protected $casts = [
        'seat_number' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price_centavos' => 'integer',
        'line_total_centavos' => 'integer',
        'course_no' => 'integer',
        'hold_until' => 'datetime',
        'promotion_allocation_snapshot' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(DiningTicket::class, 'dining_ticket_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_item_id');
    }

    public function derivedItems(): HasMany
    {
        return $this->hasMany(self::class, 'source_item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
