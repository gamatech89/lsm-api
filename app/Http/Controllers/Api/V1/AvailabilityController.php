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
        $logs = AvailabilityLog::with(['user', 'user.assignedProjects', 'user.managedProjects'])
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
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'status' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'note' => 'nullable|string'
        ]);

        $log = AvailabilityLog::create([
            'user_id' => Auth::id(),
            'status' => $validated['status'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        $user = Auth::user();
        $notified = [];

        // Logic 1: Developer -> Notify PMs
        if ($user->role === 'developer') {
            $projects = $user->assignedProjects()->with('manager')->get();
            foreach ($projects as $project) {
                if ($project->manager && !in_array($project->manager->email, $notified)) {
                    $notified[] = $project->manager->email;
                }
            }
        } 
        // Logic 2: Manager -> Notify Admins
        elseif ($user->role === 'manager') {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                if (!in_array($admin->email, $notified)) {
                    $notified[] = $admin->email;
                }
            }
        }

        // Logic 3: High Impact -> Notify Admins
        // If developer has > 3 projects or PM manages > 5 projects
        $impactedProjects = $user->assignedProjects()->count() + $user->managedProjects()->count();
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
            'data' => $log,
            'message' => 'Availability logged. Notifications sent to: ' . implode(', ', $notified)
        ]);
    }
}
