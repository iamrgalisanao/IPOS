<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiningTicketTable extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const ROLE_PRIMARY = 'primary';
    public const ROLE_JOINED = 'joined';
    public const ROLE_MOVED_FROM = 'moved_from';
    public const ROLE_MOVED_TO = 'moved_to';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'dining_ticket_id',
        'dining_table_id',
        'role',
        'attached_at',
        'detached_at',
    ];

    protected $casts = [
        'attached_at' => 'datetime',
        'detached_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(DiningTicket::class, 'dining_ticket_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
