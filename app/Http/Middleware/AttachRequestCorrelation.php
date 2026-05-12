<?php

namespace App\Http\Middleware;

use App\Services\Observability\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestCorrelation
{
    public function __construct(
        protected RequestCorrelation $requestCorrelation
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->requestCorrelation->clear();

        $correlationId = $this->requestCorrelation->resolveFromRequest($request);

        $this->requestCorrelation->set($correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::shareContext($this->requestCorrelation->context($request));

        $response = $next($request);

        Log::shareContext($this->requestCorrelation->context($request));
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}