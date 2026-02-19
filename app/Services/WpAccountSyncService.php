<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class WpAccountSyncService
{
    /**
     * Sync all assigned developers and managers to the WP site.
     */
    public function syncProjectTeam(Project $project): ?array
    {
        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return null;
        }

        // Gather all team members (devs + managers, deduplicated by ID)
        $teamMembers = $this->getProjectTeamMembers($project);

        if (empty($teamMembers)) {
            Log::info("WP Account Sync: No team members for project {$project->id}");
            return null;
        }

        $result = $lsm->syncWpUsers($teamMembers);

        Log::info("WP Account Sync: Project {$project->id}", [
            'team_count' => count($teamMembers),
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Create a WordPress account for a single user on a project's WP site.
     */
    public function provisionUser(Project $project, User $user): ?array
    {
        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return null;
        }

        $result = $lsm->createWpUser($user);

        Log::info("WP Account Provisioned: User {$user->email} on project {$project->id}", [
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Remove a WordPress account for a single user from a project's WP site.
     */
    public function deprovisionUser(Project $project, User $user): ?array
    {
        $lsm = LsmService::for($project);

        if (!$lsm->isConfigured()) {
            return null;
        }

        $result = $lsm->deleteWpUser($user->email);

        Log::info("WP Account Deprovisioned: User {$user->email} from project {$project->id}", [
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Get all unique team members (developers + managers) for a project.
     *
     * @return User[]
     */
    public function getProjectTeamMembers(Project $project): array
    {
        $developers = $project->developers()->get();
        $managers = $project->managers()->get();

        // Merge and deduplicate by user ID
        return $developers->merge($managers)->unique('id')->values()->all();
    }
}
