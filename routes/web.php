<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Minimal - API-Only Backend)
|--------------------------------------------------------------------------
|
| Since the frontend is now a standalone React SPA (apps/web/),
| web routes are minimal. Only health checks and dev mock endpoints.
|
*/

// API Status (Root)
Route::get('/', function () {
    return response()->json([
        'status' => 'online',
        'service' => 'LSM Platform API',
        'version' => '1.0.0',
        'docs' => '/api/v1'
    ]);
});

// =====================================================
// HEALTH CHECK ENDPOINTS (for load balancers / monitoring)
// =====================================================
Route::get('/health', function () {
    try {
        // Check database connection
        \DB::connection()->getPdo();
        $dbStatus = 'ok';
    } catch (\Exception $e) {
        $dbStatus = 'error';
    }
    
    return response()->json([
        'status' => $dbStatus === 'ok' ? 'healthy' : 'unhealthy',
        'timestamp' => now()->toIso8601String(),
        'checks' => [
            'database' => $dbStatus,
            'cache' => cache()->has('health_check') || cache()->put('health_check', true, 10) ? 'ok' : 'error',
        ],
    ], $dbStatus === 'ok' ? 200 : 503);
});

Route::get('/up', function () {
    return response('OK', 200);
});

// Credential sharing is handled by the SPA via API routes
// See: routes/api.php -> /api/v1/share/*

