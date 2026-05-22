<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyOnboardingEvent extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'company_onboarding_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'event_data',
        'created_at',
    ];

    protected $casts = [
        'event_data' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relationship: Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Scope: Filter by event type
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope: Get events for a tenant
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId)->orderBy('created_at', 'asc');
    }

    /**
     * Scope: Get recent events
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
