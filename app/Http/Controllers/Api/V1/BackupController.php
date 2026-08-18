<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\Project;
use App\Services\BackupStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Backup Controller
 * 
 * Handles project backup management with configurable storage backends.
 */
class BackupController extends Controller
{
    protected BackupStorageService $storage;

    public function __construct(BackupStorageService $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List all backups for a project.
     */
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $backups = $project->backups()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $backups,
        ]);
    }

    /**
     * Get a single backup.
     */
    public function show(Backup $backup): JsonResponse
    {
        // Shallow route — the project is derived from the backup.
        Gate::authorize('view', $backup->project);

        $backup->load('creator:id,name');

        return response()->json([
            'success' => true,
            'data' => $backup,
        ]);
    }

    /**
     * Create a new backup.
     */
    public function store(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'type' => 'sometimes|in:manual,scheduled,pre_update',
            'includes_database' => 'sometimes|boolean',
            'includes_files' => 'sometimes|boolean',
            'includes_uploads' => 'sometimes|boolean',
        ]);

        // Check if WordPress is connected
        if (!$project->health_check_secret) {
            return response()->json([
                'success' => false,
                'message' => 'WordPress connection required to create backups',
            ], 400);
        }

        // Create backup record
        $backup = $project->backups()->create([
            'created_by' => auth()->id(),
            'type' => $validated['type'] ?? 'manual',
            'status' => 'pending',
            'includes_database' => $validated['includes_database'] ?? config('backup.defaults.includes_database', true),
            'includes_files' => $validated['includes_files'] ?? config('backup.defaults.includes_files', true),
            'includes_uploads' => $validated['includes_uploads'] ?? config('backup.defaults.includes_uploads', true),
            'started_at' => now(),
        ]);

        // Dispatch backup job to queue
        CreateBackupJob::dispatch($backup);

        return response()->json([
            'success' => true,
            'message' => 'Backup started',
            'data' => $backup,
        ], 201);
    }

    /**
     * Delete a backup.
     */
    public function destroy(Backup $backup): JsonResponse
    {
        // Shallow route — the project is derived from the backup.
        Gate::authorize('update', $backup->project);

        // Delete the backup file using the storage service
        if ($backup->file_path) {
            $this->storage->delete($backup->file_path);
        }

        $backup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Backup deleted',
        ]);
    }

    /**
     * Download a backup.
     * 
     * For cloud storage (S3, GCS), returns a temporary signed URL.
     * For local storage, streams the file directly.
     */
    public function download(Backup $backup)
    {
        // Shallow route — the project is derived from the backup.
        $project = $backup->project;
        Gate::authorize('view', $project);

        if (!$backup->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Backup is not completed yet',
            ], 400);
        }

        if (!$backup->file_path || !$this->storage->exists($backup->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Backup file not found',
            ], 404);
        }

        // For cloud storage, try to generate a temporary URL
        $tempUrl = $this->storage->temporaryUrl($backup->file_path, 60);
        if ($tempUrl) {
            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $tempUrl,
                    'expires_in' => 3600, // 60 minutes
                ],
            ]);
        }

        // For local storage, stream the file
        $filename = "backup-{$project->slug}-{$backup->created_at->format('Y-m-d-His')}.zip";
        $stream = $this->storage->getStream($backup->file_path);

        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length' => $this->storage->size($backup->file_path),
            ]
        );
    }

    /**
     * Restore a backup.
     */
    public function restore(Backup $backup): JsonResponse
    {
        // Shallow route — the project is derived from the backup.
        Gate::authorize('update', $backup->project);

        if (!$backup->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Backup is not completed yet',
            ], 400);
        }

        // Dispatch restore job to queue
        RestoreBackupJob::dispatch($backup);

        return response()->json([
            'success' => true,
            'message' => 'Backup restore started',
        ]);
    }

    /**
     * Get backup statistics for a project.
     */
    public function stats(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        // Get storage usage
        $storageUsage = $this->storage->getStorageUsage($project->id);

        $stats = [
            'total' => $project->backups()->count(),
            'completed' => $project->backups()->completed()->count(),
            'last_backup' => $project->backups()->completed()->latest()->first()?->created_at,
            'total_size' => $project->backups()->completed()->sum('file_size'),
            'by_type' => [
                'manual' => $project->backups()->where('type', 'manual')->count(),
                'scheduled' => $project->backups()->where('type', 'scheduled')->count(),
                'pre_update' => $project->backups()->where('type', 'pre_update')->count(),
            ],
            'storage' => [
                'driver' => $this->storage->getDriver(),
                'files_count' => $storageUsage['count'],
                'total_size' => $storageUsage['total_size'],
                'total_size_human' => $storageUsage['total_size_human'],
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get backup settings/configuration.
     */
    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                // Master feature switch (BACKUP_ENABLED). The SPA hides its backup UI when false.
                'enabled' => (bool) config('backup.enabled', false),
                'driver' => config('backup.driver'),
                'available_drivers' => ['local', 's3', 'gcs', 'gdrive'],
                'retention' => config('backup.retention'),
                'schedule' => config('backup.schedule'),
                'defaults' => config('backup.defaults'),
            ],
        ]);
    }
}
