<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\BackupStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to clean up old backups based on retention policy.
 * 
 * This job iterates through all projects and deletes backups
 * that exceed the configured retention limits.
 */
class CleanupOldBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(BackupStorageService $storage): void
    {
        $maxBackups = config('backup.retention.max_backups', 10);
        $maxAgeDays = config('backup.retention.max_age_days', 30);
        $minBackups = config('backup.retention.min_backups', 3);

        Log::info("CleanupOldBackupsJob: Starting cleanup (max: {$maxBackups}, age: {$maxAgeDays} days, min: {$minBackups})");

        // Get all projects
        $projects = Project::all();
        $totalDeleted = 0;

        foreach ($projects as $project) {
            $deleted = $this->cleanupProjectBackups($project, $storage, $maxBackups, $maxAgeDays, $minBackups);
            $totalDeleted += $deleted;
        }

        Log::info("CleanupOldBackupsJob: Completed. Deleted {$totalDeleted} old backups");
    }

    /**
     * Clean up backups for a specific project.
     */
    private function cleanupProjectBackups(
        Project $project,
        BackupStorageService $storage,
        int $maxBackups,
        int $maxAgeDays,
        int $minBackups
    ): int {
        $backups = $project->backups()
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->get();

        $deleted = 0;
        $kept = 0;

        foreach ($backups as $backup) {
            // Keep minimum backups
            if ($kept < $minBackups) {
                $kept++;
                continue;
            }

            // Calculate age
            $ageDays = now()->diffInDays($backup->created_at);

            // Delete if over max count OR too old
            $shouldDelete = ($kept >= $maxBackups) || ($maxAgeDays > 0 && $ageDays > $maxAgeDays);

            if ($shouldDelete) {
                try {
                    // Delete file from storage
                    if ($backup->file_path) {
                        $storage->delete($backup->file_path);
                    }

                    // Delete database record
                    $backup->delete();
                    $deleted++;

                    Log::debug("CleanupOldBackupsJob: Deleted backup {$backup->id} for project {$project->id}");

                } catch (\Exception $e) {
                    Log::warning("CleanupOldBackupsJob: Failed to delete backup {$backup->id}: {$e->getMessage()}");
                }
            } else {
                $kept++;
            }
        }

        return $deleted;
    }
}
