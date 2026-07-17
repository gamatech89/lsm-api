<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Todo;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Controller
 * 
 * Provides dashboard statistics and recent issues for the API.
 * Reuses the same optimized queries from the web dashboard.
 */
class DashboardController extends Controller
{
    /**
     * Clear cached dashboard statistics.
     * Called from models when project status changes.
     */
    public static function clearCache(): void
    {
        // Clear all dashboard stats caches using pattern
        // Since we cache per user, we need to flush by pattern
        Cache::flush(); // Simple approach - flush all cache
        // For production, consider using tagged caching: Cache::tags('dashboard')->flush();
    }

    /**
     * Get dashboard data including stats and recent issues.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Get stats based on user role
        $stats = $this->getStatsForUser($user);
        
        // Get recent projects with issues
        $recentIssues = $this->getRecentIssuesForUser($user);

        return $this->successResponse([
            'stats' => $stats,
            'recent_issues' => ProjectResource::collection($recentIssues),
        ]);
    }

    /**
     * Get stats overview only (lighter endpoint).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->getStatsForUser($request->user());
        
        return $this->successResponse($stats);
    }

    /**
     * Get stats based on user role.
     * Admin sees all, Manager sees their projects, Developer sees assigned projects.
     */
    private function getStatsForUser($user): array
    {
        $cacheKey = "dashboard_stats_{$user->role}_{$user->id}";
        
        // Cache for 5 minutes
        return Cache::remember($cacheKey, 300, function () use ($user) {
            $query = DB::table('projects')->whereNull('deleted_at');
            
            // Apply role-based filtering
            if ($user->role === 'developer') {
                $query->whereExists(function ($q) use ($user) {
                    $q->select(DB::raw(1))
                      ->from('project_developer')
                      ->whereColumn('project_developer.project_id', 'projects.id')
                      ->where('project_developer.user_id', $user->id);
                });
            } elseif ($user->role === 'manager') {
                $query->where(function($q2) use ($user) {
                    $q2->where('manager_id', $user->id)
                       ->orWhereExists(function ($q3) use ($user) {
                           $q3->select(DB::raw(1))
                              ->from('project_manager')
                              ->whereColumn('project_manager.project_id', 'projects.id')
                              ->where('project_manager.user_id', $user->id);
                       });
                });
            }
            // Admin sees all - no filter
            
            $result = $query->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN health_status IN ('online', 'confirming_down') THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN health_status = 'down_error' THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN health_status = 'updating' THEN 1 ELSE 0 END) as maintenance,
                SUM(CASE WHEN security_status = 'secure' THEN 1 ELSE 0 END) as secure,
                SUM(CASE WHEN security_status = 'monitoring' THEN 1 ELSE 0 END) as monitoring,
                SUM(CASE WHEN security_status IN ('at_risk', 'compromised') THEN 1 ELSE 0 END) as at_risk,
                SUM(CASE WHEN security_status = 'hacked' THEN 1 ELSE 0 END) as hacked
            ")->first();

            // Count open todos
            $todoQuery = Todo::where('status', '!=', 'completed')->whereNull('deleted_at');
            if ($user->role === 'developer') {
                $todoQuery->where('assignee_id', $user->id);
            } elseif ($user->role === 'manager') {
                $todoQuery->whereHas('project', fn($q) => $q->where('manager_id', $user->id)->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id)));
            }
            $openTodos = $todoQuery->count();

            // Count open support tickets
            $ticketQuery = SupportTicket::open();
            if ($user->role === 'developer') {
                $ticketQuery->whereHas('project.developers', fn($q) => $q->where('user_id', $user->id));
            } elseif ($user->role === 'manager') {
                $ticketQuery->whereHas('project', fn($q) => $q->where('manager_id', $user->id)->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id)));
            }
            $openTickets = $ticketQuery->count();

            return [
                'total' => (int) $result->total,
                'online' => (int) $result->online,
                'offline' => (int) $result->offline,
                'maintenance' => (int) $result->maintenance,
                'secure' => (int) $result->secure,
                'monitoring' => (int) $result->monitoring,
                'at_risk' => (int) $result->at_risk,
                'hacked' => (int) $result->hacked,
                'open_todos' => $openTodos,
                'open_tickets' => $openTickets,
            ];
        });
    }

    /**
     * Get recent projects with issues based on user role.
     */
    private function getRecentIssuesForUser($user)
    {
        $query = Project::where(function ($q) {
            $q->where('health_status', '!=', 'online')
              ->orWhere('security_status', 'hacked')
              ->orWhereIn('security_status', ['at_risk', 'compromised']);
        });

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $query->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager') {
            $query->where(function($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }
        // Admin sees all

        return $query
            ->select(['id', 'name', 'url', 'health_status', 'security_status', 'updated_at'])
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();
    }
}
