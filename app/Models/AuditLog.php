<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUuids;

    protected static function booted()
    {
        static::addGlobalScope('tenant', function ($builder) {
            $tenantContext = app(\App\Services\TenantContext::class);
            if ($tenantContext->hasTenant()) {
                $builder->where($builder->getQuery()->from . '.tenant_id', $tenantContext->getTenantId());
            }
        });

        static::creating(function ($model) {
            $tenantContext = app(\App\Services\TenantContext::class);
            if ($tenantContext->hasTenant()) {
                $model->tenant_id = $model->tenant_id ?? $tenantContext->getTenantId();
            }
        });

        static::updating(function ($model) {
            throw new \RuntimeException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Audit logs are append-only and cannot be deleted.');
        });
    }

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'actor_user_id',
        'actor_type',
        'action',
        'auditable_type',
        'auditable_id',
        'before_values',
        'after_values',
        'metadata',
        'reason',
        'remarks',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'before_values' => 'json',
        'after_values' => 'json',
        'metadata' => 'json',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

}
