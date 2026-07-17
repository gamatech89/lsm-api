<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhpError;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PHP Error Controller
 * 
 * Handles PHP error log management for WordPress sites.
 */
class PhpErrorController extends Controller
{
    /**
     * List all PHP errors for a project.
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('view', $project);

        $query = $project->phpErrors()
            ->unresolved()
            ->recent();

        // Filter by type
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                  ->orWhere('file', 'like', "%{$search}%");
            });
        }

        $errors = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $errors,
        ]);
    }

    /**
     * Get a single PHP error.
     */
    public function show(PhpError $phpError): JsonResponse
    {
        // Shallow route — the project is derived from the error.
        Gate::authorize('view', $phpError->project);

        return response()->json([
            'success' => true,
            'data' => $phpError,
        ]);
    }

    /**
     * Log a new PHP error (called from WordPress RMB plugin).
     */
    public function store(Project $project, Request $request): JsonResponse
    {
        // This endpoint may be called by the WordPress plugin, so we verify via secret.
        // X-LSM-Key is the standard plugin header; X-Health-Check-Secret is legacy.
        $secret = $request->header('X-LSM-Key') ?? $request->header('X-Health-Check-Secret');
        $expected = $project->health_check_secret;

        if (!$secret || !$expected || !hash_equals($expected, $secret)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'type' => 'required|in:fatal,warning,notice,deprecated,parse',
            'message' => 'required|string',
            'file' => 'nullable|string',
            'line' => 'nullable|integer',
            'wordpress_version' => 'nullable|string',
            'php_version' => 'nullable|string',
            'plugin_slug' => 'nullable|string',
            'theme_slug' => 'nullable|string',
        ]);

        $error = PhpError::logError(
            $project->id,
            $validated['type'],
            $validated['message'],
            $validated['file'] ?? null,
            $validated['line'] ?? null,
            [
                'wordpress_version' => $validated['wordpress_version'] ?? null,
                'php_version' => $validated['php_version'] ?? null,
                'plugin_slug' => $validated['plugin_slug'] ?? null,
                'theme_slug' => $validated['theme_slug'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Error logged',
            'data' => $error,
        ], 201);
    }

    /**
     * Mark an error as resolved.
     */
    public function resolve(PhpError $phpError): JsonResponse
    {
        // Shallow route — the project is derived from the error.
        Gate::authorize('update', $phpError->project);

        $phpError->markResolved(auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Error marked as resolved',
            'data' => $phpError,
        ]);
    }

    /**
     * Delete a specific error.
     */
    public function destroy(PhpError $phpError): JsonResponse
    {
        // Shallow route — the project is derived from the error.
        Gate::authorize('update', $phpError->project);

        $phpError->delete();

        return response()->json([
            'success' => true,
            'message' => 'Error deleted',
        ]);
    }

    /**
     * Clear all errors for a project.
     */
    public function clear(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $count = $project->phpErrors()->delete();

        return response()->json([
            'success' => true,
            'message' => "{$count} errors cleared",
        ]);
    }

    /**
     * Get error statistics for a project.
     */
    public function stats(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $stats = [
            'total' => $project->phpErrors()->count(),
            'unresolved' => $project->phpErrors()->unresolved()->count(),
            'by_type' => [
                'fatal' => $project->phpErrors()->unresolved()->ofType('fatal')->count(),
                'warning' => $project->phpErrors()->unresolved()->ofType('warning')->count(),
                'notice' => $project->phpErrors()->unresolved()->ofType('notice')->count(),
                'deprecated' => $project->phpErrors()->unresolved()->ofType('deprecated')->count(),
            ],
            'recent_24h' => $project->phpErrors()
                ->where('last_seen_at', '>=', now()->subDay())
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
