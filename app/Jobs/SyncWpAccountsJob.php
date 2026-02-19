<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Services\WpAccountSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to sync WordPress user accounts with LSM Platform team assignments.
 *
 * Handles provisioning (creating) and deprovisioning (deleting) WordPress
 * accounts when team members are assigned to or removed from a project.
 */
class SyncWpAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * WP site might be temporarily down, so retry a few times.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    public Project $project;

    /** @var int[] User IDs to provision (create WP accounts for) */
    public array $usersToAdd;

    /** @var int[] User IDs to deprovision (delete WP accounts for) */
    public array $usersToRemove;

    /** Whether to do a full sync instead of incremental add/remove */
    public bool $fullSync;

    /**
     * Create a new job instance.
     *
     * @param Project $project
     * @param int[] $usersToAdd User IDs to create WP accounts for
     * @param int[] $usersToRemove User IDs to delete WP accounts for
     * @param bool $fullSync If true, does a full sync instead of incremental
     */
    public function __construct(
        Project $project,
        array $usersToAdd = [],
        array $usersToRemove = [],
        bool $fullSync = false
    ) {
        $this->project = $project;
        $this->usersToAdd = $usersToAdd;
        $this->usersToRemove = $usersToRemove;
        $this->fullSync = $fullSync;
    }

    /**
     * Execute the job.
     */
    public function handle(WpAccountSyncService $syncService): void
    {
        Log::info("SyncWpAccountsJob: Starting for project {$this->project->id}", [
            'full_sync' => $this->fullSync,
            'add_count' => count($this->usersToAdd),
            'remove_count' => count($this->usersToRemove),
        ]);

        if ($this->fullSync) {
            $result = $syncService->syncProjectTeam($this->project);
            Log::info("SyncWpAccountsJob: Full sync completed for project {$this->project->id}", [
                'result' => $result,
            ]);
            return;
        }

        // Incremental: provision new users
        foreach ($this->usersToAdd as $userId) {
            $user = User::find($userId);
            if ($user) {
                $syncService->provisionUser($this->project, $user);
            }
        }

        // Incremental: deprovision removed users
        foreach ($this->usersToRemove as $userId) {
            $user = User::find($userId);
            if ($user) {
                $syncService->deprovisionUser($this->project, $user);
            }
        }

        Log::info("SyncWpAccountsJob: Completed for project {$this->project->id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SyncWpAccountsJob: Failed for project {$this->project->id}", [
            'error' => $exception->getMessage(),
            'add_count' => count($this->usersToAdd),
            'remove_count' => count($this->usersToRemove),
        ]);
    }
}
