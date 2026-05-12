<?php

namespace App\Http\Middleware;

use App\Models\SupportAccessSession;
use App\Services\BranchContext;
use App\Services\Observability\RequestCorrelation;
use App\Services\Support\SupportAuditLogger;
use App\Services\SupportAccessSessionService;
use App\Services\SupportContext;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class IdentifySupportAssistedContext
{
    public function __construct(
        protected SupportAccessSessionService $supportAccessSessionService,
        protected SupportAuditLogger $supportAuditLogger,
        protected RequestCorrelation $requestCorrelation,
        protected SupportContext $supportContext,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->clear();
        $this->branchContext->clear();
        $this->supportContext->clear();

        $user = $request->user();

        if (!$user) {
            abort(403, 'Authenticated support user required.');
        }

        if (!$user->isActive()) {
            abort(403, 'User account is deactivated.');
        }

        if (!$user->isPlatformSupport()) {
            abort(403, 'Platform support access required.');
        }

        try {
            $routeSession = $request->route('supportAccessSession');
            $supportAccessSession = $routeSession instanceof SupportAccessSession
                ? $routeSession
                : $this->supportAccessSessionService->assertActiveSession((string) $routeSession);

            $supportAccessSession = $this->supportAccessSessionService->assertActiveSession($supportAccessSession);
        } catch (RuntimeException $exception) {
            $supportSessionId = $routeSession instanceof SupportAccessSession ? $routeSession->id : null;

            Log::warning('support.assisted.session.validation_failed', $this->requestCorrelation->operationalContext($request, $supportSessionId));

            abort(403, $exception->getMessage());
        }

        if ($supportAccessSession->support_user_id !== $user->id) {
            abort(403, 'Support access session does not belong to the authenticated support user.');
        }

        $tenant = $supportAccessSession->tenant;

        if (!$tenant) {
            abort(403, 'Support access session tenant is invalid.');
        }

        if ($tenant->status !== 'active') {
            abort(403, 'Tenant account is ' . $tenant->status . '.');
        }

        $this->tenantContext->setTenant($tenant);

        if ($supportAccessSession->branch_id) {
            $branch = $supportAccessSession->branch;

            if (!$branch) {
                abort(403, 'Support access session branch is invalid.');
            }

            if ($branch->tenant_id !== $tenant->id) {
                abort(403, 'Support access session branch tenant mismatch.');
            }

            if ($branch->status !== 'active') {
                abort(403, 'Branch account is ' . $branch->status . '.');
            }

            $this->branchContext->setBranch($branch);
        }

        $this->supportContext->setSession($supportAccessSession);

        Log::info('support.assisted.request.accessed', $this->requestCorrelation->operationalContext($request, $supportAccessSession->id));

        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->supportAuditLogger->log(
                eventType: 'support.action.blocked',
                supportSession: $supportAccessSession,
                actor: $user,
                status: 'blocked',
                routeName: $request->route()?->getName(),
                path: $request->path(),
                method: $request->method(),
                metadata: [
                    'masking_profile' => $supportAccessSession->masking_profile,
                    'request' => $request->all(),
                    'headers' => [
                        'Authorization' => $request->header('Authorization'),
                    ],
                ]
            );

            abort(403, 'Support assisted routes are read-only.');
        }

        return $next($request);
    }
}