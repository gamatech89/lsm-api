<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

class LsmService
{
    protected const API_NAMESPACE = '/wp-json/lsm/v1';
    protected const DEFAULT_TIMEOUT = 30;
    protected const UPDATE_TIMEOUT = 120;

    protected Project $project;
    protected ?string $apiKey;
    protected string $baseUrl;

    public function __construct(Project $project)
    {
        $this->project = $project;
        $this->apiKey = $project->health_check_secret;
        $this->baseUrl = rtrim($project->url, '/') . self::API_NAMESPACE;
    }

    public static function for(Project $project): self
    {
        return new self($project);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->project->url);
    }

    /**
     * Test connectivity to the LSM plugin.
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout(10)
                ->get(rtrim($this->project->url, '/') . self::API_NAMESPACE . '/info');

            if ($response->successful()) {
                $data = $response->json();
                $success = $data['success'] ?? false; // LSM /info returns { success: true, data: { ... } }
                
                if ($success) {
                    $pluginData = $data['data'] ?? [];
                    return [
                        'connected' => true,
                        'plugin' => $pluginData['plugin'] ?? 'Landeseiten Maintenance',
                        'version' => $pluginData['version'] ?? '1.0.0',
                        'status' => $pluginData['status'] ?? 'active',
                    ];
                }
            }
            
            return [
                 'connected' => false,
                 'error' => "HTTP {$response->status()}",
                 'body' => $response->body()
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    public function getHealth(): ?array
    {
        return $this->get('/health');
    }

    /**
     * Get recovery status.
     * Since LSM doesn't have a dedicated recovery status endpoint, 
     * we derive it from health data which now includes maintenance_mode.
     */
    public function getRecoveryStatus(): ?array
    {
        $health = $this->get('/health');
        
        if (!$health) {
            return null;
        }

        // Determine maintenance mode
        // 1. Prefer direct value from health response (if plugin updated)
        if (isset($health['maintenance_mode'])) {
            $isMaintenance = (bool) $health['maintenance_mode'];
        } else {
            // 2. Fallback: Probe the site homepage for 503 status
            // An anonymous request should receive 503 if maintenance is active
            try {
                $response = Http::timeout(5)->get($this->project->url);
                $isMaintenance = $response->status() === 503;
            } catch (\Exception $e) {
                // If connection fails entirely, we can't be sure, but standard maintenance is 503.
                // If it's a DNS error or timeout, assume false or handle error.
                // For now, assume false to avoid false positives.
                $isMaintenance = false;
            }
        }

        // Map health data to expected recovery status format for frontend
        return [
            'maintenance_mode' => $isMaintenance,
            // Add identifying info if needed
            'active_plugins' => $health['active_plugins'] ?? ($health['plugins']['active'] ?? 0),
            'current_theme' => $health['current_theme']['name'] ?? ($health['themes']['current']['name'] ?? 'Unknown'),
        ];
    }

    public function getAvailableUpdates(): ?array
    {
        return $this->get('/updates');
    }

    /**
     * Get all installed plugins from WordPress.
     */
    public function getPlugins(): ?array
    {
        return $this->get('/plugins');
    }

    /**
     * Get all installed themes from WordPress.
     */
    public function getThemes(): ?array
    {
        return $this->get('/themes');
    }


    public function enableMaintenance(): ?array
    {
        return $this->post('/maintenance/enable');
    }

    public function disableMaintenance(): ?array
    {
        return $this->post('/maintenance/disable');
    }

    public function clearCache(): ?array
    {
        return $this->post('/cache/clear');
    }
    
    public function optimizeDatabase(): ?array
    {
        return $this->post('/database/optimize');
    }

    /**
     * Cleanup database - removes revisions, transients, drafts, spam, etc.
     */
    public function cleanupDatabase(array $options = []): ?array
    {
        return $this->post('/database/cleanup', $options);
    }

    /**
     * Get database statistics for cleanup preview.
     */
    public function getDatabaseStats(): ?array
    {
        return $this->get('/database/stats');
    }

    public function flushRewriteRules(): ?array
    {
        return $this->post('/rewrite/flush');
    }
    
    public function updateCore(): ?array
    {
        return $this->post('/updates/core', [], self::UPDATE_TIMEOUT);
    }
    
    public function updatePlugins(): ?array 
    {
        // Updates all plugins
         return $this->post('/updates/plugins', [], self::UPDATE_TIMEOUT);
    }

    /**
     * Update a single plugin.
     */
    public function updatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/update', ['slug' => $slug], self::UPDATE_TIMEOUT);
    }

    /**
     * Activate a single plugin.
     */
    public function activatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/activate', ['slug' => $slug]);
    }

    /**
     * Deactivate a single plugin.
     * Note: The WordPress plugin protects itself from being deactivated remotely.
     */
    public function deactivatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/deactivate', ['slug' => $slug]);
    }

    /**
     * Delete a plugin.
     * Note: Plugin must be deactivated first. The LSM plugin protects itself.
     */
    public function deletePlugin(string $slug): ?array
    {
        return $this->post('/plugins/delete', ['slug' => $slug]);
    }

    public function disableAllPlugins(): ?array
    {
        return $this->post('/recovery/disable-plugins');
    }

    public function restorePlugins(): ?array
    {
        return $this->post('/recovery/restore-plugins');
    }

    /**
     * Activate a specific theme.
     */
    public function activateTheme(string $slug): ?array
    {
        return $this->post('/themes/activate', ['slug' => $slug]);
    }

    /**
     * Update a single theme.
     */
    public function updateTheme(string $slug): ?array
    {
        return $this->post('/themes/update', ['slug' => $slug], self::UPDATE_TIMEOUT);
    }

    /**
     * Switch to default theme.
     */
    public function switchTheme(): ?array
    {
        // The emergency recovery switch-theme functionality exists under recovery
        return $this->post('/recovery/switch-theme');
    }

    public function emergencyRecovery(): ?array
    {
        return $this->post('/recovery/emergency');
    }

    public function generateLoginToken(string $role = 'administrator'): ?array
    {
        $user = auth()->user();

        return $this->post('/sso/token', [
            'role' => $role,
            'bind_ip' => request()->ip(),
            'dashboard_user' => $user ? $user->email : 'system',
            'email' => $user ? $user->email : null,
            'display_name' => $user ? $user->name : null,
        ]);
    }

    // =========================================================================
    // BACKUP METHODS
    // =========================================================================

    /**
     * Create a backup on the WordPress site.
     */
    public function createBackup(array $options = []): ?array
    {
        return $this->post('/backup/create', [
            'includes_database' => $options['includes_database'] ?? true,
            'includes_files' => $options['includes_files'] ?? true,
            'includes_uploads' => $options['includes_uploads'] ?? true,
        ]);
    }

    /**
     * Get list of backups on the WordPress site.
     */
    public function listBackups(): ?array
    {
        return $this->get('/backup/list');
    }

    /**
     * Restore a backup on the WordPress site.
     */
    public function restoreBackup(string $backupFile): ?array
    {
        return $this->post('/backup/restore', [
            'backup_file' => $backupFile,
        ]);
    }

    /**
     * Delete a backup on the WordPress site.
     */
    public function deleteBackup(string $backupFile): ?array
    {
        return $this->post('/backup/delete', [
            'backup_file' => $backupFile,
        ]);
    }

    // =========================================================================
    // PHP ERROR LOGGING METHODS
    // =========================================================================

    /**
     * Get PHP error log from WordPress site.
     * 
     * @param array $filters Optional filters (type, unresolved, search)
     */
    public function getPhpErrors(array $filters = []): ?array
    {
        return $this->get('/errors/list', $filters);
    }

    /**
     * Get PHP error statistics from WordPress site.
     */
    public function getPhpErrorStats(): ?array
    {
        return $this->get('/errors/stats');
    }

    /**
     * Resolve a PHP error on WordPress site.
     */
    public function resolvePhpError(string $hash): ?array
    {
        return $this->post('/errors/resolve', ['hash' => $hash]);
    }

    /**
     * Clear PHP error log on WordPress site.
     */
    public function clearPhpErrors(): ?array
    {
        return $this->post('/errors/clear');
    }

    // =========================================================================
    // Activity Log Methods
    // =========================================================================

    /**
     * Get activity log from WordPress site.
     * 
     * @param int $limit Number of entries to return
     * @param string|null $action Filter by action type
     */
    public function getActivity(int $limit = 50, ?string $action = null): ?array
    {
        $params = ['limit' => $limit];
        if ($action) {
            $params['action'] = $action;
        }
        return $this->get('/activity/list', $params);
    }

    /**
     * Get activity statistics from WordPress site.
     */
    public function getActivityStats(): ?array
    {
        return $this->get('/activity/stats');
    }

    /**
     * Clear activity log on WordPress site.
     */
    public function clearActivityLog(): ?array
    {
        return $this->post('/activity/clear');
    }

    // =========================================================================
    // SITE INFO & SECURITY SETTINGS
    // =========================================================================

    /**
     * Get comprehensive site information including users, content, comments.
     */
    public function getSiteInfo(): ?array
    {
        return $this->get('/site/info');
    }

    /**
     * Get list of WordPress users.
     */
    public function getUsers(): ?array
    {
        return $this->get('/users/list');
    }

    /**
     * Create a WordPress user for an LSM Platform team member.
     */
    public function createWpUser(\App\Models\User $user, string $role = 'administrator'): ?array
    {
        return $this->post('/users/create', [
            'email' => $user->email,
            'display_name' => $user->name,
            'role' => $role,
            'platform_user_id' => $user->id,
        ]);
    }

    /**
     * Delete a WordPress user by email.
     */
    public function deleteWpUser(string $email): ?array
    {
        return $this->post('/users/delete', [
            'email' => $email,
        ]);
    }

    /**
     * Sync WordPress users with a list of platform users.
     *
     * @param \App\Models\User[] $users
     */
    public function syncWpUsers(array $users): ?array
    {
        $userData = array_map(fn($user) => [
            'email' => $user->email,
            'display_name' => $user->name,
            'role' => 'administrator',
            'platform_user_id' => $user->id,
        ], $users);

        return $this->post('/users/sync', [
            'users' => $userData,
        ]);
    }

    /**
     * Get current security settings.
     */
    public function getSecuritySettings(): ?array
    {
        return $this->get('/security/settings');
    }

    /**
     * Update security settings.
     */
    public function updateSecuritySettings(array $settings): ?array
    {
        return $this->post('/security/settings', $settings);
    }

    /**
     * Get security headers status for the site.
     */
    public function getSecurityHeaders(): ?array
    {
        return $this->get('/security/headers');
    }

    /**
     * Get security header configuration snippets for Apache, Nginx, and PHP.
     */
    public function getSecurityHeaderSnippets(): ?array
    {
        return $this->get('/security/headers/snippets');
    }

    // =========================================================================
    // SECURITY SCANNING
    // =========================================================================

    /**
     * Run a security scan on the WordPress site.
     *
     * @param array|null $modules Specific modules to run (null = all)
     * @param string     $scanType Scan tier: 'quick', 'standard', or 'full'
     */
    public function runSecurityScan(?array $modules = null, string $scanType = 'full'): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $url = $this->baseUrl . '/security/scan';
            $data = ['scan_type' => $scanType];
            if ($modules) {
                $data['modules'] = implode(',', $modules);
            }

            $timeouts = ['quick' => 60, 'standard' => 180, 'full' => 600];
            $timeout = $timeouts[$scanType] ?? 180;

            $response = Http::timeout($timeout)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->asJson()
                ->post($url, $data);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM Security Scan Error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Run a quick security scan on the WordPress site.
     */
    public function runQuickScan(): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::timeout(90)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . '/security/scan/quick');

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM Quick Scan Error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get current scan progress from the WordPress site.
     * Lightweight endpoint that reads a transient — safe to poll frequently.
     */
    public function getScanProgress(): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . '/security/scan/progress');

            $json = $response->json();
            return $json ?? null;
        } catch (\Exception $e) {
            // Don't log polling errors — they're noise
            return null;
        }
    }

    // =========================================================================
    // MEDIA LIBRARY
    // =========================================================================

    /**
     * Scan for unused media attachments on the WordPress site.
     * Note: Uses a longer timeout since scanning large media libraries can be slow.
     */
    public function getUnusedMedia(): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::timeout(120)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . '/media/unused');

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM API Error (media/unused): {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Delete media attachments on the WordPress site.
     *
     * @param array $ids Array of attachment IDs to delete.
     */
    public function deleteMedia(array $ids): ?array
    {
        return $this->post('/media/delete', ['ids' => $ids]);
    }

    /**
     * Legacy alias for backwards compatibility.
     * @deprecated Use getPhpErrors() instead
     */
    public function getErrorLog(): ?array
    {
        return $this->getPhpErrors();
    }

    /**
     * Legacy alias for backwards compatibility.
     * @deprecated Use clearPhpErrors() instead
     */
    public function clearErrorLog(): ?array
    {
        return $this->clearPhpErrors();
    }


    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function get(string $endpoint, array $params = []): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::timeout(self::DEFAULT_TIMEOUT)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . $endpoint, $params);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM API Error ({$endpoint}): {$e->getMessage()}");
            return null;
        }
    }

    protected function post(string $endpoint, array $data = [], ?int $timeout = null): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            // Authenticate via header — keeps the secret out of URLs and access logs.
            $response = Http::timeout($timeout ?? self::DEFAULT_TIMEOUT)
                ->withHeaders(['X-LSM-Key' => $this->apiKey])
                ->withOptions(['allow_redirects' => true])
                ->asJson() // Ensure JSON content type
                ->post($this->baseUrl . $endpoint, $data);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error("LSM API Error ({$endpoint}): {$e->getMessage()}");
            return null;
        }
    }

    protected function handleResponse(Response $response): ?array
    {
        if (!$response->successful()) {
            Log::warning("LSM API Error: {$response->status()} - {$response->body()}");
            return null;
        }

        $json = $response->json();
        
        // LSM standard response is { success: true, data: ... }
        if (isset($json['success']) && $json['success'] && isset($json['data'])) {
            return $json['data'];
        }
        
        // Some endpoints might return data directly or just success boolean
        return $json;
    }
}
