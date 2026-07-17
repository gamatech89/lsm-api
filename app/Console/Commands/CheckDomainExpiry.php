<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Notifications\DomainExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckDomainExpiry extends Command
{
    protected $signature = 'projects:check-domains
                            {--project= : Check a specific project by ID}
                            {--dry-run : Show results without saving}';

    protected $description = 'Check domain expiry dates via WHOIS for all active projects';

    public function handle(): int
    {
        $this->info('Checking domain expiry dates...');
        $this->newLine();

        $query = Project::whereNotNull('url')
            ->where('status', '!=', 'archived');

        if ($this->option('project')) {
            $query->where('id', $this->option('project'));
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects with URLs found.');
            return Command::SUCCESS;
        }

        $results = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        $this->withProgressBar($projects, function ($project) use (&$results) {
            $domain = $this->extractDomain($project->url);
            
            if (!$domain) {
                $results['skipped']++;
                return;
            }

            $whoisData = $this->lookupWhois($domain);

            if ($whoisData) {
                if (!$this->option('dry-run')) {
                    $updateData = [];
                    if ($whoisData['expires_at']) {
                        $updateData['domain_expires_at'] = $whoisData['expires_at'];
                    }
                    if ($whoisData['registrar']) {
                        $updateData['domain_registrar'] = $whoisData['registrar'];
                    }
                    if (!empty($updateData)) {
                        $project->update($updateData);
                    }

                    $this->maybeNotifyExpiring($project, $whoisData);
                }

                $this->newLine();
                $this->info("  ✓ {$project->name} ({$domain}): expires {$whoisData['expires_at']}");
                $results['updated']++;
            } else {
                $this->newLine();
                $this->warn("  ⚠ {$project->name} ({$domain}): could not parse WHOIS data");
                $results['errors']++;
            }
        });

        $this->newLine(2);
        $this->displaySummary($results);

        return Command::SUCCESS;
    }

    /**
     * Extract the root domain from a URL.
     * e.g. https://sub.example.com/path -> example.com
     */
    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if (!$host) {
            return null;
        }

        // Remove www prefix
        $host = preg_replace('/^www\./', '', $host);

        // Extract root domain (last two parts for most TLDs)
        $parts = explode('.', $host);
        
        if (count($parts) < 2) {
            return null;
        }

        // Handle common multi-part TLDs
        $multiPartTlds = ['co.uk', 'com.au', 'co.nz', 'co.za', 'com.br', 'co.jp', 'co.kr', 'sg-host.com'];
        $lastTwo = implode('.', array_slice($parts, -2));
        
        if (in_array($lastTwo, $multiPartTlds) && count($parts) >= 3) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * Look up WHOIS data for a domain.
     */
    private function lookupWhois(string $domain): ?array
    {
        try {
            $output = [];
            $returnCode = 0;
            
            // Run whois command
            exec("whois " . escapeshellarg($domain) . " 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0 || empty($output)) {
                Log::info("WHOIS lookup failed for {$domain}: exit code {$returnCode}");
                return null;
            }

            $whoisText = implode("\n", $output);
            
            return $this->parseWhoisData($whoisText);
        } catch (\Exception $e) {
            Log::warning("WHOIS lookup error for {$domain}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse WHOIS response text to extract expiry date and registrar.
     */
    private function parseWhoisData(string $whoisText): ?array
    {
        $expiresAt = null;
        $registrar = null;

        // Common patterns for expiry date across different registrars
        $expiryPatterns = [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Registrar Registration Expiration Date:\s*(.+)/i', 
            '/Expiry Date:\s*(.+)/i',
            '/Expiration Date:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
            '/free-date:\s*(.+)/i',
            '/expires:\s*(.+)/i',
            '/expire:\s*(.+)/i',
            '/Renewal Date:\s*(.+)/i',
        ];

        foreach ($expiryPatterns as $pattern) {
            if (preg_match($pattern, $whoisText, $matches)) {
                $dateStr = trim($matches[1]);
                $timestamp = strtotime($dateStr);
                if ($timestamp !== false) {
                    $expiresAt = date('Y-m-d', $timestamp);
                    break;
                }
            }
        }

        // Parse registrar
        $registrarPatterns = [
            '/Registrar:\s*(.+)/i',
            '/registrar:\s*(.+)/i',
        ];

        foreach ($registrarPatterns as $pattern) {
            if (preg_match($pattern, $whoisText, $matches)) {
                $registrar = trim($matches[1]);
                break;
            }
        }

        if (!$expiresAt) {
            return null;
        }

        return [
            'expires_at' => $expiresAt,
            'registrar' => $registrar,
            'days_until_expiry' => (int) ((strtotime($expiresAt) - time()) / 86400),
        ];
    }

    /**
     * Alert the project team when the domain expires within 30 days.
     * The command runs weekly, so a 6-day dedup window means one alert per run.
     */
    private function maybeNotifyExpiring(Project $project, array $whoisData): void
    {
        if ($project->domain_alerts_enabled === false) {
            return;
        }

        $daysRemaining = $whoisData['days_until_expiry'] ?? null;
        if ($daysRemaining === null || $daysRemaining > 30) {
            return;
        }

        $daysRemaining = max($daysRemaining, 0);

        $recentlyNotified = DB::table('notifications')
            ->where('type', DomainExpiringNotification::class)
            ->where('data->project_id', $project->id)
            ->where('created_at', '>=', now()->subDays(6))
            ->exists();

        if ($recentlyNotified) {
            return;
        }

        foreach ($this->getProjectMembers($project) as $member) {
            $member->notify(new DomainExpiringNotification(
                $project,
                $whoisData['expires_at'],
                $daysRemaining,
            ));
        }
    }

    private function getProjectMembers(Project $project)
    {
        $members = collect();

        foreach ($project->managers as $manager) {
            $members->push($manager);
        }
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

    private function displaySummary(array $results): void
    {
        $this->info('Domain Check Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $results['updated']],
                ['Skipped', $results['skipped']],
                ['Errors', $results['errors']],
            ]
        );
    }
}
