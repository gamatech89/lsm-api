<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Activity Controller
 * 
 * Provides access to the activity log for admin users.
 */
class ActivityController extends Controller
{
    /**
     * Display a paginated listing of activity logs.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer:id,name,email')
            ->latest();

        // Filter by subject type
        if ($request->filled('subject_type')) {
            $subjectType = $request->subject_type;
            // Convert short names to full class names
            $typeMap = [
                'project' => 'App\\Models\\Project',
                'credential' => 'App\\Models\\Credential',
                'user' => 'App\\Models\\User',
                'todo' => 'App\\Models\\Todo',
            ];
            $query->where('subject_type', $typeMap[$subjectType] ?? $subjectType);
        }

        // Filter by causer (user)
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        // Filter by date range
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // Search in description
        if ($request->filled('search')) {
            $query->where('description', 'like', "%{$request->search}%");
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $activities = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $activities->map(fn($a) => [
                'id' => $a->id,
                'description' => $a->description,
                'subject_type' => class_basename($a->subject_type),
                'subject_id' => $a->subject_id,
                'causer' => $a->causer ? [
                    'id' => $a->causer->id,
                    'name' => $a->causer->name,
                    'email' => $a->causer->email,
                ] : null,
                'properties' => $a->properties,
                'created_at' => $a->created_at->toISOString(),
            ]),
            'current_page' => $activities->currentPage(),
            'last_page' => $activities->lastPage(),
            'per_page' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }
}
