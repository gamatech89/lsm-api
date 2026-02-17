<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Public endpoint that proxies the latest WP plugin release info from GitHub.
 * Used by the WP plugin's auto-updater as a reliable alternative to direct
 * GitHub API calls (which may be blocked/rate-limited on shared hosting).
 */
class PluginReleaseController extends Controller
{
    private const GITHUB_REPO = 'gamatech89/lsm-wp';
    private const CACHE_KEY = 'lsm_plugin_latest_release';
    private const CACHE_TTL = 600; // 10 minutes

    /**
     * GET /api/v1/plugin/latest-release
     *
     * Returns the latest release info for the WP plugin.
     * Cached for 10 minutes to avoid hitting GitHub rate limits.
     */
    public function latestRelease(): JsonResponse
    {
        $release = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'LSM-API-Server/1.0',
            ])->timeout(10)->get(
                'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest'
            );

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (empty($data) || !isset($data['tag_name'])) {
                return null;
            }

            // Return only the fields the updater needs
            return [
                'tag_name' => $data['tag_name'],
                'name' => $data['name'] ?? '',
                'body' => $data['body'] ?? '',
                'published_at' => $data['published_at'] ?? '',
                'zipball_url' => $data['zipball_url'] ?? '',
                'assets' => array_map(function ($asset) {
                    return [
                        'name' => $asset['name'],
                        'browser_download_url' => $asset['browser_download_url'],
                    ];
                }, $data['assets'] ?? []),
            ];
        });

        if (!$release) {
            return response()->json(['error' => 'No release found'], 404);
        }

        return response()->json($release);
    }
}
