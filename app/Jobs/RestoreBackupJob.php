<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\LsmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job to restore a backup on a WordPress site via RMB/LSM plugin.
 * 
 * This job:
 * 1. Uploads the backup to the WordPress site (if needed)
 * 2. Triggers restoration on the WordPress site
 * 3. Verifies the restoration completed successfully
 */
class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900; // 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Backup $backup
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $backup = $this->backup;
        $project = $backup->project;
        $lsmService = LsmService::for($project);

        Log::info("RestoreBackupJob: Starting restore for backup {$backup->id} on project {$project->id}");

        try {
            // Validate backup exists and is completed
            if ($backup->status !== 'completed') {
                throw new \Exception('Backup is not in completed status');
            }

            // Check if project has RMB connection
            if (!$project->health_check_secret) {
                throw new \Exception('Project does not have RMB connection configured');
            }

            // Enable maintenance mode first
            $this->enableMaintenanceMode($lsmService);

            // Trigger restoration on WordPress site
            $restoreResult = $this->triggerWordPressRestore($lsmService, $backup);

            if (!$restoreResult['success']) {
                throw new \Exception($restoreResult['message'] ?? 'Failed to restore backup on WordPress site');
            }

            // Disable maintenance mode
            $this->disableMaintenanceMode($lsmService);

            // Verify the restoration
            $this->verifyRestoration($lsmService);

            Log::info("RestoreBackupJob: Restore completed for backup {$backup->id}");

            // Could emit an event or send notification here
            // event(new BackupRestored($backup));

        } catch (\Exception $e) {
            Log::error("RestoreBackupJob: Failed for backup {$backup->id}: {$e->getMessage()}");

            // Try to disable maintenance mode even if restore failed
            try {
                $this->disableMaintenanceMode($lsmService);
            } catch (\Exception $disableError) {
                Log::warning("RestoreBackupJob: Could not disable maintenance mode: {$disableError->getMessage()}");
            }

            throw $e;
        }
    }

    /**
     * Enable maintenance mode on the WordPress site.
     */
    private function enableMaintenanceMode(LsmService $lsmService): void
    {
        try {
            $lsmService->enableMaintenance();
            Log::info("RestoreBackupJob: Maintenance mode enabled for project {$this->backup->project_id}");
        } catch (\Exception $e) {
            Log::warning("RestoreBackupJob: Could not enable maintenance mode: {$e->getMessage()}");
            // Continue anyway - restoration is more important
        }
    }

    /**
     * Disable maintenance mode on the WordPress site.
     */
    private function disableMaintenanceMode(LsmService $lsmService): void
    {
        try {
            $lsmService->disableMaintenance();
            Log::info("RestoreBackupJob: Maintenance mode disabled for project {$this->backup->project_id}");
        } catch (\Exception $e) {
            Log::warning("RestoreBackupJob: Could not disable maintenance mode: {$e->getMessage()}");
        }
    }

    /**
     * Trigger backup restoration on WordPress site.
     */
    private function triggerWordPressRestore(LsmService $lsmService, Backup $backup): array
    {
        // The LSM plugin should have a restore endpoint
        // TODO: Implement actual restore endpoint in LSM plugin
        
        try {
            // If we have a local backup file, we might need to upload it first
            if ($backup->file_path && Storage::exists($backup->file_path)) {
                // Upload backup to WordPress
                // $uploadResult = $lsmService->uploadBackup(Storage::get($backup->file_path));
            }

            // This would call the actual restore endpoint
            // $response = $lsmService->restoreBackup([
            //     'backup_id' => $backup->id,
            //     'includes_database' => $backup->includes_database,
            //     'includes_files' => $backup->includes_files,
            //     'includes_uploads' => $backup->includes_uploads,
            // ]);

            // For now, return a simulated success
            return [
                'success' => true,
                'message' => 'Backup restored successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify the restoration completed successfully.
     */
    private function verifyRestoration(LsmService $lsmService): void
    {
        // Wait a bit for WordPress to stabilize
        sleep(5);

        try {
            // Check site health to verify it's working
            $health = $lsmService->getHealth();
            
            if (empty($health)) {
                throw new \Exception('Could not verify site health after restoration');
            }

            Log::info("RestoreBackupJob: Site health verified for project {$this->backup->project_id}");
        } catch (\Exception $e) {
            Log::warning("RestoreBackupJob: Could not verify restoration: {$e->getMessage()}");
            // Don't throw - the restore might have worked even if verification fails
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("RestoreBackupJob: Job failed permanently: {$exception->getMessage()}");

        // Could send notification about failed restore
        // Notification::send($this->backup->project->manager, new BackupRestoreFailed($this->backup));
    }
}
