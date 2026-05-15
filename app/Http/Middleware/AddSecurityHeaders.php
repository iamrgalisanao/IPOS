<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($this->shouldApplyHsts($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    protected function shouldApplyHsts(Request $request): bool
    {
        $environment = (string) config('app.env', 'production');

        if (in_array($environment, ['local', 'testing'], true)) {
            return false;
        }

        return $request->isSecure()
            || $request->server->get('HTTPS') === 'on'
            || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https';
    }
}