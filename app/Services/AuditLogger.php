<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Record an audit event.
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?string $reason = null,
        ?string $remarks = null,
        array $metadata = [],
        ?User $actor = null
    ): AuditLog {
        // Enforce fail-loudly if context is missing, unless it's an identity flow
        if (!$this->tenantContext->hasTenant()) {
            if ($auditable && method_exists($auditable, 'isIdentityModel') && $auditable->isIdentityModel()) {
                // Allow logging identity models without context
            } else {
                throw new \RuntimeException('Tenant context is required for audit logging.');
            }
        }

        $logData = [
            'tenant_id' => $this->tenantContext->getTenantId(),
            'branch_id' => $this->branchContext->getBranchId(),
            'action' => $action,
            'before_values' => $beforeValues,
            'after_values' => $afterValues,
            'reason' => $reason,
            'remarks' => $remarks,
            'metadata' => $metadata ?: null,
            'actor_user_id' => $actor?->id ?? \Illuminate\Support\Facades\Auth::id(),
            'actor_type' => $actor?->actor_type ?? \Illuminate\Support\Facades\Auth::user()?->actor_type ?? 'system',
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(), // Explicitly set because $timestamps = false in model
        ];

        if ($auditable) {
            $logData['auditable_type'] = get_class($auditable);
            $logData['auditable_id'] = $auditable->getKey();
        }

        // Note: actor_user_id is now handled automatically.
        
        return AuditLog::create($logData);
    }
}
