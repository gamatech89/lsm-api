<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Uptime Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how frequently sites are checked and the concurrency level.
    |
    */

    // How often to check sites (in minutes)
    // Options: 1, 2, 3, 5, 10, 15, 30
    'check_interval' => env('UPTIME_CHECK_INTERVAL', 5),

    // How many sites to check simultaneously (parallel requests)
    // Higher = faster but more server load
    // Recommended: 10-20 for most servers
    'concurrency' => env('UPTIME_CONCURRENCY', 10),

    // Request timeout in seconds
    'timeout' => env('UPTIME_TIMEOUT', 15),

    // Enable/disable uptime monitoring entirely
    'enabled' => env('UPTIME_MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    // Minutes between repeated "site down" notifications for the same project.
    // Prevents spam during prolonged outages — one alert fires, then silence
    // until the cooldown expires or the site recovers.
    // Set via UPTIME_NOTIFICATION_COOLDOWN in .env (default: 60 minutes).
    'notification_cooldown_minutes' => env('UPTIME_NOTIFICATION_COOLDOWN', 60),

    /*
    |--------------------------------------------------------------------------
    | History Retention
    |--------------------------------------------------------------------------
    */

    // Days of uptime_checks history to keep; older rows are pruned nightly.
    'retention_days' => env('UPTIME_RETENTION_DAYS', 90),
];
