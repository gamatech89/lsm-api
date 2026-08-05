<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Enabled
    |--------------------------------------------------------------------------
    |
    | This value determines if the MCP server should be enabled. By default,
    | it is enabled, but you can disable it by setting this value to false.
    |
    */
    'enabled' => env('MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure the route settings for the MCP server.
    |
    */
    'route' => [
        'path' => '/mcp',

        // No 'web' — this is a stateless API endpoint. Adding the web group
        // would pull in session state and CSRF verification.
        'middleware' => ['auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Metadata
    |--------------------------------------------------------------------------
    |
    | This information is presented to MCP clients when they connect.
    |
    */
    'server' => [
        'name' => 'LSM Platform',
        'version' => '1.0.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure how MCP requests and errors are logged.
    |
    */
    'logging' => [
        'enabled' => true,
        'channel' => env('LOG_CHANNEL', 'stack'),
    ],
];
