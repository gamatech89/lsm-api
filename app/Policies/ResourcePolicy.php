<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Resource;
use App\Models\User;

class ResourcePolicy
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
     * Determine whether the user can view resources for a project.
     * All authenticated users can view resources.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view a specific resource.
     */
    public function view(User $user, Resource $resource): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create resources.
     * Managers can create for their projects, developers for assigned projects.
     * Viewers cannot create.
     */
    public function create(User $user, Project $project): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'manager') {
            return $project->manager_id === $user->id;
        }
        
        if ($user->role === 'developer') {
            return $project->developer_id === $user->id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can update the resource.
     * Same rules as create - must be assigned to the project.
     */
    public function update(User $user, Resource $resource): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        $project = $resource->project;

        if ($user->role === 'manager') {
            return $project->manager_id === $user->id;
        }
        
        if ($user->role === 'developer') {
            return $project->developer_id === $user->id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the resource.
     * Only managers (of their projects) can delete.
     */
    public function delete(User $user, Resource $resource): bool
    {
        if ($user->role === 'viewer' || $user->role === 'developer') {
            return false;
        }

        $project = $resource->project;

        if ($user->role === 'manager') {
            return $project->manager_id === $user->id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can download resource files.
     */
    public function download(User $user, Resource $resource): bool
    {
        // All users who can view the project can download files
        return true;
    }
}
