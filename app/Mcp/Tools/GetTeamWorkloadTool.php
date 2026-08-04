<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\Todo;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class GetTeamWorkloadTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'get-team-workload';

    protected string $description = <<<'MARKDOWN'
        Get team workload statistics. Shows active todos, time tracked, and helps identify 
        who has the least work. Use this to find the "least busy" dev for assignment.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        // Only admins and managers can view team workload
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can view team workload.');
        }

        $developers = User::whereIn('role', ['developer', 'manager'])
            ->where('is_active', true)
            ->get();

        $workloads = [];

        foreach ($developers as $dev) {
            // Active (non-completed) todos assigned
            $activeTodos = Todo::where('assignee_id', $dev->id)
                ->where('status', '!=', 'completed')
                ->count();

            $urgentTodos = Todo::where('assignee_id', $dev->id)
                ->where('status', '!=', 'completed')
                ->where('priority', 'urgent')
                ->count();

            // Time tracked this week
            $weeklyMinutes = TimeEntry::where('user_id', $dev->id)
                ->whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
                ->sum('duration_minutes');

            // Currently has active timer
            $hasActiveTimer = TimeEntry::where('user_id', $dev->id)
                ->whereNull('ended_at')
                ->exists();

            $workloads[] = [
                'user' => $dev,
                'active_todos' => $activeTodos,
                'urgent_todos' => $urgentTodos,
                'weekly_hours' => round($weeklyMinutes / 60, 1),
                'is_tracking' => $hasActiveTimer,
            ];
        }

        // Sort by active todos (ascending) to find least busy
        usort($workloads, fn($a, $b) => $a['active_todos'] <=> $b['active_todos']);

        $leastBusy = $workloads[0] ?? null;

        $lines = ["**👥 Team Workload**\n"];

        foreach ($workloads as $w) {
            $trackingIcon = $w['is_tracking'] ? '🟢' : '⚪';
            $urgentBadge = $w['urgent_todos'] > 0 ? " 🔴{$w['urgent_todos']}" : '';
            
            $lines[] = sprintf(
                "%s **%s** (ID: %d)\n   📋 %d active todos%s | ⏱️ %sh this week",
                $trackingIcon,
                $w['user']->name,
                $w['user']->id,
                $w['active_todos'],
                $urgentBadge,
                $w['weekly_hours']
            );
        }

        if ($leastBusy) {
            $lines[] = "\n---\n**💡 Least busy**: {$leastBusy['user']->name} (ID: {$leastBusy['user']->id}) with {$leastBusy['active_todos']} active todos";
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
