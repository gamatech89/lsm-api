<?php

namespace App\Jobs;

use App\Models\PhpError;
use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to sync PHP errors from WordPress sites.
 * 
 * Fetches errors captured by the LSM plugin on WordPress
 * and stores them in the local database.
 */
class SyncPhpErrorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The project to sync errors for.
     * If null, syncs for all eligible projects.
     */
    public ?Project $project;

    /**
     * Create a new job instance.
     */
    public function __construct(?Project $project = null)
    {
        $this->project = $project;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->project) {
            $this->syncErrorsForProject($this->project);
        } else {
            $this->syncErrorsForAllProjects();
        }
    }

    /**
     * Sync errors for all eligible projects.
     */
    private function syncErrorsForAllProjects(): void
    {
        $projects = Project::whereNotNull('health_check_secret')
            ->where('status', '!=', 'archived')
            ->get();

        Log::info("SyncPhpErrorsJob: Syncing errors for {$projects->count()} projects");

        $totalErrors = 0;

        foreach ($projects as $project) {
            $count = $this->syncErrorsForProject($project);
            $totalErrors += $count;
        }

        Log::info("SyncPhpErrorsJob: Completed. Synced {$totalErrors} errors total");
    }

    /**
     * Sync errors for a specific project.
     */
    private function syncErrorsForProject(Project $project): int
    {
        try {
            $lsmService = LsmService::for($project);
            
            // Fetch errors from WordPress
            $response = $lsmService->getPhpErrors(['unresolved' => true]);

            if (!$response || !isset($response['data'])) {
                Log::debug("SyncPhpErrorsJob: No errors data for project {$project->id}");
                return 0;
            }

            $errors = $response['data'];
            $imported = 0;

            foreach ($errors as $error) {
                $imported += $this->importError($project, $error);
            }

            Log::debug("SyncPhpErrorsJob: Imported {$imported} errors for project {$project->id}");

            return $imported;

        } catch (\Exception $e) {
            Log::warning("SyncPhpErrorsJob: Failed for project {$project->id}: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Import a single error.
     */
    private function importError(Project $project, array $error): int
    {
        $type = $this->normalizeErrorType($error['type'] ?? 'notice');
        $message = $error['message'] ?? 'Unknown error';
        $file = $error['file'] ?? null;
        $line = isset($error['line']) ? (int) $error['line'] : null;

        // Generate hash using the model's method
        $hash = PhpError::generateHash($type, $message, $file, $line);

        // Check if already exists
        $existing = PhpError::where('project_id', $project->id)
            ->where('error_hash', $hash)
            ->first();

        if ($existing) {
            // Update occurrence count and last seen
            $existing->increment('count');
            $existing->update([
                'last_seen_at' => $error['last_seen_at'] ?? now(),
            ]);
            return 0;
        }

        // Create new error
        PhpError::create([
            'project_id' => $project->id,
            'error_hash' => $hash,
            'type' => $type,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'plugin_slug' => $error['plugin_slug'] ?? null,
            'count' => $error['count'] ?? 1,
            'first_seen_at' => $error['first_seen_at'] ?? now(),
            'last_seen_at' => $error['last_seen_at'] ?? now(),
            'is_resolved' => $error['resolved'] ?? false,
        ]);

        return 1;
    }


    /**
     * Normalize error type to match our schema.
     */
    private function normalizeErrorType(string $type): string
    {
        $typeMap = [
            'E_ERROR' => 'fatal',
            'E_PARSE' => 'fatal',
            'E_CORE_ERROR' => 'fatal',
            'E_COMPILE_ERROR' => 'fatal',
            'E_WARNING' => 'warning',
            'E_NOTICE' => 'notice',
            'E_DEPRECATED' => 'deprecated',
            'fatal' => 'fatal',
            'warning' => 'warning',
            'notice' => 'notice',
            'deprecated' => 'deprecated',
        ];

        return $typeMap[$type] ?? 'notice';
    }
}
