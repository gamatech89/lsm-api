<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PluginReleaseService;
use Illuminate\Http\JsonResponse;

/**
 * Public endpoint that proxies the latest WP plugin release info from GitHub.
 * Used by the WP plugin's auto-updater as a reliable alternative to direct
 * GitHub API calls (which may be blocked/rate-limited on shared hosting).
 */
class PluginReleaseController extends Controller
{
    /**
     * GET /api/v1/plugin/latest-release
     *
     * Returns the latest release info for the WP plugin.
     * Cached for 10 minutes to avoid hitting GitHub rate limits.
     */
    public function latestRelease(PluginReleaseService $releases): JsonResponse
    {
        $release = $releases->latest();

        if (!$release) {
            return response()->json(['error' => 'No release found'], 404);
        }

        return response()->json($release);
    }
}
