<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Models\UptimeCheck;
use App\Notifications\SiteDownNotification;
use App\Notifications\SiteRecoveredNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Automated Uptime Monitor
 * 
 * Runs at configurable intervals to check all monitored WordPress sites.
 * Uses parallel HTTP requests for efficient checking of many sites.
 * 
 * Detects: connection errors, HTTP errors, redirects, SSL issues, timeouts.
 */
class CheckSiteUptime extends Command
{
    protected $signature = 'sites:check-uptime
                            {--project= : Check specific project ID only}
                            {--concurrency= : Override concurrency setting}';

    protected $description = 'Check uptime for all monitored WordPress sites (parallel)';

    protected int $currentTimeout = 15;

    protected string $userAgent = 'Mozilla/5.0 (compatible; LSM-Uptime-Monitor/1.0)';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        // Check if global monitoring is enabled (from settings or config)
        $isEnabled = \App\Models\Setting::getOrConfig('uptime.enabled', 'uptime.enabled') ?? true;
        if (!$isEnabled) {
            $this->info('Uptime monitoring is disabled in settings.');
            return 0;
        }

        $startTime = now();
        $concurrency = $this->option('concurrency') 
            ?? \App\Models\Setting::getOrConfig('uptime.concurrency', 'uptime.concurrency') 
            ?? 10;
        $timeout = \App\Models\Setting::getOrConfig('uptime.timeout', 'uptime.timeout') ?? 15;
        $this->currentTimeout = (int) $timeout;

        // Get projects to check
        // Only check projects where:
        // - url is set
        // - status is not archived
        // - uptime_monitoring_enabled is true (per-project toggle)
        // Note: health_check_secret is NOT required — sites without the plugin
        //       get a simple HTTP check of their URL instead.
        $query = Project::whereNotNull('url')
            ->where('status', '!=', 'archived')
            ->where(function ($q) {
                // Include projects where monitoring is enabled OR not set (default true)
                $q->where('uptime_monitoring_enabled', true)
                  ->orWhereNull('uptime_monitoring_enabled');
            });

        if ($projectId = $this->option('project')) {
            $query->where('id', $projectId);
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->info('No monitored projects found.');
            return 0;
        }

        $this->info("Checking {$projects->count()} project(s) with concurrency of {$concurrency}...");
        
        // Process in batches for parallel execution
        $results = ['up' => 0, 'down' => 0, 'error' => 0, 'confirming' => 0];
        $batches = $projects->chunk($concurrency);

        foreach ($batches as $batch) {
            $batchResults = $this->checkBatchParallel($batch, $timeout);
            foreach ($batchResults as $result) {
                $results[$result]++;
            }
        }

        $duration = round(now()->floatDiffInSeconds($startTime), 1);
        $this->newLine();
        $this->info("Completed in {$duration}s: ✅ {$results['up']} up, ❌ {$results['down']} down, ⏳ {$results['confirming']} confirming, 💥 {$results['error']} errors");

        // Return non-zero if any sites are down
        return ($results['down'] + $results['error']) > 0 ? 1 : 0;
    }

    /**
     * Check a batch of projects in parallel using HTTP Pool.
     */
    protected function checkBatchParallel($projects, int $timeout): array
    {
        $results = [];
        $projectsList = $projects->values(); // Re-index for easier access

        // Build pool of requests
        // Two-tier: plugin health endpoint if connected, otherwise simple URL check
        $responses = Http::pool(function ($pool) use ($projectsList, $timeout) {
            foreach ($projectsList as $index => $project) {
                $baseUrl = rtrim($project->url, '/');

                // If plugin is connected, check the health endpoint for rich data
                // Otherwise, just check the project URL directly.
                // The secret goes in the X-LSM-Key header, never the query string
                // (query strings end up in access logs).
                $checkUrl = $project->health_check_secret
                    ? "{$baseUrl}/wp-json/lsm/v1/health"
                    : $baseUrl;

                $headers = ['User-Agent' => $this->userAgent];
                if ($project->health_check_secret) {
                    $headers['X-LSM-Key'] = $project->health_check_secret;
                }

                $pool->as($index)
                    ->timeout($timeout)
                    ->withHeaders($headers)
                    ->withOptions([
                        'allow_redirects' => [
                            'max' => 5,
                            'track_redirects' => true,
                        ],
                    ])
                    ->get($checkUrl);
            }
        });

        // Process responses
        foreach ($projectsList as $index => $project) {
            try {
                $response = $responses[$index];
                
                if ($response instanceof \Illuminate\Http\Client\Response) {
                    $results[] = $this->processResponse($project, $response);
                } else {
                    // Connection exception or other error
                    $results[] = $this->handleError($project, $response);
                }
            } catch (\Exception $e) {
                $results[] = $this->handleException($project, $e);
            }
        }

        return $results;
    }

    /**
     * Process a successful HTTP response.
     */
    protected function processResponse(Project $project, $response): string
    {
        $httpStatus = $response->status();
        $responseTime = null;

        // Try to get transfer time from response
        $transferStats = $response->transferStats ?? null;
        if ($transferStats) {
            $responseTime = round($transferStats->getTransferTime() * 1000);
        }

        // Auto-correct project URL if a redirect was followed
        // (e.g. www -> non-www, http -> https)
        $redirectHistory = $response->header('X-Guzzle-Redirect-History');
        if ($redirectHistory) {
            $effectiveUrl = $transferStats?->getEffectiveUri();
            if ($effectiveUrl) {
                $effectiveBase = parse_url((string) $effectiveUrl, PHP_URL_SCHEME)
                    . '://' . parse_url((string) $effectiveUrl, PHP_URL_HOST);
                $currentBase = parse_url($project->url, PHP_URL_SCHEME)
                    . '://' . parse_url($project->url, PHP_URL_HOST);
                // Only scheme/host corrections (www → non-www, http → https).
                // The original path must survive — sites living under a subpath
                // would otherwise get their URL silently truncated.
                $currentPath = rtrim((string) parse_url($project->url, PHP_URL_PATH), '/');
                if ($effectiveBase && $effectiveBase !== $currentBase) {
                    $oldUrl = $project->url;
                    $project->url = $effectiveBase . $currentPath;
                    Log::info("Auto-corrected URL for {$project->name}: {$oldUrl} → {$project->url}");
                }
            }
        }

        // 429 = rate limited — the server is UP and responding, just throttling our checker.
        // Treat as success to avoid false "site down" alerts.
        if ($httpStatus === 429) {
            return $this->handleRateLimited($project, $httpStatus, $responseTime);
        }

        // Check for errors (4xx, 5xx)
        if (!$response->successful()) {
            $errorMessage = "HTTP {$httpStatus}";
            
            $body = $response->body();
            if (str_contains(strtolower($body), 'maintenance')) {
                $errorMessage = "Maintenance mode active";
            }

            // If the health endpoint failed but the base URL is reachable, the plugin is broken
            // (deactivated, updated, REST API disabled) — the site itself is fine.
            if ($project->health_check_secret && $this->checkBaseUrl($project)) {
                $wasDown = $project->health_status === 'down_error';
                $project->update([
                    'last_health_check_at' => now(),
                    'response_time_ms' => $responseTime,
                    'health_status' => 'online',
                    'last_health_details' => [
                        'simple_check' => true,
                        'http_status' => $httpStatus,
                        'health_endpoint_error' => $errorMessage,
                        'checked_at' => now()->toIso8601String(),
                    ],
                ]);
                $this->logCheck($project, 'up', $httpStatus, $responseTime, 'Health endpoint error (base URL is accessible)');
                if ($wasDown) {
                    $this->sendSiteRecoveredNotification($project);
                }
                if ($this->getOutput()->isVerbose()) {
                    $this->warn("⚠️  {$project->name}: Health endpoint {$httpStatus} but base URL is up");
                }
                return 'up';
            }

            // For plain URL checks (no plugin), retry once on HTTP errors before entering confirming_down.
            // 5xx codes (503, 502, etc.) are often transient. Health-endpoint sites already got
            // the checkBaseUrl() fallback above, so no double-retry for those.
            if (!$project->health_check_secret
                && $project->health_status === 'online'
                && $this->retrySingleCheck($project)
            ) {
                $this->logCheck($project, 'up', $httpStatus, $responseTime, 'Recovered on immediate retry');
                $project->update(['last_health_check_at' => now(), 'health_status' => 'online']);
                if ($this->getOutput()->isVerbose()) {
                    $this->warn("🔄 {$project->name}: HTTP {$httpStatus} but recovered on retry");
                }
                return 'up';
            }

            // Confirm-before-alert: only mark as down_error if already confirming
            $isAlreadyFailing = in_array($project->health_status, ['confirming_down', 'down_error']);

            if (!$isAlreadyFailing) {
                // First failure — mark as confirming, do NOT notify
                $project->update([
                    'last_health_check_at' => now(),
                    'response_time_ms' => $responseTime,
                    'health_status' => 'confirming_down',
                    'last_health_details' => [
                        'error' => true,
                        'error_type' => 'http_error',
                        'http_status' => $httpStatus,
                        'error_message' => $errorMessage,
                        'checked_at' => now()->toIso8601String(),
                    ],
                ]);

                $this->logCheck($project, 'confirming', $httpStatus, $responseTime, $errorMessage);

                if ($this->getOutput()->isVerbose()) {
                    $this->warn("⏳ {$project->name}: {$errorMessage} (confirming...)");
                }

                return 'confirming';
            }

            $this->logCheck($project, 'down', $httpStatus, $responseTime, $errorMessage);

            $project->update([
                'last_health_check_at' => now(),
                'response_time_ms' => $responseTime,
                'health_status' => 'down_error',
                'last_health_details' => [
                    'error' => true,
                    'error_type' => 'http_error',
                    'http_status' => $httpStatus,
                    'error_message' => $errorMessage,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);

            if ($this->getOutput()->isVerbose()) {
                $this->error("❌ {$project->name}: {$errorMessage}");
            }

            // Send site down notification (confirmed down)
            $this->sendSiteDownNotification($project, 'http_error', $errorMessage, $httpStatus);

            return 'down';
        }

        // Success — track if we're recovering from a confirmed outage before overwriting status
        $wasDown = $project->health_status === 'down_error';

        $updateData = [
            'last_health_check_at' => now(),
            'response_time_ms' => $responseTime,
            'health_status' => 'online',
        ];

        // If plugin is connected, parse rich health data from the endpoint
        if ($project->health_check_secret) {
            $healthData = $response->json() ?? [];
            $data = $healthData['data'] ?? $healthData;

            $updateData['last_health_details'] = $healthData;
            $updateData['wp_version'] = $data['wordpress']['version'] ?? null;
            $updateData['php_version'] = $data['php']['version'] ?? null;
            $updateData['outdated_plugins_count'] = $data['plugins']['outdated_count'] ?? 0;
            // ssl_status is owned by projects:health-check, whose certificate
            // probe can distinguish valid/expiring_soon/expired — the plugin's
            // boolean would flatten that back to 'valid' on every run.
        } else {
            // Simple URL check — just store basic status info
            $updateData['last_health_details'] = [
                'simple_check' => true,
                'http_status' => $httpStatus,
                'checked_at' => now()->toIso8601String(),
            ];
        }

        $project->update($updateData);

        if ($wasDown) {
            $this->sendSiteRecoveredNotification($project);
        }

        $this->logCheck($project, 'up', $httpStatus, $responseTime);

        if ($this->getOutput()->isVerbose()) {
            $this->info("✅ {$project->name}: OK" . ($responseTime ? " ({$responseTime}ms)" : ''));
        }

        return 'up';
    }

    /**
     * Handle a connection error from HTTP Pool.
     */
    protected function handleError(Project $project, $response): string
    {
        $errorMessage = 'Connection failed';
        
        if ($response instanceof \GuzzleHttp\Exception\ConnectException) {
            $errorMessage = $this->parseConnectionError($response->getMessage());
        } elseif ($response instanceof \Exception) {
            $errorMessage = $this->parseConnectionError($response->getMessage());
        }

        // Immediate retry to filter transient network/DNS glitches.
        // Only retry when the site was previously online — no point retrying an already-confirmed failure.
        if ($project->health_status === 'online' && $this->retrySingleCheck($project)) {
            $this->logCheck($project, 'up', null, null, 'Recovered on immediate retry');
            $project->update(['last_health_check_at' => now(), 'health_status' => 'online']);
            if ($this->getOutput()->isVerbose()) {
                $this->warn("🔄 {$project->name}: transient failure, recovered on retry");
            }
            return 'up';
        }

        // Confirm-before-alert: only mark as down_error if already confirming
        $isAlreadyFailing = in_array($project->health_status, ['confirming_down', 'down_error']);

        if (!$isAlreadyFailing) {
            // First failure — mark as confirming, do NOT notify
            $this->logCheck($project, 'confirming', null, null, $errorMessage);

            $project->update([
                'last_health_check_at' => now(),
                'health_status' => 'confirming_down',
                'last_health_details' => [
                    'error' => true,
                    'error_type' => 'connection_error',
                    'error_message' => $errorMessage,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);

            if ($this->getOutput()->isVerbose()) {
                $this->warn("⏳ {$project->name}: {$errorMessage} (confirming...)");
            }

            return 'confirming';
        }

        // Second consecutive failure — confirmed down
        $this->logCheck($project, 'error', null, null, $errorMessage);

        $project->update([
            'last_health_check_at' => now(),
            'health_status' => 'down_error',
            'last_health_details' => [
                'error' => true,
                'error_type' => 'connection_error',
                'error_message' => $errorMessage,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);

        if ($this->getOutput()->isVerbose()) {
            $this->error("💥 {$project->name}: {$errorMessage}");
        }

        // Send site down notification (confirmed down)
        $this->sendSiteDownNotification($project, 'connection_error', $errorMessage);

        return 'error';
    }

    /**
     * Handle any exception during checking.
     */
    protected function handleException(Project $project, \Exception $e): string
    {
        // Internal errors (DB write failures, etc.) are not site outages.
        // Log them but do not mark the site as down or send notifications.
        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
            Log::error("Internal error during uptime check for {$project->name}", [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            return 'error';
        }

        $errorMessage = $this->parseConnectionError($e->getMessage());

        // Confirm-before-alert: only mark as down_error if already confirming
        $isAlreadyFailing = in_array($project->health_status, ['confirming_down', 'down_error']);

        if (!$isAlreadyFailing) {
            // First failure — mark as confirming, do NOT notify
            $this->logCheck($project, 'confirming', null, null, $errorMessage);

            $project->update([
                'last_health_check_at' => now(),
                'health_status' => 'confirming_down',
                'last_health_details' => [
                    'error' => true,
                    'error_type' => 'exception',
                    'error_message' => $errorMessage,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);

            if ($this->getOutput()->isVerbose()) {
                $this->warn("⏳ {$project->name}: {$errorMessage} (confirming...)");
            }

            return 'confirming';
        }

        // Second consecutive failure — confirmed down
        $this->logCheck($project, 'error', null, null, $errorMessage);

        $project->update([
            'last_health_check_at' => now(),
            'health_status' => 'down_error',
            'last_health_details' => [
                'error' => true,
                'error_type' => 'exception',
                'error_message' => $errorMessage,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);

        if ($this->getOutput()->isVerbose()) {
            $this->error("💥 {$project->name}: {$errorMessage}");
        }

        Log::error("Uptime check failed for project {$project->id}", [
            'project' => $project->name,
            'error' => $e->getMessage(),
        ]);

        // Send site down notification (confirmed down)
        $this->sendSiteDownNotification($project, 'exception', $errorMessage);

        return 'error';
    }

    /**
     * Log an uptime check to the database.
     */
    protected function logCheck(Project $project, string $status, ?int $httpStatus, ?int $responseTime, ?string $errorMessage = null): void
    {
        UptimeCheck::create([
            'project_id' => $project->id,
            'status' => $status,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTime,
            'error_message' => $errorMessage,
            'checked_at' => now(),
        ]);
    }

    /**
     * Send site down notification to project team members.
     * Includes a cooldown to prevent notification spam during prolonged outages.
     */
    protected function sendSiteDownNotification(Project $project, string $errorType, string $errorMessage, ?int $httpStatus = null): void
    {
        $prefs = $project->notification_preferences ?? [];
        $triggers = $prefs['triggers'] ?? [];
        $siteDown = $triggers['site_down'] ?? ['enabled' => true];

        if (empty($siteDown['enabled'])) {
            return;
        }

        // Cooldown: only send one notification per project per cooldown window.
        $cooldownMinutes = config('uptime.notification_cooldown_minutes', 60);
        $recentNotification = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('type', SiteDownNotification::class)
            ->where('data->project_id', $project->id)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();

        if ($recentNotification) {
            return;
        }

        foreach ($this->getProjectMembers($project) as $member) {
            $member->notify(new SiteDownNotification($project, $errorType, $errorMessage, $httpStatus));
        }
    }

    /**
     * Send a recovery notification when a previously confirmed-down site comes back online.
     */
    protected function sendSiteRecoveredNotification(Project $project): void
    {
        $prefs = $project->notification_preferences ?? [];
        $triggers = $prefs['triggers'] ?? [];
        $siteDown = $triggers['site_down'] ?? ['enabled' => true];

        if (empty($siteDown['enabled'])) {
            return;
        }

        // Calculate approximate downtime for the CURRENT outage: the first
        // failed check after the most recent successful one. Searching the
        // whole window would pick up earlier, already-recovered outages.
        $lastUpCheck = UptimeCheck::where('project_id', $project->id)
            ->where('status', 'up')
            ->where('checked_at', '>=', now()->subDay()) // limit search window to 24h
            ->orderBy('checked_at', 'desc')
            ->first();

        $firstDownCheck = UptimeCheck::where('project_id', $project->id)
            ->whereIn('status', ['down', 'error'])
            ->where('checked_at', '>=', $lastUpCheck?->checked_at ?? now()->subDay())
            ->orderBy('checked_at', 'asc')
            ->first();

        $downtimeDuration = $firstDownCheck
            ? now()->diffForHumans($firstDownCheck->checked_at, true)
            : 'unknown';

        foreach ($this->getProjectMembers($project) as $member) {
            $member->notify(new SiteRecoveredNotification($project, $downtimeDuration));
        }
    }

    /**
     * Collect all team members who should be notified for a project.
     * Includes managers, developers, and always admins.
     */
    protected function getProjectMembers(Project $project)
    {
        $members = collect();

        foreach ($project->managers as $manager) {
            $members->push($manager);
        }
        // Fallback: legacy manager_id
        if ($members->isEmpty() && $project->manager_id) {
            $members->push(User::find($project->manager_id));
        }
        if ($project->developer_id) {
            $members->push(User::find($project->developer_id));
        }
        foreach ($project->developers as $dev) {
            $members->push($dev);
        }
        $members = $members->merge(User::where('role', 'admin')->get());

        return $members->filter()->unique('id');
    }

    /**
     * Handle a 429 rate-limited response: the server is up, just throttling our checker.
     */
    protected function handleRateLimited(Project $project, int $httpStatus, ?int $responseTime): string
    {
        $wasDown = $project->health_status === 'down_error';

        $project->update([
            'last_health_check_at' => now(),
            'response_time_ms' => $responseTime,
            'health_status' => 'online',
            'last_health_details' => [
                'simple_check' => true,
                'http_status' => $httpStatus,
                'note' => 'Rate limited (429) — server is responding',
                'checked_at' => now()->toIso8601String(),
            ],
        ]);

        $this->logCheck($project, 'up', $httpStatus, $responseTime, 'Rate limited (429)');

        if ($wasDown) {
            $this->sendSiteRecoveredNotification($project);
        }

        if ($this->getOutput()->isVerbose()) {
            $this->info("✅ {$project->name}: rate limited (429) — treated as up");
        }

        return 'up';
    }

    /**
     * Check if the project's base URL is reachable (used as fallback when the health endpoint fails).
     * Uses a shorter timeout since this is a secondary check.
     */
    protected function checkBaseUrl(Project $project): bool
    {
        try {
            $baseUrl = rtrim($project->url, '/');
            $response = Http::timeout(min($this->currentTimeout, 10))
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->get($baseUrl);
            return $response->successful() || $response->status() === 429;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Immediately retry a check for a project to filter out transient failures.
     * Uses a shorter timeout since this is a quick connectivity probe.
     */
    protected function retrySingleCheck(Project $project): bool
    {
        try {
            $baseUrl = rtrim($project->url, '/');
            $checkUrl = $project->health_check_secret
                ? "{$baseUrl}/wp-json/lsm/v1/health"
                : $baseUrl;
            $headers = ['User-Agent' => $this->userAgent];
            if ($project->health_check_secret) {
                $headers['X-LSM-Key'] = $project->health_check_secret;
            }
            $response = Http::timeout(min($this->currentTimeout, 10))
                ->withHeaders($headers)
                ->get($checkUrl);
            return $response->successful() || $response->status() === 429;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Parse connection error into user-friendly message.
     */
    protected function parseConnectionError(string $message): string
    {
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'Connection timeout - site may be slow or down';
        }
        if (str_contains($message, 'Could not resolve')) {
            return 'DNS resolution failed - verify domain exists and DNS is propagated';
        }
        if (str_contains($message, 'Connection refused')) {
            return 'Connection refused - server may be down';
        }
        if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
            return 'SSL/TLS error - certificate issue';
        }
        if (str_contains($message, 'Connection reset')) {
            return 'Connection reset by server';
        }

        return "Connection error: " . substr($message, 0, 80);
    }
}
