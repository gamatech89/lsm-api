<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWpAccountsJob;
use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Landeseiten Maintenance Controller
 * 
 * Handles communication with WordPress sites via the LSM plugin.
 */
class LsmController extends Controller
{
    /**
     * Get LSM status and capabilities for a project.
     */
    public function status(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json([
                'configured' => false,
                'message' => 'LSM plugin not configured for this project. Set the API key in project settings.',
            ]);
        }

        $connection = $lsm->testConnection();

        return response()->json([
            'configured' => true,
            'connected' => $connection['connected'],
            'plugin_version' => $connection['version'] ?? null,
            'message' => $connection['connected'] 
                ? 'LSM plugin is connected and ready'
                : 'Cannot connect to LSM plugin: ' . ($connection['error'] ?? 'Unknown error'),
        ]);
    }

    /**
     * Get full health data from WordPress site.
     */
    public function health(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $health = $lsm->getHealth();

        if (!$health) {
            return response()->json(['error' => 'Failed to fetch health data'], 500);
        }

        return response()->json($health);
    }

    /**
     * Get all installed plugins.
     */
    public function getPlugins(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $plugins = $lsm->getPlugins();

        if (!$plugins) {
            return response()->json(['error' => 'Failed to fetch plugins'], 500);
        }

        return response()->json($plugins);
    }

    /**
     * Get all installed themes.
     */
    public function getThemes(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $themes = $lsm->getThemes();

        if (!$themes) {
            return response()->json(['error' => 'Failed to fetch themes'], 500);
        }

        return response()->json($themes);
    }

    /**
     * Generate SSO login token and return login URL.
     */
    public function generateLoginToken(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->generateLoginToken(
            role: $request->input('role', 'administrator')
        );

        if (!$result || !isset($result['login_url'])) {
            return response()->json([
                'error' => 'Failed to generate login token',
                'details' => $result
            ], 500);
        }

        return response()->json([
            'success' => true,
            'login_url' => $result['login_url'],
            'expires_in' => $result['expires'] ?? 300,
        ]);
    }

    /**
     * Clear all caches on the WordPress site.
     */
    public function clearCache(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->clearCache();

        if (!$result) {
            return response()->json(['error' => 'Failed to clear cache'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Cache cleared successfully',
        ]);
    }

    /**
     * Get available updates (core, plugins, themes).
     */
    public function getUpdates(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $updates = $lsm->getAvailableUpdates();

        if (!$updates) {
            return response()->json(['error' => 'Failed to fetch updates'], 500);
        }

        return response()->json($updates);
    }

    /**
     * Update all plugins.
     */
    public function updateAllPlugins(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->updatePlugins();

        if (!$result) {
            return response()->json(['error' => 'Failed to update plugins'], 500);
        }

        return response()->json($result);
    }

    /**
     * Update a single plugin.
     */
    public function updatePlugin(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['slug' => 'required|string']);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->updatePlugin($request->input('slug'));

        if (!$result) {
            return response()->json(['error' => 'Failed to update plugin'], 500);
        }

        return response()->json($result);
    }

    /**
     * Activate a plugin.
     */
    public function activatePlugin(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['slug' => 'required|string']);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->activatePlugin($request->input('slug'));

        if (!$result) {
            return response()->json(['error' => 'Failed to activate plugin'], 500);
        }

        return response()->json($result);
    }

    /**
     * Deactivate a plugin.
     */
    public function deactivatePlugin(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['slug' => 'required|string']);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->deactivatePlugin($request->input('slug'));

        if (!$result) {
            return response()->json(['error' => 'Failed to deactivate plugin'], 500);
        }

        return response()->json($result);
    }

    /**
     * Delete a plugin.
     */
    public function deletePlugin(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['slug' => 'required|string']);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->deletePlugin($request->input('slug'));

        if (!$result || isset($result['error'])) {
            return response()->json($result ?? ['error' => 'Failed to delete plugin'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plugin deleted successfully',
            ...$result,
        ]);
    }

    /**
     * Update WordPress core.
     */
    public function updateCore(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->updateCore();

        return response()->json($result ?? ['error' => 'Failed to update core']);
    }

    /**
     * Optimize database.
     */
    public function optimizeDatabase(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->optimizeDatabase();

        return response()->json($result ?? ['error' => 'Failed to optimize database']);
    }

    /**
     * Cleanup database - removes revisions, transients, drafts, spam, etc.
     */
    public function cleanupDatabase(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $options = $request->only([
            'revisions',
            'transients', 
            'drafts',
            'spam',
            'trash',
            'orphan_meta',
        ]);

        $result = $lsm->cleanupDatabase($options);

        return response()->json($result ?? ['error' => 'Failed to cleanup database']);
    }

    /**
     * Get database statistics for cleanup preview.
     */
    public function getDatabaseStats(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getDatabaseStats();

        return response()->json($result ?? ['error' => 'Failed to get database stats']);
    }

    /**
     * Flush rewrite rules.
     */
    public function flushRewrite(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->flushRewriteRules();

        return response()->json([
            'success' => true,
            'message' => 'Rewrite rules flushed',
            'data' => $result
        ]);
    }

    /**
     * Get recovery status.
     */
    public function recoveryStatus(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getRecoveryStatus();

        return response()->json($result ?? ['error' => 'Failed to get recovery status']);
    }

    /**
     * Enable maintenance mode.
     */
    public function enableMaintenance(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->enableMaintenance();

        return response()->json($result ?? ['error' => 'Failed to enable maintenance mode']);
    }

    /**
     * Disable maintenance mode.
     */
    public function disableMaintenance(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->disableMaintenance();

        return response()->json($result ?? ['error' => 'Failed to disable maintenance mode']);
    }

    /**
     * Disable all plugins (emergency).
     */
    public function disablePlugins(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->disableAllPlugins();

        return response()->json($result ?? ['error' => 'Failed to disable plugins']);
    }

    /**
     * Restore disabled plugins.
     */
    public function restorePlugins(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->restorePlugins();

        return response()->json($result ?? ['error' => 'Failed to restore plugins']);
    }

    /**
     * Activate a specific theme.
     */
    public function activateTheme(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $slug = $request->input('slug');

        if (empty($slug)) {
            return response()->json(['error' => 'Theme slug is required'], 400);
        }

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->activateTheme($slug);

        return response()->json($result ?? ['error' => 'Failed to activate theme']);
    }

    /**
     * Switch to default theme.
     */
    public function switchTheme(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->switchTheme();

        return response()->json($result ?? ['error' => 'Failed to switch theme']);
    }

    /**
     * Execute full emergency recovery.
     */
    public function emergencyRecovery(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->emergencyRecovery();

        return response()->json($result ?? ['error' => 'Failed to execute emergency recovery']);
    }
    /**
     * Download the LSM plugin as a zip file.
     * 
     * @param Request $request
     * @param Project $project
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadPlugin(Request $request, Project $project)
    {
        // Path to the plugin directory
        $pluginPath = base_path('wordpress-plugin/landeseiten-maintenance');
        $zipFileName = 'landeseiten-maintenance.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);
        
        // Ensure temp directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        // Initialize ZipArchive
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
            
            // Create recursive directory iterator
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginPath),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                // Skip directories (they would be added automatically)
                if (!$file->isDir()) {
                    // Get real and relative path for current file
                    $filePath = $file->getRealPath();
                    $relativePath = 'landeseiten-maintenance/' . substr($filePath, strlen($pluginPath) + 1);

                    // Skip git files or DS_Store
                    if (strpos($filePath, '.git') !== false || strpos($filePath, '.DS_Store') !== false) {
                        continue;
                    }

                    // Add current file to archive
                    $zip->addFile($filePath, $relativePath);
                }
            }

            // Close and save archive
            $zip->close();
        }

        // Return the file as download
        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    // ================================================================
    // PHP ERROR MONITORING METHODS
    // ================================================================

    /**
     * Get PHP errors directly from WordPress.
     */
    public function getPhpErrors(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $filters = [];
        if ($request->has('type')) {
            $filters['type'] = $request->type;
        }
        if ($request->has('limit')) {
            $filters['limit'] = (int) $request->limit;
        }

        $result = $lsm->getPhpErrors($filters);

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch PHP errors from WordPress'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $result['errors'] ?? $result,
            'stats' => $result['stats'] ?? null,
        ]);
    }

    /**
     * Get PHP error statistics from WordPress.
     */
    public function getPhpErrorStats(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getPhpErrorStats();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch PHP error stats from WordPress'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Sync PHP errors from WordPress to local database.
     * Fetches errors from WordPress and stores them locally for faster access.
     */
    public function syncPhpErrors(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        // Fetch errors from WordPress
        $result = $lsm->getPhpErrors(['limit' => 100]);

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch PHP errors from WordPress'], 500);
        }

        $errors = $result['errors'] ?? $result;
        $synced = 0;
        $skipped = 0;

        if (is_array($errors)) {
            foreach ($errors as $error) {
                try {
                    // Use the PhpError model to log/update the error
                    \App\Models\PhpError::logError(
                        $project->id,
                        $error['type'] ?? 'notice',
                        $error['message'] ?? 'Unknown error',
                        $error['file'] ?? null,
                        $error['line'] ?? null,
                        [
                            'wordpress_version' => $error['wordpress_version'] ?? null,
                            'php_version' => $error['php_version'] ?? null,
                            'plugin_slug' => $error['plugin_slug'] ?? null,
                            'theme_slug' => $error['theme_slug'] ?? null,
                        ]
                    );
                    $synced++;
                } catch (\Exception $e) {
                    $skipped++;
                }
            }
        }

        // Update project's last sync timestamp
        $project->update(['php_errors_synced_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => "Synced {$synced} errors from WordPress",
            'synced' => $synced,
            'skipped' => $skipped,
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Clear PHP errors on WordPress site.
     */
    public function clearPhpErrors(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->clearPhpErrors();

        if (!$result) {
            return response()->json(['error' => 'Failed to clear PHP errors on WordPress'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'PHP errors cleared on WordPress',
            ...$result,
        ]);
    }

    // =========================================================================
    // Activity Log (from WordPress)
    // =========================================================================

    /**
     * Get activity log from WordPress.
     */
    public function getActivity(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $limit = $request->input('limit', 50);
        $action = $request->input('action');

        $result = $lsm->getActivity($limit, $action);

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch activity from WordPress'], 500);
        }

        return response()->json($result);
    }

    /**
     * Get activity statistics from WordPress.
     */
    public function getActivityStats(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getActivityStats();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch activity stats from WordPress'], 500);
        }

        return response()->json($result);
    }

    // =========================================================================
    // SITE INFO & SECURITY SETTINGS
    // =========================================================================

    /**
     * Get comprehensive site information.
     */
    public function getSiteInfo(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getSiteInfo();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch site info'], 500);
        }

        return response()->json($result);
    }

    /**
     * Get list of WordPress users.
     */
    public function getUsers(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getUsers();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch users'], 500);
        }

        return response()->json($result);
    }

    /**
     * Get current security settings.
     */
    public function getSecuritySettings(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getSecuritySettings();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch security settings'], 500);
        }

        return response()->json($result);
    }

    /**
     * Update security settings.
     */
    public function updateSecuritySettings(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $settings = $request->only([
            'comments_enabled',
            'registration_enabled',
            'xmlrpc_enabled',
            'rest_api_public',
            'file_editing_disabled',
            'debug_enabled',
            'security_headers_enabled',
        ]);

        $result = $lsm->updateSecuritySettings($settings);

        if (!$result) {
            return response()->json(['error' => 'Failed to update security settings'], 500);
        }

        return response()->json($result);
    }

    /**
     * Get security headers status for the site.
     */
    public function getSecurityHeaders(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getSecurityHeaders();

        if (!$result) {
            return response()->json(['error' => 'Failed to fetch security headers'], 500);
        }

        return response()->json($result);
    }

    /**
     * Get security header configuration snippets for Apache, Nginx, and PHP.
     */
    public function getSecurityHeaderSnippets(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        $result = $lsm->getSecurityHeaderSnippets();

        if (!$result) {
            return response()->json(['error' => 'Failed to generate security header snippets'], 500);
        }

        return response()->json($result);
    }

    // =========================================================================
    // WP ACCOUNT SYNC
    // =========================================================================

    /**
     * Manually trigger a full sync of WordPress user accounts.
     * Creates WP accounts for all assigned team members and
     * removes accounts for users no longer assigned.
     */
    public function syncWpAccounts(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return response()->json(['error' => 'LSM not configured'], 400);
        }

        SyncWpAccountsJob::dispatch($project, [], [], true);

        return response()->json([
            'success' => true,
            'message' => 'WP account sync has been queued. Accounts will be synced shortly.',
        ]);
    }
}
