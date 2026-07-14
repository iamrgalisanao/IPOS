<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillSplitAllocation extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const METHOD_SEAT = 'seat';
    public const METHOD_ITEM_QUANTITY = 'item_quantity';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'split_request_uuid',
        'request_fingerprint',
        'parent_ticket_id',
        'child_ticket_id',
        'child_ticket_item_id',
        'source_ticket_item_id',
        'allocation_method',
        'allocation_sequence',
        'allocated_quantity',
        'allocated_amount_centavos',
        'promotion_discount_centavos',
        'rounding_adjustment_centavos',
        'promotion_allocation_snapshot',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'allocation_sequence' => 'integer',
        'allocated_quantity' => 'decimal:3',
        'allocated_amount_centavos' => 'integer',
        'promotion_discount_centavos' => 'integer',
        'rounding_adjustment_centavos' => 'integer',
        'promotion_allocation_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function parentTicket(): BelongsTo
    {
        return $this->belongsTo(DiningTicket::class, 'parent_ticket_id');
    }

    public function childTicket(): BelongsTo
    {
        return $this->belongsTo(DiningTicket::class, 'child_ticket_id');
    }

    public function childTicketItem(): BelongsTo
    {
        return $this->belongsTo(DiningTicketItem::class, 'child_ticket_item_id');
    }

    public function sourceTicketItem(): BelongsTo
    {
        return $this->belongsTo(DiningTicketItem::class, 'source_ticket_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
