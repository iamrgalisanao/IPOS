<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosAdjustmentRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'idempotency_key',
        'action_type',
        'sale_id',
        'cashier_id',
        'request_hash',
        'response_snapshot',
        'status',
    ];

    protected $casts = [
        'response_snapshot' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
