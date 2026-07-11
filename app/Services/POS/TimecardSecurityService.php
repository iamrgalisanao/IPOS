<?php

namespace App\Services\POS;

use Illuminate\Support\Facades\Cache;

class TimecardSecurityService
{
    /**
     * Assert that the terminal/client is not locked out.
     */
    public function assertNotLockedOut(
        string $tenantId,
        string $branchId,
        ?string $terminalId,
        ?string $deviceId,
        ?string $ip
    ): void {
        $rateKey = $this->getRateKey($ip);

        if (Cache::get($rateKey . '_blocked')) {
            throw new \App\Exceptions\TimecardLockoutException();
        }
    }

    /**
     * Record a failed PIN attempt and lock if threshold is breached.
     */
    public function recordFailedPinAttempt(
        string $tenantId,
        string $branchId,
        ?string $terminalId,
        ?string $deviceId,
        ?string $ip
    ): void {
        $rateKey = $this->getRateKey($ip);
        $failures = (int) Cache::get($rateKey, 0) + 1;
        Cache::put($rateKey, $failures, 900); // Keep counter active for 15 mins

        if ($failures >= 10) {
            // Lockout for 15 minutes (900 seconds)
            Cache::put($rateKey . '_blocked', true, 900);
        } elseif ($failures >= 5) {
            // Lockout for 1 minute (60 seconds)
            Cache::put($rateKey . '_blocked', true, 60);
        }
    }

    /**
     * Clear failed attempts on successful authentication.
     */
    public function clearFailuresAfterSuccess(
        string $tenantId,
        string $branchId,
        ?string $terminalId,
        ?string $deviceId,
        ?string $ip
    ): void {
        $rateKey = $this->getRateKey($ip);
        Cache::forget($rateKey);
        Cache::forget($rateKey . '_blocked');
    }

    /**
     * Helper to generate dynamic rate key based on IP.
     */
    protected function getRateKey(?string $ip): string
    {
        $ip = $ip ?: 'unknown';
        return "timecard_pin_attempts_" . md5($ip);
    }
}
