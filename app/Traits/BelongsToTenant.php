<?php

namespace App\Traits;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantContext = app(TenantContext::class);
            
            if ($tenantContext->hasTenant()) {
                $builder->where($builder->getQuery()->from . '.tenant_id', $tenantContext->getTenantId());
            } else {
                // Identity models (like User) must be findable to establish context during authentication.
                // We allow them to be queried without context ONLY if the model explicitly allows it 
                // as an identity-carrying entity.
                if (method_exists($builder->getModel(), 'isIdentityModel') && $builder->getModel()->isIdentityModel()) {
                    return;
                }
                throw new \RuntimeException('Tenant context is required for tenant-scoped model queries: ' . get_class($builder->getModel()));
            }
        });

        static::creating(function (Model $model) {
            $tenantContext = app(TenantContext::class);

            if (!$tenantContext->hasTenant()) {
                // Allow creation of identity models (e.g. support users) without context 
                // if they are not strictly tenant-scoped (have no tenant_id set yet).
                if (method_exists($model, 'isIdentityModel') && $model->isIdentityModel() && !$model->tenant_id) {
                    return;
                }
                throw new \RuntimeException('Tenant context missing for scoped model creation: ' . get_class($model));
            }

            $contextTenantId = $tenantContext->getTenantId();

            if ($model->tenant_id && $model->tenant_id !== $contextTenantId) {
                throw new \RuntimeException('Cross-tenant assignment blocked for model: ' . get_class($model));
            }

            $model->tenant_id = $contextTenantId;
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
