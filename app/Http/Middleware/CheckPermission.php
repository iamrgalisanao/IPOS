<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(403, 'Unauthorized.');
        }

        // Platform support users bypass permission checks (already verified by EnsurePlatformAdmin)
        if ($user->isPlatformSupport()) {
            return $next($request);
        }

        $permissions = explode('|', $permission);
        $hasAccess = false;
        
        foreach ($permissions as $p) {
            if ($user->hasPermission(trim($p))) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            abort(403, 'Unauthorized. Permissions required: ' . implode(' or ', $permissions));
        }

        return $next($request);
    }
}
