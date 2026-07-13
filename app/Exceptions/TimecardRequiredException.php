<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimecardRequiredException extends Exception
{
    public function render(Request $request)
    {
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'code' => 'TIMECARD_REQUIRED',
                'message' => 'You must be clocked in before performing this action.',
                'next_action' => 'CLOCK_IN'
            ], 403);
        }

        return redirect()->route('pos.terminal.shift')->with('error', 'You must be clocked in before performing this action.');
    }
}
