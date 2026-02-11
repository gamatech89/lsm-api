<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Backup Storage Service
 * 
 * Provides a unified interface for backup storage, supporting multiple
 * storage backends (local, S3, Google Cloud, Google Drive).
 */
class BackupStorageService
{
    protected string $driver;
    protected Filesystem $disk;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? config('backup.driver', 'local');
        $diskName = $this->getDiskName();
        $this->disk = Storage::disk($diskName);
    }

    /**
     * Get the filesystem disk name for the current driver.
     */
    protected function getDiskName(): string
    {
        $disks = config('backup.disks', [
            'local' => 'backups',
            's3' => 'backups-s3',
            'gcs' => 'backups-gcs',
            'gdrive' => 'backups-gdrive',
        ]);

        return $disks[$this->driver] ?? 'backups';
    }

    /**
     * Get the current driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get the underlying filesystem disk.
     */
    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    /**
     * Store a backup file.
     * 
     * @param int $projectId Project ID for organizing backups
     * @param string $filename Filename for the backup
     * @param string|resource $contents File contents or stream
     * @return string The full storage path
     */
    public function store(int $projectId, string $filename, $contents): string
    {
        $path = $this->getProjectPath($projectId, $filename);
        
        if (is_resource($contents)) {
            $this->disk->writeStream($path, $contents);
        } else {
            $this->disk->put($path, $contents);
        }

        Log::info("BackupStorageService: Stored backup at {$path} using driver {$this->driver}");

        return $path;
    }

    /**
     * Store a backup from a local file path.
     */
    public function storeFromPath(int $projectId, string $filename, string $localPath): string
    {
        $stream = fopen($localPath, 'r');
        $path = $this->store($projectId, $filename, $stream);
        fclose($stream);
        
        return $path;
    }

    /**
     * Download/retrieve a backup file.
     */
    public function get(string $path): ?string
    {
        if (!$this->exists($path)) {
            return null;
        }

        return $this->disk->get($path);
    }

    /**
     * Get a stream for a backup file.
     */
    public function getStream(string $path)
    {
        if (!$this->exists($path)) {
            return null;
        }

        return $this->disk->readStream($path);
    }

    /**
     * Check if a backup file exists.
     */
    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    /**
     * Delete a backup file.
     */
    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return true;
        }

        $result = $this->disk->delete($path);
        Log::info("BackupStorageService: Deleted backup at {$path}");

        return $result;
    }

    /**
     * Get file size in bytes.
     */
    public function size(string $path): int
    {
        return $this->disk->size($path);
    }

    /**
     * Get last modified timestamp.
     */
    public function lastModified(string $path): int
    {
        return $this->disk->lastModified($path);
    }

    /**
     * List all backups for a project.
     */
    public function listBackups(int $projectId): array
    {
        $directory = $this->getProjectDirectory($projectId);
        
        if (!$this->disk->exists($directory)) {
            return [];
        }

        $files = $this->disk->files($directory);
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'path' => $file,
                'filename' => basename($file),
                'size' => $this->size($file),
                'last_modified' => $this->lastModified($file),
            ];
        }

        // Sort by last modified, newest first
        usort($backups, fn($a, $b) => $b['last_modified'] - $a['last_modified']);

        return $backups;
    }

    /**
     * Generate a temporary download URL (for S3/cloud storage).
     */
    public function temporaryUrl(string $path, int $minutes = 60): ?string
    {
        // Only works for S3-compatible storage
        if (!in_array($this->driver, ['s3', 'gcs'])) {
            return null;
        }

        try {
            return $this->disk->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Exception $e) {
            Log::warning("BackupStorageService: Could not generate temporary URL: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get the storage path for a project.
     */
    public function getProjectDirectory(int $projectId): string
    {
        return "projects/{$projectId}";
    }

    /**
     * Get the full path for a backup file.
     */
    public function getProjectPath(int $projectId, string $filename): string
    {
        return $this->getProjectDirectory($projectId) . '/' . $filename;
    }

    /**
     * Generate a backup filename.
     */
    public static function generateFilename(int $projectId, string $type = 'manual'): string
    {
        $timestamp = now()->format('Y-m-d_His');
        return "backup_{$projectId}_{$type}_{$timestamp}.zip";
    }

    /**
     * Clean up old backups based on retention settings.
     */
    public function cleanupOldBackups(int $projectId): int
    {
        $maxBackups = config('backup.retention.max_backups', 10);
        $maxAgeDays = config('backup.retention.max_age_days', 30);
        $minBackups = config('backup.retention.min_backups', 3);

        $backups = $this->listBackups($projectId);
        $deleted = 0;
        $kept = 0;

        foreach ($backups as $backup) {
            // Keep minimum backups
            if ($kept < $minBackups) {
                $kept++;
                continue;
            }

            // Delete if over max count
            if ($kept >= $maxBackups) {
                $this->delete($backup['path']);
                $deleted++;
                continue;
            }

            // Delete if too old
            if ($maxAgeDays > 0) {
                $age = now()->diffInDays(\Carbon\Carbon::createFromTimestamp($backup['last_modified']));
                if ($age > $maxAgeDays) {
                    $this->delete($backup['path']);
                    $deleted++;
                    continue;
                }
            }

            $kept++;
        }

        if ($deleted > 0) {
            Log::info("BackupStorageService: Cleaned up {$deleted} old backups for project {$projectId}");
        }

        return $deleted;
    }

    /**
     * Get storage usage summary.
     */
    public function getStorageUsage(int $projectId): array
    {
        $backups = $this->listBackups($projectId);
        $totalSize = array_sum(array_column($backups, 'size'));

        return [
            'count' => count($backups),
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'driver' => $this->driver,
        ];
    }

    /**
     * Format bytes to human readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
