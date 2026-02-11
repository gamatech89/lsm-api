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
    | Alerting (Future)
    |--------------------------------------------------------------------------
    */

    // Send alerts when sites go down (future feature)
    'alerts_enabled' => env('UPTIME_ALERTS_ENABLED', false),

    // Minutes a site must be down before alerting
    'alert_threshold_minutes' => env('UPTIME_ALERT_THRESHOLD', 10),
];
