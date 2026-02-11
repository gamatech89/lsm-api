<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;

class TimesheetPolicy
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
     * Determine whether the user can view any timesheets.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the timesheet.
     * Users can view their own, managers can view team timesheets.
     */
    public function view(User $user, Timesheet $timesheet): bool
    {
        // Users can view their own timesheets
        if ($timesheet->user_id === $user->id) {
            return true;
        }

        // Managers can view timesheets of users on their projects
        if ($user->role === 'manager') {
            return $this->managesUserProjects($user, $timesheet->user_id);
        }

        return false;
    }

    /**
     * Determine whether the user can create timesheets.
     * Timesheets are auto-created, but users can trigger creation.
     */
    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * Determine whether the user can update the timesheet.
     * Only owners of open/rejected timesheets can update.
     */
    public function update(User $user, Timesheet $timesheet): bool
    {
        if ($timesheet->user_id !== $user->id) {
            return false;
        }

        return in_array($timesheet->status, [
            Timesheet::STATUS_OPEN,
            Timesheet::STATUS_REJECTED,
        ]);
    }

    /**
     * Determine whether the user can submit the timesheet.
     */
    public function submit(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->user_id === $user->id
            && in_array($timesheet->status, [
                Timesheet::STATUS_OPEN,
                Timesheet::STATUS_REJECTED,
            ]);
    }

    /**
     * Determine whether the user can approve/reject the timesheet.
     * Only managers who manage projects the user works on can approve.
     */
    public function approve(User $user, Timesheet $timesheet): bool
    {
        if ($user->role === 'manager') {
            return $this->managesUserProjects($user, $timesheet->user_id);
        }

        return false;
    }

    /**
     * Alias for approve - same permissions.
     */
    public function reject(User $user, Timesheet $timesheet): bool
    {
        return $this->approve($user, $timesheet);
    }

    /**
     * Check if manager has projects where the given user has worked.
     */
    private function managesUserProjects(User $manager, int $userId): bool
    {
        // Get projects this manager manages
        $projectIds = \App\Models\Project::where('manager_id', $manager->id)->pluck('id');
        
        // Check if the user has time entries on those projects
        return \App\Models\TimeEntry::whereIn('project_id', $projectIds)
            ->where('user_id', $userId)
            ->exists();
    }
}
