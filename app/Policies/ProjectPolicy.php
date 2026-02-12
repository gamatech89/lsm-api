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
     * All authenticated users can view project details.
     */
    public function view(User $user, Project $project): bool
    {
        return true;
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
     * Determine whether the user can update the model.
     * Managers can update their own projects, developers can update assigned projects.
     */
    public function update(User $user, Project $project): bool
    {
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
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
            return $project->managers->contains('id', $user->id);
        }
        
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $project->managers->contains('id', $user->id);
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
     */
    public function manageCredentials(User $user, Project $project): bool
    {
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            // Check both legacy single developer and many-to-many relationship
            return $project->developer_id === $user->id 
                || $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }

    /**
     * Determine whether the user can assign team members to the project.
     */
    public function assignTeam(User $user, Project $project): bool
    {
        return $user->role === 'manager' && $project->managers->contains('id', $user->id);
    }
}
