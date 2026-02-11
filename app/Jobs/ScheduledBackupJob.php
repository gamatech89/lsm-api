<?php

namespace App\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to run scheduled backups for all eligible projects.
 * 
 * Dispatched by the scheduler, this job iterates through projects
 * and creates backup jobs for those that are due for a backup.
 */
class ScheduledBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!config('backup.schedule.enabled', true)) {
            Log::info('ScheduledBackupJob: Scheduled backups are disabled');
            return;
        }

        $frequency = config('backup.schedule.frequency', 'weekly');
        
        Log::info("ScheduledBackupJob: Running {$frequency} scheduled backups");

        // Get all projects with RMB connection
        $projects = Project::whereNotNull('health_check_secret')
            ->where('archived', false)
            ->get();

        $backupsCreated = 0;

        foreach ($projects as $project) {
            if ($this->shouldBackup($project, $frequency)) {
                $this->createBackupForProject($project);
                $backupsCreated++;
            }
        }

        Log::info("ScheduledBackupJob: Created {$backupsCreated} scheduled backups");
    }

    /**
     * Determine if a project should be backed up.
     */
    private function shouldBackup(Project $project, string $frequency): bool
    {
        // Get the last completed backup
        $lastBackup = $project->backups()
            ->where('status', 'completed')
            ->latest()
            ->first();

        // If no backup exists, create one
        if (!$lastBackup) {
            return true;
        }

        $lastBackupDate = $lastBackup->created_at;

        switch ($frequency) {
            case 'daily':
                return $lastBackupDate->lt(now()->subDay());
                
            case 'weekly':
                return $lastBackupDate->lt(now()->subWeek());
                
            case 'monthly':
                return $lastBackupDate->lt(now()->subMonth());
                
            default:
                return $lastBackupDate->lt(now()->subWeek());
        }
    }

    /**
     * Create a backup for a project.
     */
    private function createBackupForProject(Project $project): void
    {
        try {
            // Create backup record
            $backup = $project->backups()->create([
                'type' => 'scheduled',
                'status' => 'pending',
                'includes_database' => config('backup.defaults.includes_database', true),
                'includes_files' => config('backup.defaults.includes_files', true),
                'includes_uploads' => config('backup.defaults.includes_uploads', true),
                'started_at' => now(),
            ]);

            // Dispatch the backup job
            CreateBackupJob::dispatch($backup);

            Log::info("ScheduledBackupJob: Created backup for project {$project->id} ({$project->name})");

        } catch (\Exception $e) {
            Log::error("ScheduledBackupJob: Failed to create backup for project {$project->id}: {$e->getMessage()}");
        }
    }
}
