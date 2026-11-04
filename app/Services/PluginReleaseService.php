<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads WP plugin releases from GitHub, the single source of truth for what
 * the fleet auto-updates to and what staff download from the platform.
 */
class PluginReleaseService
{
    private const GITHUB_REPO = 'gamatech89/lsm-wp';
    private const CACHE_KEY = 'lsm_plugin_latest_release';
    private const CACHE_TTL = 600; // 10 minutes
    private const USER_AGENT = 'LSM-API-Server/1.0';
    private const ZIP_ASSET_NAME = 'landeseiten-maintenance.zip';
    private const ZIP_MAGIC_BYTES = "PK\x03\x04";

    /**
     * Latest release info, cached to avoid hitting GitHub rate limits.
     * Failures are not cached, so the next call retries.
     */
    public function latest(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => self::USER_AGENT,
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
    }

    /**
     * Contents of the plugin zip attached to the latest release, or null when
     * GitHub can't provide it. The magic-byte check keeps an HTML error page
     * from reaching someone's WordPress installer as a "plugin".
     */
    public function latestZip(): ?string
    {
        try {
            $asset = collect($this->latest()['assets'] ?? [])
                ->firstWhere('name', self::ZIP_ASSET_NAME);

            if (!$asset) {
                Log::warning('Latest plugin release has no zip asset', ['asset' => self::ZIP_ASSET_NAME]);

                return null;
            }

            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(30)
                ->get($asset['browser_download_url']);
        } catch (ConnectionException $e) {
            report($e);

            return null;
        }

        $zip = $response->body();

        if (!$response->successful() || !str_starts_with($zip, self::ZIP_MAGIC_BYTES)) {
            Log::warning('Plugin zip download from GitHub failed', ['status' => $response->status()]);

            return null;
        }

        return $zip;
    }
}
