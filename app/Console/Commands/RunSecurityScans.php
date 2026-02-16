<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\SecurityScan;
use App\Notifications\MalwareDetectedNotification;
use App\Services\LsmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunSecurityScans extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:scan
                            {--project= : Scan a specific project by ID}
                            {--quick : Run a quick scan instead of full}
                            {--notify : Send notifications for threats}';

    /**
     * The console command description.
     */
    protected $description = 'Run automated security scans on connected WordPress sites';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isQuick = $this->option('quick');
        $shouldNotify = $this->option('notify');
        $scanType = $isQuick ? 'quick' : 'full';

        // Get projects to scan
        if ($projectId = $this->option('project')) {
            $projects = Project::where('id', $projectId)->get();
        } else {
            // Scan all projects that have LSM configured
            $projects = Project::whereNotNull('health_check_secret')
                ->whereNotNull('url')
                ->get();
        }

        if ($projects->isEmpty()) {
            $this->warn('No projects found to scan.');
            return 0;
        }

        $this->info("Starting {$scanType} security scan for {$projects->count()} project(s)...");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;
        $threatsDetected = 0;

        foreach ($projects as $project) {
            $this->info("Scanning: {$project->name} ({$project->url})");

            $lsm = LsmService::for($project);

            if (!$lsm->isConfigured()) {
                $this->warn("  ⏭ Skipping: LSM not configured");
                continue;
            }

            // Create scan record
            $scan = SecurityScan::create([
                'project_id' => $project->id,
                'scan_type' => $scanType,
                'status' => 'running',
                'triggered_by' => 'scheduled',
                'started_at' => now(),
            ]);

            try {
                $results = $isQuick
                    ? $lsm->runQuickScan()
                    : $lsm->runSecurityScan();

                if (!$results) {
                    $scan->update([
                        'status' => 'failed',
                        'completed_at' => now(),
                    ]);
                    $this->error("  ✗ Failed: Could not reach plugin");
                    $failCount++;
                    continue;
                }

                $riskLevel = $results['status'] ?? 'unknown';
                $summary = $results['summary'] ?? [];
                $threats = $summary['threats_found'] ?? 0;
                $warnings = $summary['warnings_found'] ?? 0;

                $scan->update([
                    'status' => 'completed',
                    'risk_level' => $riskLevel,
                    'threats_found' => $threats,
                    'warnings_found' => $warnings,
                    'files_scanned' => $summary['files_scanned'] ?? 0,
                    'duration_seconds' => $results['duration_seconds'] ?? null,
                    'results' => $results,
                    'summary' => $summary,
                    'completed_at' => now(),
                ]);

                // Update project
                $project->update([
                    'last_security_scan_at' => now(),
                    'last_security_scan_risk' => $riskLevel,
                ]);

                // Display result
                $statusIcon = match ($riskLevel) {
                    'clean' => '✅',
                    'low' => '🟢',
                    'medium' => '🟡',
                    'high' => '🟠',
                    'critical' => '🔴',
                    default => '⚪',
                };

                $this->info("  {$statusIcon} {$riskLevel} — {$threats} threats, {$warnings} warnings");

                // Notify if threats detected
                if ($threats > 0 && $shouldNotify) {
                    $threatsDetected += $threats;
                    $this->notifyThreatsDetected($project, $scan);
                    $this->warn("  📧 Notifications sent");
                }

                $successCount++;
            } catch (\Exception $e) {
                $scan->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                ]);
                $this->error("  ✗ Error: {$e->getMessage()}");
                Log::error("Security scan failed for {$project->name}: {$e->getMessage()}");
                $failCount++;
            }

            // Brief pause between projects to avoid overwhelming shared hosting
            usleep(500000); // 500ms
        }

        $this->newLine();
        $this->info("Scan complete: {$successCount} succeeded, {$failCount} failed, {$threatsDetected} threats detected");

        return $failCount > 0 ? 1 : 0;
    }

    /**
     * Send notification when threats are detected.
     */
    protected function notifyThreatsDetected(Project $project, SecurityScan $scan): void
    {
        foreach ($project->managers as $manager) {
            $manager->notify(new MalwareDetectedNotification($project, $scan));
        }

        if ($project->managers->isEmpty() && $project->manager) {
            $project->manager->notify(new MalwareDetectedNotification($project, $scan));
        }
    }
}
