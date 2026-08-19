<?php

namespace App\Mcp\Resources;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Todo;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use App\Mcp\Concerns\HasRequiredScope;

class DashboardResource extends Resource
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'dashboard';

    protected string $uri = 'lsm://dashboard';

    protected string $description = <<<'MARKDOWN'
        Your LSM dashboard summary including project stats, assigned todos, 
        time tracked today, and unread notifications. This gives you a 
        complete overview of your current status.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        // Get accessible projects based on role
        $projectsQuery = Project::query();

        if ($user->role === 'developer') {
            $projectsQuery->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager' && !$user->canViewAllProjects()) {
            $projectsQuery->where(function($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }
        // Admin sees all

        $projects = $projectsQuery->get();

        // Project stats
        $projectStats = [
            'total' => $projects->count(),
            'online' => $projects->where('health_status', 'online')->count(),
            'offline' => $projects->whereIn('health_status', ['offline', 'down_error'])->count(),
            'maintenance' => $projects->whereIn('health_status', ['maintenance', 'updating'])->count(),
            'at_risk' => $projects->whereIn('security_status', ['at_risk', 'compromised'])->count(),
        ];

        // Todo stats
        $myTodos = Todo::where('assigned_to', $user->id)
            ->where('status', '!=', 'completed')
            ->get();

        $todoStats = [
            'total_pending' => $myTodos->count(),
            'urgent' => $myTodos->where('priority', 'urgent')->count(),
            'high' => $myTodos->where('priority', 'high')->count(),
        ];

        // Time tracking stats
        $todayEntries = TimeEntry::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->get();

        $activeTimer = TimeEntry::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->with('project')
            ->first();

        $timeStats = [
            'today_minutes' => $todayEntries->sum('duration_minutes'),
            'today_formatted' => $this->formatDuration($todayEntries->sum('duration_minutes')),
            'entries_count' => $todayEntries->count(),
            'active_timer' => $activeTimer ? [
                'project' => $activeTimer->project->name,
                'started_at' => $activeTimer->started_at->toIso8601String(),
                'description' => $activeTimer->description,
            ] : null,
        ];

        // Notifications
        $notificationStats = [
            'unread' => $user->unreadNotifications()->count(),
        ];

        $dashboard = [
            'user' => [
                'name' => $user->name,
                'role' => $user->role,
            ],
            'projects' => $projectStats,
            'todos' => $todoStats,
            'time' => $timeStats,
            'notifications' => $notificationStats,
            'generated_at' => now()->toIso8601String(),
        ];

        return Response::json($dashboard);
    }

    private function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%dh %dm', $hours, $mins);
    }
}
