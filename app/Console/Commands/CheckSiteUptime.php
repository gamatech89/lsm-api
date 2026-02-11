<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Models\UptimeCheck;
use App\Notifications\SiteDownNotification;
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
        
        // Get projects to check
        // Only check projects where:
        // - health_check_secret is set (plugin is connected)
        // - url is set
        // - status is not archived
        // - uptime_monitoring_enabled is true (per-project toggle)
        $query = Project::whereNotNull('health_check_secret')
            ->whereNotNull('url')
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
        $results = ['up' => 0, 'down' => 0, 'error' => 0, 'redirect' => 0];
        $batches = $projects->chunk($concurrency);

        foreach ($batches as $batch) {
            $batchResults = $this->checkBatchParallel($batch, $timeout);
            foreach ($batchResults as $result) {
                $results[$result]++;
            }
        }

        $duration = round(now()->floatDiffInSeconds($startTime), 1);
        $this->newLine();
        $this->info("Completed in {$duration}s: ✅ {$results['up']} up, ⚠️ {$results['redirect']} redirects, ❌ {$results['down']} down, 💥 {$results['error']} errors");

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
        $responses = Http::pool(function ($pool) use ($projectsList, $timeout) {
            foreach ($projectsList as $index => $project) {
                $baseUrl = rtrim($project->url, '/');
                $healthUrl = "{$baseUrl}/wp-json/lsm/v1/health?key={$project->health_check_secret}";

                $pool->as($index)
                    ->timeout($timeout)
                    ->withOptions(['allow_redirects' => false])
                    ->get($healthUrl);
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

        // Check for redirects (3xx status codes)
        if ($httpStatus >= 300 && $httpStatus < 400) {
            $redirectLocation = $response->header('Location') ?? 'unknown';
            
            $this->logCheck($project, 'redirect', $httpStatus, $responseTime, "Redirecting to: {$redirectLocation}");
            
            // Update project status
            $project->update([
                'last_health_check_at' => now(),
                'response_time_ms' => $responseTime,
                'health_status' => 'down_error',
                'last_health_details' => [
                    'error' => true,
                    'error_type' => 'redirect',
                    'redirect_to' => $redirectLocation,
                    'http_status' => $httpStatus,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);

            if ($this->getOutput()->isVerbose()) {
                $this->warn("⚠️ {$project->name}: Redirect ({$httpStatus}) -> {$redirectLocation}");
            }
            
            return 'redirect';
        }

        // Check for errors (4xx, 5xx)
        if (!$response->successful()) {
            $errorMessage = "HTTP {$httpStatus}";
            
            $body = $response->body();
            if (str_contains(strtolower($body), 'maintenance')) {
                $errorMessage = "Maintenance mode active";
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

            // Send site down notification
            $this->sendSiteDownNotification($project, 'http_error', $errorMessage, $httpStatus);

            return 'down';
        }

        // Success - parse health data
        $healthData = $response->json() ?? [];

        $project->update([
            'last_health_check_at' => now(),
            'response_time_ms' => $responseTime,
            'last_health_details' => $healthData,
            'wp_version' => $healthData['wordpress']['version'] ?? null,
            'php_version' => $healthData['php']['version'] ?? null,
            'outdated_plugins_count' => $healthData['plugins']['outdated_count'] ?? 0,
            'ssl_status' => ($healthData['ssl']['enabled'] ?? false) ? 'valid' : 'none',
            'health_status' => 'online',
        ]);

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

        // Send site down notification
        $this->sendSiteDownNotification($project, 'connection_error', $errorMessage);

        return 'error';
    }

    /**
     * Handle any exception during checking.
     */
    protected function handleException(Project $project, \Exception $e): string
    {
        $errorMessage = $this->parseConnectionError($e->getMessage());
        
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
     */
    protected function sendSiteDownNotification(Project $project, string $errorType, string $errorMessage, ?int $httpStatus = null): void
    {
        $prefs = $project->notification_preferences ?? [];
        $triggers = $prefs['triggers'] ?? [];
        $siteDown = $triggers['site_down'] ?? ['enabled' => true];

        if (empty($siteDown['enabled'])) {
            return;
        }

        // Collect team members
        $members = collect();
        if ($project->manager_id) {
            $members->push(User::find($project->manager_id));
        }
        if ($project->developer_id) {
            $members->push(User::find($project->developer_id));
        }
        foreach ($project->developers as $dev) {
            $members->push($dev);
        }
        // Always notify admins for site down
        $members = $members->merge(User::where('role', 'admin')->get());
        $members = $members->filter()->unique('id');

        foreach ($members as $member) {
            $member->notify(new SiteDownNotification($project, $errorType, $errorMessage, $httpStatus));
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
            return 'DNS resolution failed - domain may not exist';
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
