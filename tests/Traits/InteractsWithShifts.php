<?php

namespace Tests\Traits;

use App\Models\Branch;
use App\Models\User;
use App\Services\Shift\ShiftService;

trait InteractsWithShifts
{
    /**
     * Open a shift for a user in a branch.
     */
    protected function openShiftFor(User $user, Branch $branch, string $amount = '1000.00', ?\Carbon\CarbonInterface $openedAt = null): \App\Models\Shift
    {
        return app(ShiftService::class)->openShift($user, $branch, $amount, $user, $openedAt);
    }
}
