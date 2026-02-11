<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Http\Resources\TimeEntryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TimerController extends Controller
{
    /**
     * Get current running timer
     */
    public function current()
    {
        $entry = TimeEntry::with(['project'])
            ->forUser(Auth::id())
            ->running()
            ->first();

        if (!$entry) {
            return $this->success(null, 'No timer running');
        }

        return $this->success(new TimeEntryResource($entry));
    }

    /**
     * Start a new timer
     */
    public function start(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'description' => 'nullable|string|max:500',
            'is_billable' => 'nullable|boolean',
        ]);

        // Check if user already has a running timer
        $existingTimer = TimeEntry::forUser(Auth::id())->running()->first();
        
        if ($existingTimer) {
            return $this->error('You already have a running timer. Please stop it first.', 400);
        }

        // Get or create current week's timesheet
        $timesheet = Timesheet::currentWeek(Auth::id());
        
        // If the current week's timesheet is already submitted/approved,
        // still allow starting new timers - they'll be linked to this timesheet
        // and can be submitted later (supports multiple submissions per week)

        $user = Auth::user();
        
        $entry = TimeEntry::create([
            'user_id' => Auth::id(),
            'project_id' => $request->project_id,
            'description' => $request->description,
            'started_at' => Carbon::now(),
            'is_billable' => $request->is_billable ?? $user->default_billable ?? true,
            'status' => TimeEntry::STATUS_DRAFT,
            'timesheet_id' => $timesheet->id,
        ]);

        $entry->load('project');

        return $this->created(new TimeEntryResource($entry), 'Timer started');
    }

    /**
     * Stop the current running timer
     */
    public function stop(Request $request)
    {
        $request->validate([
            'description' => 'nullable|string|max:500',
        ]);

        $entry = TimeEntry::forUser(Auth::id())->running()->first();

        if (!$entry) {
            return $this->error('No timer is currently running.', 400);
        }

        // Update description if provided
        if ($request->has('description')) {
            $entry->description = $request->description;
        }

        // Stop the timer
        $entry->stop();
        
        $entry->load('project');

        return $this->success(new TimeEntryResource($entry), 'Timer stopped');
    }

    /**
     * Discard the current running timer
     */
    public function discard()
    {
        $entry = TimeEntry::forUser(Auth::id())->running()->first();

        if (!$entry) {
            return $this->error('No timer is currently running.', 400);
        }

        $entry->delete();

        return $this->success(null, 'Timer discarded');
    }

    /**
     * Quick start - get projects for timer dropdown
     */
    public function projects()
    {
        $user = Auth::user();
        
        // Get projects user has access to (based on role)
        $query = \App\Models\Project::query();
        
        if ($user->role === 'developer') {
            // Developers see projects they're assigned to
            $query->where(function ($q) use ($user) {
                $q->where('developer_id', $user->id)
                  ->orWhere('manager_id', $user->id)
                  ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }
        // Managers and admins see all projects
        
        $projects = $query->select('id', 'name', 'url')
            ->orderBy('name')
            ->get();

        return $this->success($projects);
    }
}
