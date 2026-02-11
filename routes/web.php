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

// =====================================================
// MOCK WORDPRESS API (DEV ONLY - Remove in production!)
// =====================================================
if (app()->environment('local')) {
    Route::prefix('wp-json/lsm/v1')->group(function () {
        Route::get('/health', function (\Illuminate\Http\Request $request) {
            $key = $request->get('key');
            
            // Test scenarios:
            // test-secret-123  = Healthy site
            // test-outdated    = Outdated plugins
            // test-error500    = 500 error
            // test-error503    = 503 error
            
            if ($key === 'test-error500') {
                abort(500, 'Internal Server Error');
            }
            
            if ($key === 'test-error503') {
                abort(503, 'Service Unavailable');
            }
            
            if ($key === 'test-outdated') {
                return response()->json([
                    'status' => 'warning',
                    'wordpress' => ['version' => '5.9.0'],
                    'php' => ['version' => '7.4.33'],
                    'plugins' => [
                        'total_count' => 15,
                        'active_count' => 12,
                        'outdated_count' => 8,
                        'outdated_plugins' => [
                            ['name' => 'Contact Form 7', 'current_version' => '5.5', 'new_version' => '5.8'],
                            ['name' => 'Yoast SEO', 'current_version' => '19.0', 'new_version' => '21.5'],
                        ],
                    ],
                    'theme' => ['name' => 'Astra', 'version' => '3.9.0', 'update_available' => true],
                    'ssl' => ['enabled' => true],
                    'updates' => ['core_update_available' => true, 'core_new_version' => '6.4.2'],
                    'security' => ['debug_mode' => true, 'file_editing_disabled' => false],
                    'disk' => ['free_space' => '2.5 GB', 'total_space' => '10 GB'],
                    'landeseiten_stack' => [
                        'stack_complete' => false,
                        'issues' => ['Hello Elementor not active', 'Child Theme not active'],
                    ],
                ]);
            }
            
            if ($key === 'test-secret-123') {
                return response()->json([
                    'status' => 'ok',
                    'wordpress' => ['version' => '6.4.2'],
                    'php' => ['version' => '8.2.14'],
                    'plugins' => [
                        'total_count' => 12,
                        'active_count' => 10,
                        'outdated_count' => 0,
                        'outdated_plugins' => [],
                    ],
                    'theme' => ['name' => 'flavor starter theme', 'version' => '2.1.0', 'update_available' => false],
                    'ssl' => ['enabled' => true],
                    'updates' => ['core_update_available' => false],
                    'security' => ['debug_mode' => false, 'file_editing_disabled' => true],
                    'disk' => ['free_space' => '15.2 GB', 'total_space' => '20 GB'],
                    'landeseiten_stack' => [
                        'stack_complete' => true,
                        'issues' => [],
                    ],
                ]);
            }
            
            return response()->json(['error' => 'Invalid key'], 401);
        });
        
        Route::get('/ping', function () {
            return response()->json([
                'status' => 'ok',
                'plugin' => 'lsm-health-monitor',
                'version' => '1.0.0',
                'licensed' => true,
            ]);
        });
    });
}
