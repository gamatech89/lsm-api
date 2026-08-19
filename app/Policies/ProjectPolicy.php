<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Admins can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return null;
    }

    /**
     * Determine whether the user can view any models.
     * All authenticated users can view projects list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * Users can only view projects they are assigned to.
     */
    public function view(User $user, Project $project): bool
    {
        // Managers see every project while permissions.managers_view_all_projects
        // is on (visibility only — update/delete/credentials stay assignment-scoped).
        if ($user->canViewAllProjects()) {
            return true;
        }

        if ($user->role === 'manager') {
            return $project->isManagedBy($user);
        }

        if ($user->role === 'developer') {
            return $project->developer_id === $user->id
                || $project->developers()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     * Only admins and managers can create projects.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager']);
    }

    /**
     * Determine whether the user manages the project.
     * Delegates to Project::isManagedBy(), the platform-wide source of truth
     * (legacy manager_id column OR project_manager pivot).
     */
    private function managesProject(User $user, Project $project): bool
    {
        return $project->isManagedBy($user);
    }

    /**
     * Determine whether the user can update the model.
     * Managers can update their own projects, developers can update assigned projects.
     */
    public function update(User $user, Project $project): bool
    {
        if ($user->role === 'manager') {
            return $this->managesProject($user, $project);
        }
        
        if ($user->role === 'developer') {
            // Check both legacy single developer and many-to-many relationship
            return $project->developer_id === $user->id 
                || $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     * Only admins and the project's manager can delete.
     */
    public function delete(User $user, Project $project): bool
    {
        if ($user->role === 'manager') {
            return $this->managesProject($user, $project);
        }
        
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }

    /**
     * Determine whether the user can permanently delete the model.
     * Only admins can force delete.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false; // Only admins via before()
    }

    /**
     * Determine whether the user can manage credentials for this project.
     * Only admins and project managers can manage credentials.
     */
    public function manageCredentials(User $user, Project $project): bool
    {
        if ($user->role === 'admin' || $user->is_admin) {
            return true;
        }

        if ($user->role === 'manager') {
            return $this->managesProject($user, $project);
        }

        // Developers cannot manage credentials
        return false;
    }

    /**
     * Determine whether the user can assign team members to the project.
     */
    public function assignTeam(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $this->managesProject($user, $project);
    }
}
