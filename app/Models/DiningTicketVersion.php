<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiningTicketVersion extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const UPDATED_AT = null;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'dining_ticket_id',
        'version',
        'operation',
        'actor_user_id',
        'terminal_id',
        'source',
        'reason',
        'before_snapshot',
        'after_snapshot',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Dining ticket versions are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Dining ticket versions are append-only and cannot be deleted.');
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(DiningTicket::class, 'dining_ticket_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class, 'terminal_id');
    }
}
