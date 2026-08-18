<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Notifications\BackupCompletedNotification;
use App\Notifications\BackupFailedNotification;
use App\Services\BackupStorageService;
use App\Services\LsmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Job to create a backup for a WordPress site via RMB/LSM plugin.
 * 
 * This job:
 * 1. Triggers backup creation on the WordPress site
 * 2. Downloads the backup file
 * 3. Stores it using the configured backup storage driver
 * 4. Updates the backup record with file info
 */
class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Backup $backup
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BackupStorageService $storage): void
    {
        $backup = $this->backup;

        // Backstop for the BACKUP_ENABLED master switch: every dispatcher is
        // already gated, but a job that was queued before the flag flipped (or a
        // future direct dispatch) must still never touch the client site.
        if (! config('backup.enabled', false)) {
            Log::info("CreateBackupJob: backup feature disabled (BACKUP_ENABLED=false); skipping backup {$backup->id}");
            $backup->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => 'Backups are currently disabled.',
            ]);

            return;
        }

        $project = $backup->project;
        $lsmService = LsmService::for($project);

        Log::info("CreateBackupJob: Starting backup for project {$project->id} using driver {$storage->getDriver()}");

        try {
            // Update status to in_progress
            $backup->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            // Check if project has RMB connection
            if (!$project->health_check_secret) {
                throw new \Exception('Project does not have RMB connection configured');
            }

            // Get current site health for metadata
            $health = $this->getSiteHealth($lsmService);

            // Trigger backup on WordPress site
            $backupResult = $this->triggerWordPressBackup($lsmService);

            if (!$backupResult['success']) {
                throw new \Exception($backupResult['message'] ?? 'Failed to create backup on WordPress site');
            }

            // Download and store the backup file
            $filePath = null;
            $fileSize = null;
            $checksum = null;

            if (!empty($backupResult['download_url'])) {
                $storeResult = $this->downloadAndStoreBackup(
                    $storage,
                    $project,
                    $backupResult['download_url']
                );
                $filePath = $storeResult['path'];
                $fileSize = $storeResult['size'];
                $checksum = $storeResult['checksum'];
            }

            // Update backup record with success
            $backup->update([
                'status' => 'completed',
                'completed_at' => now(),
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'metadata' => [
                    'wordpress_version' => $health['wordpress']['version'] ?? null,
                    'php_version' => $health['php']['version'] ?? null,
                    'site_url' => $health['site_url'] ?? $project->url,
                    'plugins_count' => $health['plugins']['total'] ?? null,
                    'theme' => $health['theme']['name'] ?? null,
                    'storage_driver' => $storage->getDriver(),
                ],
            ]);

            // Cleanup old backups based on retention policy
            $storage->cleanupOldBackups($project->id);

            // Send success notification
            $this->sendNotification($backup, true);

            Log::info("CreateBackupJob: Backup completed for project {$project->id}");

        } catch (\Exception $e) {
            Log::error("CreateBackupJob: Failed for project {$project->id}: {$e->getMessage()}");

            $backup->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            // Send failure notification
            $this->sendNotification($backup, false, $e->getMessage());

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Send backup notification.
     */
    private function sendNotification(Backup $backup, bool $success, ?string $error = null): void
    {
        if (!config('backup.notifications.enabled', true)) {
            return;
        }

        try {
            $project = $backup->project;
            $recipients = $this->getNotificationRecipients($project);

            if (empty($recipients)) {
                return;
            }

            $notification = $success 
                ? new BackupCompletedNotification($backup)
                : new BackupFailedNotification($backup, $error ?? 'Unknown error');

            Notification::send($recipients, $notification);

            Log::info("CreateBackupJob: Sent " . ($success ? 'success' : 'failure') . " notification for backup {$backup->id}");

        } catch (\Exception $e) {
            Log::warning("CreateBackupJob: Failed to send notification: {$e->getMessage()}");
        }
    }

    /**
     * Get notification recipients for a project.
     */
    private function getNotificationRecipients($project): array
    {
        $recipients = [];

        // Get project managers
        foreach ($project->managers as $manager) {
            $recipients[] = $manager;
        }
        // Fallback: legacy manager_id
        if (empty($recipients) && $project->manager_id) {
            $recipients[] = $project->manager;
        }

        // Get configured notification emails
        $emails = config('backup.notifications.email.recipients', []);
        foreach ($emails as $email) {
            // Create anonymous notifiable for each email
            $recipients[] = Notification::route('mail', $email);
        }

        return $recipients;
    }

    /**
     * Get site health data.
     */
    private function getSiteHealth(LsmService $lsmService): array
    {
        try {
            $response = $lsmService->getHealth();
            return $response ?? [];
        } catch (\Exception $e) {
            Log::warning("CreateBackupJob: Could not get health data: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Trigger backup creation on WordPress site.
     */
    private function triggerWordPressBackup(LsmService $lsmService): array
    {
        try {
            // Call the actual backup endpoint on the WordPress site
            $response = $lsmService->createBackup([
                'includes_database' => $this->backup->includes_database,
                'includes_files' => $this->backup->includes_files,
                'includes_uploads' => $this->backup->includes_uploads,
            ]);

            if (!$response) {
                return [
                    'success' => false,
                    'message' => 'No response from WordPress backup endpoint',
                ];
            }

            return [
                'success' => true,
                'message' => 'Backup created successfully',
                'download_url' => $response['download_url'] ?? null,
                'backup_file' => $response['backup_file'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Download the backup file from WordPress and store using configured driver.
     */
    private function downloadAndStoreBackup(
        BackupStorageService $storage,
        $project,
        string $downloadUrl
    ): array {
        // Generate filename
        $filename = BackupStorageService::generateFilename(
            $project->id,
            $this->backup->type
        );

        // Download file from WordPress
        $response = Http::timeout(300)
            ->withHeaders([
                'X-LSM-Key' => $project->health_check_secret,
            ])
            ->get($downloadUrl);

        if (!$response->successful()) {
            throw new \Exception("Failed to download backup: HTTP {$response->status()}");
        }

        $content = $response->body();
        $checksum = md5($content);

        // Store using the backup storage service
        $storagePath = $storage->store($project->id, $filename, $content);

        return [
            'path' => $storagePath,
            'size' => strlen($content),
            'checksum' => $checksum,
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("CreateBackupJob: Job failed permanently: {$exception->getMessage()}");

        $this->backup->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => 'Backup failed after multiple attempts: ' . $exception->getMessage(),
        ]);
    }
}
