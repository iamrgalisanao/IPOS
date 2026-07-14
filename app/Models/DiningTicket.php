<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTicket extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLING = 'settling';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_VOIDED = 'voided';

    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_SETTLING,
    ];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'ticket_number',
        'status',
        'guest_count',
        'subtotal_centavos',
        'discount_centavos',
        'service_charge_centavos',
        'tax_centavos',
        'grand_total_centavos',
        'opened_by',
        'opened_at',
        'closed_at',
        'parent_ticket_id',
        'source_sale_id',
        'terminal_id',
        'ticket_revision',
        'reservation_id',
        'checkout_request_uuid',
        'client_request_uuid',
        'client_request_fingerprint',
        'pricing_engine_version',
        'tax_engine_version',
        'discount_engine_version',
        'notes',
    ];

    protected $casts = [
        'guest_count' => 'integer',
        'subtotal_centavos' => 'integer',
        'discount_centavos' => 'integer',
        'service_charge_centavos' => 'integer',
        'tax_centavos' => 'integer',
        'grand_total_centavos' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'ticket_revision' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class, 'terminal_id');
    }

    public function sourceSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'source_sale_id');
    }

    public function parentTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_ticket_id');
    }

    public function childTickets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_ticket_id');
    }

    public function tableMappings(): HasMany
    {
        return $this->hasMany(DiningTicketTable::class);
    }

    public function activeTableMappings(): HasMany
    {
        return $this->tableMappings()
            ->whereNull('detached_at')
            ->where('role', DiningTicketTable::ROLE_PRIMARY)
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', self::ACTIVE_STATUSES));
    }

    public function primaryTableMapping(): HasOne
    {
        return $this->hasOne(DiningTicketTable::class)
            ->whereNull('detached_at')
            ->where('role', DiningTicketTable::ROLE_PRIMARY);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DiningTicketItem::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
