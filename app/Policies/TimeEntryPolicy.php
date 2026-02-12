<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;

class TimeEntryPolicy
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
     * Determine whether the user can view any time entries.
     * Managers can view entries for their projects, developers only their own.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the time entry.
     * Users can view their own entries, managers can view entries on their projects.
     */
    public function view(User $user, TimeEntry $timeEntry): bool
    {
        // Users can view their own entries
        if ($timeEntry->user_id === $user->id) {
            return true;
        }

        // Managers can view entries on projects they manage
        if ($user->role === 'manager') {
            $project = $timeEntry->project;
            return $project && $project->managers->contains('id', $user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can create time entries.
     * All non-viewer users can create their own time entries.
     */
    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * Determine whether the user can update the time entry.
     * Users can update their own draft entries.
     */
    public function update(User $user, TimeEntry $timeEntry): bool
    {
        // Only owner can update their draft entries
        if ($timeEntry->user_id !== $user->id) {
            // Managers can update entries on their projects
            if ($user->role === 'manager') {
                $project = $timeEntry->project;
                return $project && $project->managers->contains('id', $user->id);
            }
            return false;
        }

        // Can only edit draft or rejected entries
        return in_array($timeEntry->status, [
            TimeEntry::STATUS_DRAFT,
            TimeEntry::STATUS_REJECTED,
        ]);
    }

    /**
     * Determine whether the user can delete the time entry.
     * Users can delete their own draft entries.
     */
    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        // Only owner can delete their draft entries
        if ($timeEntry->user_id !== $user->id) {
            return false;
        }

        // Can only delete draft entries
        return $timeEntry->status === TimeEntry::STATUS_DRAFT;
    }

    /**
     * Determine whether the user can approve time entries.
     * Only managers and admins can approve.
     */
    public function approve(User $user, TimeEntry $timeEntry): bool
    {
        if ($user->role === 'manager') {
            $project = $timeEntry->project;
            return $project && $project->managers->contains('id', $user->id);
        }

        return false;
    }
}
