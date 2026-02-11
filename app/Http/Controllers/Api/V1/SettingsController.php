<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings Controller - Admin Only
 * 
 * Manages global application settings stored in the database.
 */
class SettingsController extends Controller
{
    /**
     * Get all settings grouped by category.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Only admins can access settings
        if (auth()->user()->role !== 'admin') {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Get all settings from database
        $dbSettings = Setting::getAll();

        // Merge with defaults for any missing settings
        $settings = [
            'uptime' => [
                'enabled' => $dbSettings['uptime.enabled'] ?? config('uptime.enabled', true),
                'interval' => $dbSettings['uptime.interval'] ?? config('uptime.check_interval', 5),
                'concurrency' => $dbSettings['uptime.concurrency'] ?? config('uptime.concurrency', 10),
                'timeout' => $dbSettings['uptime.timeout'] ?? config('uptime.timeout', 15),
            ],
            'backup' => [
                'default_frequency' => $dbSettings['backup.default_frequency'] ?? config('backup.default_frequency', 'daily'),
                'retention_days' => $dbSettings['backup.retention_days'] ?? config('backup.retention_days', 30),
            ],
        ];

        return $this->successResponse($settings);
    }

    /**
     * Update settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        // Only admins can update settings
        if (auth()->user()->role !== 'admin') {
            return $this->errorResponse('Unauthorized', 403);
        }

        $validated = $request->validate([
            'uptime.enabled' => 'sometimes|boolean',
            'uptime.interval' => 'sometimes|integer|in:1,2,3,5,10,15,30',
            'uptime.concurrency' => 'sometimes|integer|min:1|max:50',
            'uptime.timeout' => 'sometimes|integer|min:5|max:60',
            'backup.default_frequency' => 'sometimes|string|in:daily,weekly,monthly',
            'backup.retention_days' => 'sometimes|integer|min:1|max:365',
        ]);

        // Save uptime settings
        if (isset($validated['uptime'])) {
            foreach ($validated['uptime'] as $key => $value) {
                Setting::set("uptime.{$key}", $value, 'uptime');
            }
        }

        // Save backup settings
        if (isset($validated['backup'])) {
            foreach ($validated['backup'] as $key => $value) {
                Setting::set("backup.{$key}", $value, 'backup');
            }
        }

        // Clear cache to ensure fresh values
        Setting::clearCache();

        return $this->successResponse(null, 'Settings updated successfully');
    }

    /**
     * Get current uptime monitoring status (quick check for scheduler).
     *
     * @return JsonResponse
     */
    public function uptimeStatus(): JsonResponse
    {
        return $this->successResponse([
            'enabled' => Setting::getOrConfig('uptime.enabled', 'uptime.enabled'),
            'interval' => Setting::getOrConfig('uptime.interval', 'uptime.check_interval'),
            'concurrency' => Setting::getOrConfig('uptime.concurrency', 'uptime.concurrency'),
        ]);
    }
}
