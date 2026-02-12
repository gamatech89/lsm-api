<?php

namespace App\Policies;

use App\Models\Credential;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CredentialPolicy
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
     * All authenticated users can view the vault.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * Users can view credentials for projects they manage or develop.
     */
    public function view(User $user, Credential $credential): bool
    {
        $project = $credential->project;
        
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            return $project->developers()->where('user_id', $user->id)->exists();
        }
        
        // Viewers can see credentials but not edit
        if ($user->role === 'viewer') {
            return true;
        }
        
        return false;
    }

    /**
     * Determine whether the user can create models.
     * Managers and developers can create credentials for their projects.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager', 'developer']);
    }

    /**
     * Determine whether the user can update the model.
     * Only project managers and assigned developers can update.
     */
    public function update(User $user, Credential $credential): bool
    {
        $project = $credential->project;
        
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            return $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     * Only project managers can delete credentials.
     */
    public function delete(User $user, Credential $credential): bool
    {
        $project = $credential->project;
        
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Credential $credential): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Credential $credential): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create share links for this credential.
     * Managers and developers for their assigned projects can share.
     */
    public function share(User $user, Credential $credential): bool
    {
        $project = $credential->project;
        
        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            return $project->developer_id === $user->id 
                || $project->developers->contains($user->id);
        }
        
        return false;
    }
}
