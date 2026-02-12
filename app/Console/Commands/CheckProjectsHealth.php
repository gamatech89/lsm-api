<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectStatusChangedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckProjectsHealth extends Command
{
    protected $signature = 'projects:health-check 
                            {--notify : Send notifications for status changes}
                            {--dry-run : Show what would happen without making changes}
                            {--deep : Include WordPress plugin health checks where available}
                            {--project= : Check a specific project by ID}';

    protected $description = 'Check the health status of all active projects (WordPress sites)';

    public function handle(): int
    {
        $this->info('Starting project health checks...');
        $this->newLine();

        $query = Project::whereNotNull('url');
        
        if ($this->option('project')) {
            $query->where('id', $this->option('project'));
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects with URLs found.');
            return Command::SUCCESS;
        }

        $results = [
            'online' => 0,
            'down_error' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'deep_checks' => 0,
        ];

        $this->withProgressBar($projects, function ($project) use (&$results) {
            $result = $this->checkProject($project);
            $results[$result]++;
        });

        $this->newLine(2);
        $this->displaySummary($results);

        return Command::SUCCESS;
    }

    private function checkProject(Project $project): string
    {
        $startTime = microtime(true);
        $newStatus = 'online';
        $healthDetails = [];

        try {
            // Basic HTTP check
            $response = Http::timeout(15)
                ->withOptions(['verify' => true])
                ->get($project->url);
            
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            
            if (!$response->successful()) {
                $newStatus = 'down_error';
                $healthDetails['http_status'] = $response->status();
            } else {
                $healthDetails['http_status'] = $response->status();
            }
            
            // SSL Certificate Check
            $sslInfo = $this->checkSSL($project->url);
            $healthDetails['ssl'] = $sslInfo;
            
            // Deep WordPress check if enabled and secret is configured
            if ($this->option('deep') && $project->health_check_secret) {
                $wpHealth = $this->checkWordPressHealth($project);
                if ($wpHealth) {
                    $healthDetails['wordpress'] = $wpHealth;
                    
                    // Update WordPress-specific fields
                    $project->wp_version = $wpHealth['wordpress']['version'] ?? null;
                    $project->php_version = $wpHealth['php']['version'] ?? null;
                    $project->outdated_plugins_count = $wpHealth['plugins']['outdated_count'] ?? 0;
                }
            }
            
            // Update project with health data
            $updateData = [
                'response_time_ms' => $responseTime,
                'last_health_check_at' => now(),
                'last_health_details' => $healthDetails,
            ];
            
            if (isset($sslInfo['status'])) {
                $updateData['ssl_status'] = $sslInfo['status'];
                $updateData['ssl_expires_at'] = $sslInfo['expires_at'] ?? null;
            }
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $newStatus = 'down_error';
            $healthDetails['error'] = 'Connection failed: ' . $e->getMessage();
            Log::warning("Health check connection failed for {$project->name}: {$e->getMessage()}");
        } catch (\Exception $e) {
            $newStatus = 'down_error';
            $healthDetails['error'] = $e->getMessage();
            Log::warning("Health check failed for {$project->name}: {$e->getMessage()}");
        }

        $oldStatus = $project->health_status;

        if ($oldStatus === $newStatus) {
            // Still update the health check timestamp and details
            if (!$this->option('dry-run')) {
                $project->update($updateData ?? [
                    'last_health_check_at' => now(),
                    'last_health_details' => $healthDetails,
                ]);
            }
            return 'unchanged';
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment("  Would update {$project->name}: {$oldStatus} → {$newStatus}");
            return $newStatus;
        }

        $updateData['health_status'] = $newStatus;
        $project->update($updateData);

        // Send notifications if enabled
        if ($this->option('notify')) {
            $this->sendNotifications($project, $oldStatus, $newStatus);
        }

        $this->newLine();
        if ($newStatus === 'online') {
            $this->info("  ✓ {$project->name} is now online");
        } else {
            $this->error("  ✗ {$project->name} is now down/error");
        }

        return $newStatus;
    }

    /**
     * Check SSL certificate status.
     */
    private function checkSSL(string $url): array
    {
        $parsedUrl = parse_url($url);
        
        if (($parsedUrl['scheme'] ?? '') !== 'https') {
            return ['status' => 'none', 'message' => 'Not using HTTPS'];
        }
        
        $host = $parsedUrl['host'] ?? '';
        if (empty($host)) {
            return ['status' => 'none', 'message' => 'Invalid URL'];
        }

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $socket = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$socket) {
                return ['status' => 'none', 'message' => "SSL connection failed: {$errstr}"];
            }
            
            $params = stream_context_get_params($socket);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            fclose($socket);
            
            if (!$cert) {
                return ['status' => 'none', 'message' => 'Could not parse certificate'];
            }
            
            $expiresAt = isset($cert['validTo_time_t']) 
                ? date('Y-m-d', $cert['validTo_time_t']) 
                : null;
            $daysUntilExpiry = $expiresAt 
                ? (strtotime($expiresAt) - time()) / 86400 
                : null;
            
            if ($daysUntilExpiry !== null && $daysUntilExpiry < 0) {
                $status = 'expired';
            } elseif ($daysUntilExpiry !== null && $daysUntilExpiry < 30) {
                $status = 'expiring_soon';
            } else {
                $status = 'valid';
            }
            
            return [
                'status' => $status,
                'expires_at' => $expiresAt,
                'days_until_expiry' => $daysUntilExpiry ? (int) $daysUntilExpiry : null,
                'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown',
            ];
            
        } catch (\Exception $e) {
            return ['status' => 'none', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check WordPress health via the RMB plugin endpoint.
     */
    private function checkWordPressHealth(Project $project): ?array
    {
        if (empty($project->health_check_secret)) {
            return null;
        }

        try {
            $baseUrl = rtrim($project->url, '/');
            
            // Try new RMB plugin first
            $rmbUrl = "{$baseUrl}/wp-json/rmb/v1/health?key={$project->health_check_secret}";
            $response = Http::timeout(10)->get($rmbUrl);
            
            if ($response->successful()) {
                $data = $response->json();
                // RMB returns nested data structure
                return $data['data'] ?? $data;
            }
            
            // Fallback to legacy LSM plugin
            $lsmUrl = "{$baseUrl}/wp-json/lsm/v1/health?key={$project->health_check_secret}";
            $response = Http::timeout(10)->get($lsmUrl);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        } catch (\Exception $e) {
            Log::info("WordPress health check not available for {$project->name}: {$e->getMessage()}");
            return null;
        }
    }

    private function sendNotifications(Project $project, string $oldStatus, string $newStatus): void
    {
        $teamMembers = collect();

        foreach ($project->managers as $manager) {
            $teamMembers->push($manager);
        }
        // Fallback: legacy manager_id
        if ($teamMembers->isEmpty() && $project->manager_id) {
            $teamMembers->push(User::find($project->manager_id));
        }
        if ($project->developer_id) {
            $teamMembers->push(User::find($project->developer_id));
        }
        
        // Include developers from many-to-many relationship
        foreach ($project->developers as $developer) {
            $teamMembers->push($developer);
        }

        // Also notify admins for critical status changes
        if ($newStatus === 'down_error') {
            $admins = User::where('role', 'admin')->get();
            $teamMembers = $teamMembers->merge($admins);
        }

        $teamMembers = $teamMembers->filter()->unique('id');

        foreach ($teamMembers as $member) {
            $member->notify(new ProjectStatusChangedNotification(
                $project,
                'health',
                $oldStatus,
                $newStatus
            ));
        }
    }

    private function displaySummary(array $results): void
    {
        $this->info('Health Check Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Online', $results['online']],
                ['Down/Error', $results['down_error']],
                ['Unchanged', $results['unchanged']],
                ['Check Errors', $results['errors']],
            ]
        );

        $total = $results['online'] + $results['down_error'] + $results['unchanged'];
        $healthyPercent = $total > 0 ? round(($results['online'] / $total) * 100, 1) : 0;
        
        $this->newLine();
        if ($healthyPercent === 100.0) {
            $this->info("🎉 All projects are online!");
        } elseif ($healthyPercent >= 90) {
            $this->info("✓ {$healthyPercent}% of projects are healthy.");
        } else {
            $this->warn("⚠ Only {$healthyPercent}% of projects are healthy.");
        }
    }
}
