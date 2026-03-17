<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AvailabilityController extends Controller
{
    /**
     * List active availability logs.
     */
    public function index()
    {
        $logs = AvailabilityLog::with(['user', 'setByUser', 'user.assignedProjects', 'user.managedProjects'])
            ->where(function ($query) {
                $query->where('end_date', '>=', now())
                      ->orWhereNull('end_date');
            })
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    /**
     * Create a new availability log.
     * Admins/managers can set for other users by passing user_id.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'status' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'note' => 'nullable|string',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $currentUser = Auth::user();
        $targetUserId = Auth::id();
        $setByUserId = null;

        // If user_id is provided, check that the caller has permission
        if (!empty($validated['user_id']) && $validated['user_id'] != Auth::id()) {
            if (!in_array($currentUser->role, ['admin', 'manager']) && !$currentUser->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins and managers can set availability for other users.'
                ], 403);
            }
            $targetUserId = $validated['user_id'];
            $setByUserId = Auth::id();
        }

        $log = AvailabilityLog::create([
            'user_id' => $targetUserId,
            'set_by_user_id' => $setByUserId,
            'status' => $validated['status'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        $targetUser = $targetUserId == Auth::id() ? $currentUser : User::find($targetUserId);
        $notified = [];

        // Notification logic
        if ($targetUser->role === 'developer') {
            // Notify PMs of projects this developer is on
            $projects = $targetUser->assignedProjects()->with('manager')->get();
            foreach ($projects as $project) {
                if ($project->manager && !in_array($project->manager->email, $notified)) {
                    $notified[] = $project->manager->email;
                }
            }
        } elseif ($targetUser->role === 'manager') {
            // Notify admins
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                if (!in_array($admin->email, $notified)) {
                    $notified[] = $admin->email;
                }
            }
        }

        // High impact: notify admins if many projects affected
        $impactedProjects = $targetUser->assignedProjects()->count() + $targetUser->managedProjects()->count();
        if ($impactedProjects > 3) {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                if (!in_array($admin->email, $notified)) {
                    $notified[] = $admin->email;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $log->load(['user', 'setByUser']),
            'message' => 'Availability logged. Notifications sent to: ' . implode(', ', $notified)
        ]);
    }

    /**
     * Update an existing availability log.
     */
    public function update(Request $request, AvailabilityLog $availability)
    {
        $currentUser = Auth::user();

        // Users can only edit their own logs unless admin/manager
        if ($availability->user_id != Auth::id()) {
            if (!in_array($currentUser->role, ['admin', 'manager']) && !$currentUser->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only edit your own availability logs.'
                ], 403);
            }
        }

        $validated = $request->validate([
            'status' => 'sometimes|required|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'note' => 'nullable|string',
        ]);

        $availability->update($validated);

        return response()->json([
            'success' => true,
            'data' => $availability->load(['user', 'setByUser']),
            'message' => 'Availability updated successfully.'
        ]);
    }

    /**
     * Delete/cancel an availability log.
     */
    public function destroy(AvailabilityLog $availability)
    {
        $currentUser = Auth::user();

        // Users can only delete their own logs unless admin/manager
        if ($availability->user_id != Auth::id()) {
            if (!in_array($currentUser->role, ['admin', 'manager']) && !$currentUser->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only cancel your own availability logs.'
                ], 403);
            }
        }

        $userName = $availability->user->name ?? 'User';
        $availability->delete();

        return response()->json([
            'success' => true,
            'message' => "Availability for {$userName} has been cancelled."
        ]);
    }
}
