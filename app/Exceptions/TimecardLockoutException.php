<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimecardLockoutException extends Exception
{
    public function render(Request $request)
    {
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'code' => 'PIN_RATE_LIMITED',
                'message' => 'PIN verification is temporarily unavailable. Please try again later or contact a supervisor.',
                'next_action' => 'WAIT_LOCKOUT'
            ], 429);
        }

        return redirect()->back()->withErrors([
            'pin' => 'PIN verification is temporarily unavailable. Please try again later or contact a supervisor.'
        ]);
    }
}
