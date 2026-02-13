<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

/**
 * Remote Management Bridge Service
 * 
 * Handles all communication with the Landeseiten Maintenance WordPress plugin.
 * Also supports legacy RMB plugin for backwards compatibility.
 */
class RmbService
{
    // New LSM plugin namespace (primary)
    protected const LSM_API_NAMESPACE = '/wp-json/lsm/v1';
    // Legacy RMB plugin namespace (fallback)
    protected const RMB_API_NAMESPACE = '/wp-json/rmb/v1';
    protected const DEFAULT_TIMEOUT = 30;

    protected Project $project;
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $apiNamespace;
    protected bool $namespaceDetected = false;

    public function __construct(Project $project)
    {
        $this->project = $project;
        $this->apiKey = $project->health_check_secret;
        // Default to RMB namespace since that's the installed plugin
        $this->apiNamespace = self::RMB_API_NAMESPACE;
        $this->baseUrl = rtrim($project->url, '/') . $this->apiNamespace;
    }

    /**
     * Create instance from project.
     */
    public static function for(Project $project): self
    {
        return new self($project);
    }

    /**
     * Check if RMB is configured for this project.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->project->url);
    }

    /**
     * Ensure correct API namespace is detected and used.
     * Only makes the detection request once per instance.
     */
    protected function ensureNamespaceDetected(): void
    {
        if ($this->namespaceDetected) {
            return;
        }

        // Try RMB first since that's our installed plugin
        $rmbResult = $this->tryNamespace(self::RMB_API_NAMESPACE);
        if ($rmbResult['connected']) {
            $this->apiNamespace = self::RMB_API_NAMESPACE;
            $this->baseUrl = rtrim($this->project->url, '/') . $this->apiNamespace;
            $this->namespaceDetected = true;
            return;
        }

        // Fallback to LSM namespace
        $lsmResult = $this->tryNamespace(self::LSM_API_NAMESPACE);
        if ($lsmResult['connected']) {
            $this->apiNamespace = self::LSM_API_NAMESPACE;
            $this->baseUrl = rtrim($this->project->url, '/') . $this->apiNamespace;
        }

        $this->namespaceDetected = true;
    }

    // =========================================================================
    // HEALTH ENDPOINTS
    // =========================================================================

    /**
     * Get full health report.
     */
    public function getHealth(): ?array
    {
        return $this->get('/health');
    }

    /**
     * Get quick health status.
     */
    public function getQuickHealth(): ?array
    {
        return $this->get('/health/quick');
    }

    /**
     * Get plugin health details.
     */
    public function getPluginHealth(): ?array
    {
        return $this->get('/health/plugins');
    }

    // =========================================================================
    // AUTH ENDPOINTS (SSO)
    // =========================================================================

    /**
     * Generate a one-time login token.
     */
    public function generateLoginToken(int $ttl = 300, bool $bindIp = true, ?string $ipAddress = null): ?array
    {
        return $this->post('/auth/generate-token', [
            'ttl' => $ttl,
            'bind_ip' => $bindIp,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Revoke all login tokens.
     */
    public function revokeLoginTokens(): ?array
    {
        return $this->post('/auth/revoke');
    }

    /**
     * Build the SSO login URL.
     * Uses lsm_token for new plugin, rmb_token for legacy.
     */
    public function buildLoginUrl(string $token): string
    {
        // Use the appropriate token parameter based on detected plugin
        $tokenParam = $this->apiNamespace === self::LSM_API_NAMESPACE ? 'lsm_token' : 'rmb_token';
        return rtrim($this->project->url, '/') . '/?' . $tokenParam . '=' . $token;
    }

    // =========================================================================
    // RECOVERY ENDPOINTS (Killswitch)
    // =========================================================================

    /**
     * Get recovery status.
     */
    public function getRecoveryStatus(): ?array
    {
        return $this->get('/recovery/status');
    }

    /**
     * Disable all plugins except RMB.
     */
    public function disableAllPlugins(): ?array
    {
        return $this->post('/recovery/disable-plugins');
    }

    /**
     * Restore previously disabled plugins.
     */
    public function restorePlugins(): ?array
    {
        return $this->post('/recovery/restore-plugins');
    }

    /**
     * Enable maintenance mode.
     */
    public function enableMaintenance(?string $message = null): ?array
    {
        return $this->post('/recovery/maintenance', [
            'enabled' => true,
            'message' => $message,
        ]);
    }

    /**
     * Disable maintenance mode.
     */
    public function disableMaintenance(): ?array
    {
        return $this->post('/recovery/maintenance', [
            'enabled' => false,
        ]);
    }

    /**
     * Switch to default theme.
     */
    public function switchToDefaultTheme(): ?array
    {
        return $this->post('/recovery/switch-theme');
    }

    /**
     * Execute full emergency recovery.
     */
    public function emergencyRecovery(): ?array
    {
        return $this->post('/recovery/emergency');
    }

    /**
     * Install MU plugin for emergency access.
     */
    public function installMuPlugin(): ?array
    {
        return $this->post('/recovery/install-mu-plugin');
    }

    // =========================================================================
    // ACTION ENDPOINTS
    // =========================================================================

    /**
     * Clear all caches.
     */
    public function clearCache(): ?array
    {
        return $this->post('/actions/clear-cache');
    }

    /**
     * Get available updates.
     */
    public function getAvailableUpdates(): ?array
    {
        return $this->get('/actions/updates');
    }

    /**
     * Update a specific plugin.
     */
    public function updatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/update', ['slug' => $slug]);
    }

    /**
     * Update all plugins.
     */
    public function updateAllPlugins(): ?array
    {
        return $this->post('/updates/plugins');
    }

    /**
     * Update WordPress core.
     */
    public function updateCore(): ?array
    {
        return $this->post('/actions/update-core');
    }

    /**
     * Update a theme.
     */
    public function updateTheme(string $slug): ?array
    {
        return $this->post('/actions/update-theme', ['slug' => $slug]);
    }

    /**
     * Activate a plugin.
     */
    public function activatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/activate', ['slug' => $slug]);
    }

    /**
     * Deactivate a plugin.
     */
    public function deactivatePlugin(string $slug): ?array
    {
        return $this->post('/plugins/deactivate', ['slug' => $slug]);
    }

    /**
     * Delete a plugin.
     */
    public function deletePlugin(string $slug): ?array
    {
        return $this->post('/plugins/delete', ['slug' => $slug]);
    }

    /**
     * Optimize database.
     */
    public function optimizeDatabase(): ?array
    {
        return $this->post('/actions/optimize-db');
    }

    /**
     * Flush rewrite rules.
     */
    public function flushRewriteRules(): ?array
    {
        return $this->post('/actions/flush-rewrite');
    }

    // =========================================================================
    // HTTP METHODS
    // =========================================================================

    /**
     * Make a GET request.
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning("RMB not configured for project: {$this->project->name}");
            return null;
        }

        $this->ensureNamespaceDetected();

        try {
            $params['key'] = $this->apiKey;
            
            $response = Http::timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['allow_redirects' => true])
                ->get($this->baseUrl . $endpoint, $params);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            $this->logError($endpoint, $e);
            return null;
        }
    }

    /**
     * Make a POST request.
     */
    protected function post(string $endpoint, array $data = []): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning("RMB not configured for project: {$this->project->name}");
            return null;
        }

        $this->ensureNamespaceDetected();

        try {
            $response = Http::timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['allow_redirects' => true])
                ->asJson()
                ->post($this->baseUrl . $endpoint . '?key=' . $this->apiKey, $data);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            $this->logError($endpoint, $e);
            return null;
        }
    }

    /**
     * Handle the API response.
     */
    protected function handleResponse(Response $response): ?array
    {
        if (!$response->successful()) {
            Log::warning("RMB API error for {$this->project->name}: HTTP {$response->status()}");
            return [
                'success' => false,
                'error' => "HTTP {$response->status()}",
                'body' => $response->body(),
            ];
        }

        $data = $response->json();
        
        if (!is_array($data)) {
            return null;
        }

        // Return the nested 'data' if present
        if (isset($data['success']) && $data['success'] === true && isset($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /**
     * Log an error.
     */
    protected function logError(string $endpoint, \Exception $e): void
    {
        Log::error("RMB API error for {$this->project->name} [{$endpoint}]: {$e->getMessage()}");
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Test connectivity to the LSM or RMB plugin.
     * Tries RMB namespace first (our installed plugin), falls back to LSM.
     */
    public function testConnection(): array
    {
        // Try RMB (our installed plugin) first
        $rmbResult = $this->tryNamespace(self::RMB_API_NAMESPACE);
        if ($rmbResult['connected']) {
            $this->apiNamespace = self::RMB_API_NAMESPACE;
            $this->baseUrl = rtrim($this->project->url, '/') . $this->apiNamespace;
            $this->namespaceDetected = true;
            return array_merge($rmbResult, ['plugin_version' => $rmbResult['version'] ?? '1.0.0']);
        }

        // Fall back to LSM (new plugin)
        $lsmResult = $this->tryNamespace(self::LSM_API_NAMESPACE);
        if ($lsmResult['connected']) {
            $this->apiNamespace = self::LSM_API_NAMESPACE;
            $this->baseUrl = rtrim($this->project->url, '/') . $this->apiNamespace;
            $this->namespaceDetected = true;
            return array_merge($lsmResult, ['plugin_version' => $lsmResult['version'] ?? '1.0.0']);
        }

        $this->namespaceDetected = true;
        return $rmbResult; // Return RMB error if neither works
    }

    /**
     * Try connecting to a specific API namespace.
     */
    protected function tryNamespace(string $namespace): array
    {
        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => true])
                ->get(rtrim($this->project->url, '/') . $namespace . '/info');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'connected' => true,
                    'plugin' => $data['data']['plugin'] ?? 'Unknown',
                    'version' => $data['data']['version'] ?? 'Unknown',
                    'status' => $data['data']['status'] ?? 'Unknown',
                ];
            }

            return [
                'connected' => false,
                'error' => "HTTP {$response->status()}",
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh project health data and save to database.
     */
    public function refreshHealth(): bool
    {
        $health = $this->getHealth();

        if (!$health) {
            return false;
        }

        $updateData = [
            'last_health_check_at' => now(),
            'last_health_details' => $health,
        ];

        // Extract WordPress version
        if (isset($health['wordpress']['version'])) {
            $updateData['wp_version'] = $health['wordpress']['version'];
        }

        // Extract PHP version
        if (isset($health['php']['version'])) {
            $updateData['php_version'] = $health['php']['version'];
        }

        // Extract outdated plugins count
        if (isset($health['plugins']['outdated_count'])) {
            $updateData['outdated_plugins_count'] = $health['plugins']['outdated_count'];
        }

        // Extract SSL info
        if (isset($health['ssl']['expires_at'])) {
            $updateData['ssl_expires_at'] = $health['ssl']['expires_at'];
            $updateData['ssl_status'] = $health['ssl']['status'] ?? 'valid';
        }

        // Update health status based on overall status
        if (isset($health['status'])) {
            $healthStatus = match($health['status']) {
                'healthy' => 'online',
                'warning' => 'online',
                'critical' => 'down_error',
                default => 'online',
            };
            $updateData['health_status'] = $healthStatus;
        }

        $this->project->update($updateData);

        return true;
    }
}
