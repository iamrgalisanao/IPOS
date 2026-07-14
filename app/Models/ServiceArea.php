<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceArea extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const DEFAULT_LAYOUT_METADATA = [
        'version' => 1,
        'canvas_width' => 1600,
        'canvas_height' => 900,
        'grid_size' => 10,
        'background' => [
            'type' => 'none',
            'image_url' => null,
        ],
    ];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'normalized_name',
        'layout_metadata',
        'layout_revision',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'layout_metadata' => 'array',
        'layout_revision' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function activeDiningTickets(): HasMany
    {
        return $this->hasMany(DiningTable::class)
            ->whereHas('ticketMappings', function ($query) {
                $query->whereNull('detached_at')
                    ->where('role', DiningTicketTable::ROLE_PRIMARY)
                    ->whereHas('ticket', fn ($ticketQuery) => $ticketQuery->whereIn('status', DiningTicket::ACTIVE_STATUSES));
            });
    }
}
