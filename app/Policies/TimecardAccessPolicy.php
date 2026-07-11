<?php

namespace App\Policies;

use App\Models\User;
use App\Services\POS\TimecardService;

class TimecardAccessPolicy
{
    public function __construct(protected TimecardService $timecardService) {}

    /**
     * Assert that the user is clocked in.
     * Throws an exception or returns false if not.
     */
    public function requireClockedIn(User $user, string $tenantId, string $branchId): void
    {
        $timecard = $this->timecardService->getActiveTimecard($tenantId, $branchId, $user->id);

        if (!$timecard) {
            throw new \App\Exceptions\TimecardRequiredException();
        }
    }
}
