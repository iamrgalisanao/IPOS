<?php

namespace App\Http\Middleware;

use App\Policies\TimecardAccessPolicy;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceClockedIn
{
    public function __construct(
        protected TimecardAccessPolicy $policy,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenantContext->getTenantId();
        $branchId = $this->branchContext->getBranchId();
        $user = $request->user();

        if (config('app.enforce_timecards', true) && $tenantId && $branchId && $user) {
            $this->policy->requireClockedIn($user, $tenantId, $branchId);
        }

        return $next($request);
    }
}
