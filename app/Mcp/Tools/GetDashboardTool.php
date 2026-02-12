<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDashboardTool extends Tool
{
    protected string $name = 'get-dashboard';

    protected string $description = <<<'MARKDOWN'
        Get your dashboard summary with project stats, pending todos, time tracked 
        today, and notifications. This is a great starting point to understand your 
        current status.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();

        // Get accessible projects based on role
        $projectsQuery = Project::query();

        if ($user->role === 'developer') {
            $projectsQuery->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager') {
            $projectsQuery->where(function($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }

        $projects = $projectsQuery->get();

        // Build summary
        $summary = [
            'user' => "{$user->name} ({$user->role})",
            'projects' => [
                'total' => $projects->count(),
                'online' => $projects->where('health_status', 'online')->count(),
                'issues' => $projects->whereIn('health_status', ['offline', 'down_error'])->count()
                    + $projects->whereIn('security_status', ['at_risk', 'compromised', 'hacked'])->count(),
            ],
            'todos' => [
                'pending' => Todo::where('assignee_id', $user->id)
                    ->where('status', '!=', 'completed')
                    ->count(),
                'urgent' => Todo::where('assignee_id', $user->id)
                    ->where('status', '!=', 'completed')
                    ->where('priority', 'urgent')
                    ->count(),
            ],
            'time_today' => [
                'minutes' => TimeEntry::where('user_id', $user->id)
                    ->whereDate('started_at', today())
                    ->sum('duration_minutes'),
                'has_active_timer' => TimeEntry::where('user_id', $user->id)
                    ->whereNull('ended_at')
                    ->exists(),
            ],
            'notifications_unread' => $user->unreadNotifications()->count(),
        ];

        $text = "📊 **Dashboard for {$summary['user']}**\n\n";
        $text .= "**Projects**: {$summary['projects']['total']} total, ";
        $text .= "{$summary['projects']['online']} online, ";
        $text .= "{$summary['projects']['issues']} with issues\n\n";
        $text .= "**Todos**: {$summary['todos']['pending']} pending";
        if ($summary['todos']['urgent'] > 0) {
            $text .= " ({$summary['todos']['urgent']} urgent!)";
        }
        $text .= "\n\n";
        $text .= "**Time Today**: " . floor($summary['time_today']['minutes'] / 60) . "h " 
            . ($summary['time_today']['minutes'] % 60) . "m";
        if ($summary['time_today']['has_active_timer']) {
            $text .= " (timer running)";
        }
        $text .= "\n\n";
        $text .= "**Notifications**: {$summary['notifications_unread']} unread";

        return Response::text($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
