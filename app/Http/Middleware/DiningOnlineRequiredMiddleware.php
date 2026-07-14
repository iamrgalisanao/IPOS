<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiningOnlineRequiredMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            strtolower((string) $request->header('X-IPOS-Online-Only')) === 'dining'
            && strtolower((string) $request->header('X-IPOS-Connectivity')) !== 'online'
        ) {
            return response()->json([
                'code' => 'DINING_ONLINE_REQUIRED',
                'message' => 'Dining table actions require an online connection. Reconnect before changing tickets or tables.',
            ], 409);
        }

        return $next($request);
    }
}
