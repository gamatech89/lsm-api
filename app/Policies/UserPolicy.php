<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Admins can do anything with users.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        return null;
    }

    /**
     * Determine whether the user can view any models.
     * Only admins and managers can view team list.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager']);
    }

    /**
     * Determine whether the user can view the model.
     * Users can view their own profile, admins/managers can view all.
     */
    public function view(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }
        
        return in_array($user->role, ['admin', 'manager']);
    }

    /**
     * Determine whether the user can create models.
     * Only admins can create new team members.
     */
    public function create(User $user): bool
    {
        return false; // Only admins via before()
    }

    /**
     * Determine whether the user can update the model.
     * Users can update their own profile, admins can update anyone.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id;
    }

    /**
     * Determine whether the user can delete the model.
     * Only admins can delete users.
     */
    public function delete(User $user, User $model): bool
    {
        // Prevent self-deletion
        if ($user->id === $model->id) {
            return false;
        }
        
        return false; // Only admins via before()
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage team members.
     */
    public function manageTeam(User $user): bool
    {
        return false; // Only admins via before()
    }
}
