<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    /**
     * Admins can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        return null;
    }

    /**
     * Determine whether the user can view todos for a project.
     * All authenticated users can view todos.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view a specific todo.
     */
    public function view(User $user, Todo $todo): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create todos.
     * Managers can create for their projects, developers for assigned projects.
     * Viewers cannot create.
     */
    public function create(User $user, Project $project): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            // Allow if assigned as single developer or in many-to-many
            return $project->developer_id === $user->id
                || $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }

    /**
     * Determine whether the user can update the todo.
     * Same rules as create - must be assigned to the project.
     */
    public function update(User $user, Todo $todo): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        $project = $todo->project;

        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        if ($user->role === 'developer') {
            // Allow if assigned as single developer or in many-to-many
            return $project->developer_id === $user->id
                || $project->developers()->where('user_id', $user->id)->exists();
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the todo.
     * Only managers (of their projects) can delete.
     */
    public function delete(User $user, Todo $todo): bool
    {
        if ($user->role === 'viewer' || $user->role === 'developer') {
            return false;
        }

        $project = $todo->project;

        if ($user->role === 'manager') {
            return $project->managers->contains('id', $user->id);
        }
        
        return false;
    }

    /**
     * Determine whether the user can download todo attachments.
     */
    public function download(User $user, Todo $todo): bool
    {
        // All users who can view the project can download attachments
        return true;
    }
}
