<?php

namespace App\Services\Observability;

use App\Services\BranchContext;
use App\Services\SupportContext;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestCorrelation
{
    protected ?string $correlationId = null;

    public function normalize(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 191) {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9\-_.:]+$/', $value)) {
            return null;
        }

        return $value;
    }

    public function generate(): string
    {
        return (string) Str::uuid();
    }

    public function resolveCurrentOrGenerate(): string
    {
        return $this->normalize($this->current()) ?? $this->generate();
    }

    public function resolveFromRequest(Request $request): string
    {
        $incoming = $request->headers->get('X-Correlation-ID')
            ?: $request->headers->get('X-Request-ID');

        return $this->normalize($incoming) ?? $this->generate();
    }

    public function current(): ?string
    {
        return $this->correlationId;
    }

    public function restoreForQueue(?string $correlationId): string
    {
        $resolved = $this->normalize($correlationId) ?? $this->resolveCurrentOrGenerate();

        $this->set($resolved);

        return $resolved;
    }

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function clear(): void
    {
        $this->correlationId = null;
    }

    public function context(Request $request): array
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $branchContext = app(BranchContext::class);

        return [
            'correlation_id' => $this->current() ?? $request->attributes->get('correlation_id'),
            'tenant_id' => $tenantContext->getTenantId(),
            'branch_id' => $branchContext->getBranchId(),
            'actor_id' => $user?->getAuthIdentifier(),
            'actor_type' => $user?->actor_type,
            'route_name' => $request->route()?->getName(),
        ];
    }

    public function operationalContext(Request $request, ?string $supportSessionId = null): array
    {
        $context = $this->context($request);
        $resolvedSupportSessionId = $supportSessionId ?? app(SupportContext::class)->getSessionId();

        if ($resolvedSupportSessionId) {
            $context['support_session_id'] = $resolvedSupportSessionId;
        }

        return $context;
    }
}