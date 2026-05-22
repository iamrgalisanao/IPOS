<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Late Sync Threshold
    |--------------------------------------------------------------------------
    |
    | Offline imports whose submitted_at timestamp is older than this many
    | hours are flagged as "late sync" for admin review. Late imports are
    | NOT rejected — they remain in pending status with an informational note.
    |
    */
    'late_sync_threshold_hours' => env('OFFLINE_LATE_SYNC_THRESHOLD_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Server Recalculation Tolerance
    |--------------------------------------------------------------------------
    |
    | When comparing client-submitted totals to server-computed totals, this
    | tolerance is used to account for minor floating-point differences.
    |
    */
    'recalculation_tolerance' => env('OFFLINE_RECALCULATION_TOLERANCE', 0.01),
];
