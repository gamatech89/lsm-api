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
     * Managers see all credentials on their projects.
     * Developers only see credentials with an explicit access grant.
     */
    public function view(User $user, Credential $credential): bool
    {
        $project = $credential->project;

        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }

        if ($user->role === 'developer') {
            $onProject = $project->developer_id === $user->id
                || $project->developers()->where('user_id', $user->id)->exists();
            return $onProject && $credential->hasAccessGrant($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     * Only admins and managers can create credentials.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     * Only project managers can update credentials.
     */
    public function update(User $user, Credential $credential): bool
    {
        if ($user->role === 'manager') {
            return $credential->project->managers->contains('id', $user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     * Only project managers can delete credentials.
     */
    public function delete(User $user, Credential $credential): bool
    {
        if ($user->role === 'manager') {
            return $credential->project->managers->contains('id', $user->id);
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
     * Only managers can share credentials.
     */
    public function share(User $user, Credential $credential): bool
    {
        if ($user->role === 'manager') {
            return $credential->project->managers->contains('id', $user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can manage access grants for this credential.
     * Only managers on the project can grant/revoke developer access.
     */
    public function manageAccess(User $user, Credential $credential): bool
    {
        if ($user->role === 'manager') {
            return $credential->project->managers->contains('id', $user->id);
        }

        return false;
    }
}
