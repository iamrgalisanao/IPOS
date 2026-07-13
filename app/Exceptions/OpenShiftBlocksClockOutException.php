<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenShiftBlocksClockOutException extends Exception
{
    public function render(Request $request)
    {
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'code' => 'OPEN_SHIFT_BLOCKS_CLOCK_OUT',
                'message' => 'Please close your cashier shift before clocking out.',
                'next_action' => 'CLOSE_SHIFT'
            ], 409);
        }

        return redirect()->back()->with('error', 'Please close your cashier shift before clocking out.');
    }
}
