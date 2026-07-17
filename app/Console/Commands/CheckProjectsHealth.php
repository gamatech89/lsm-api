<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deep health data collector.
 *
 * Gathers response time, SSL certificate details and (with --deep) WordPress
 * plugin data for every monitored project. It deliberately does NOT write
 * projects.health_status and does NOT send outage notifications — that is
 * owned exclusively by sites:check-uptime, which implements the
 * confirm-before-alert state machine and notification cooldowns.
 */
class CheckProjectsHealth extends Command
{
    protected $signature = 'projects:health-check
                            {--dry-run : Show what would happen without making changes}
                            {--deep : Include WordPress plugin health checks where available}
                            {--project= : Check a specific project by ID}';

    protected $description = 'Collect health data (SSL, response time, WordPress info) for monitored projects';

    public function handle(): int
    {
        $this->info('Starting project health checks...');
        $this->newLine();

        $query = Project::whereNotNull('url')
            ->where('status', '!=', 'archived')
            ->where(function ($q) {
                $q->where('uptime_monitoring_enabled', true)
                  ->orWhereNull('uptime_monitoring_enabled');
            });

        if ($this->option('project')) {
            $query->where('id', $this->option('project'));
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->warn('No monitored projects with URLs found.');
            return Command::SUCCESS;
        }

        $results = [
            'reachable' => 0,
            'unreachable' => 0,
            'deep_checks' => 0,
        ];

        $this->withProgressBar($projects, function ($project) use (&$results) {
            $result = $this->checkProject($project);
            $results['reachable'] += $result['reachable'] ? 1 : 0;
            $results['unreachable'] += $result['reachable'] ? 0 : 1;
            $results['deep_checks'] += $result['deep'] ? 1 : 0;
        });

        $this->newLine(2);
        $this->displaySummary($results);

        return Command::SUCCESS;
    }

    /**
     * @return array{reachable: bool, deep: bool}
     */
    private function checkProject(Project $project): array
    {
        $startTime = microtime(true);
        $reachable = true;
        $deep = false;
        $healthDetails = [];

        $updateData = [
            'last_health_check_at' => now(),
        ];

        try {
            // Basic HTTP reachability probe (informational only — status
            // ownership lives in sites:check-uptime).
            $response = Http::timeout(15)->get($project->url);

            $updateData['response_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $healthDetails['http_status'] = $response->status();
            $reachable = $response->successful();
        } catch (\Exception $e) {
            $reachable = false;
            $healthDetails['error'] = $e->getMessage();
            Log::info("Health data probe failed for {$project->name}: {$e->getMessage()}");
        }

        // SSL Certificate Check
        $sslInfo = $this->checkSSL($project->url);
        $healthDetails['ssl'] = $sslInfo;

        if (isset($sslInfo['status'])) {
            $updateData['ssl_status'] = $sslInfo['status'];
            $updateData['ssl_expires_at'] = $sslInfo['expires_at'] ?? null;
        }

        // Deep WordPress check if enabled and secret is configured
        if ($this->option('deep') && $project->health_check_secret) {
            $wpHealth = $this->checkWordPressHealth($project);
            if ($wpHealth) {
                $deep = true;
                $healthDetails['wordpress'] = $wpHealth;

                $updateData['wp_version'] = $wpHealth['wordpress']['version'] ?? null;
                $updateData['php_version'] = $wpHealth['php']['version'] ?? null;
                $updateData['outdated_plugins_count'] = $wpHealth['plugins']['outdated_count'] ?? 0;
            }
        }

        $updateData['last_health_details'] = $healthDetails;

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment("  Would update {$project->name} (reachable: " . ($reachable ? 'yes' : 'no') . ')');
            return ['reachable' => $reachable, 'deep' => $deep];
        }

        $project->update($updateData);

        return ['reachable' => $reachable, 'deep' => $deep];
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
            // Verification stays off on purpose: the probe must be able to read
            // expired/invalid certificates instead of failing on them. SNI is
            // required so shared-hosting servers present the right vhost cert.
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
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
     * Check WordPress health via the LSM plugin endpoint.
     */
    private function checkWordPressHealth(Project $project): ?array
    {
        if (empty($project->health_check_secret)) {
            return null;
        }

        try {
            $baseUrl = rtrim($project->url, '/');

            $response = Http::timeout(10)
                ->withHeaders(['X-LSM-Key' => $project->health_check_secret])
                ->get("{$baseUrl}/wp-json/lsm/v1/health");

            if (!$response->successful()) {
                Log::info("WordPress health endpoint returned {$response->status()} for {$project->name} — plugin missing, deactivated, or the API key has drifted.");
                return null;
            }

            // The plugin wraps its payload in a { success, data } envelope.
            $body = $response->json();

            return $body['data'] ?? $body;
        } catch (\Exception $e) {
            Log::info("WordPress health check not available for {$project->name}: {$e->getMessage()}");
            return null;
        }
    }

    private function displaySummary(array $results): void
    {
        $this->info('Health Check Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Reachable', $results['reachable']],
                ['Unreachable', $results['unreachable']],
                ['Deep (plugin) checks', $results['deep_checks']],
            ]
        );

        $total = $results['reachable'] + $results['unreachable'];
        $healthyPercent = $total > 0 ? round(($results['reachable'] / $total) * 100, 1) : 0;

        $this->newLine();
        if ($healthyPercent === 100.0) {
            $this->info("🎉 All projects are reachable!");
        } elseif ($healthyPercent >= 90) {
            $this->info("✓ {$healthyPercent}% of projects are reachable.");
        } else {
            $this->warn("⚠ Only {$healthyPercent}% of projects are reachable — see sites:check-uptime for confirmed outages.");
        }
    }
}
