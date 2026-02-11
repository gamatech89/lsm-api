<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\TimeEntry;
use App\Models\Availability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetTeamAvailabilityTool extends Tool
{
    protected string $name = 'get-team-availability';

    protected string $description = <<<'MARKDOWN'
        Check team availability. Shows who is currently tracking time (working), 
        who hasn't started yet today, and who is on vacation/unavailable.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();

        // Only admins and managers can view team availability
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can view team availability.');
        }

        $developers = User::whereIn('role', ['developer', 'manager', 'admin'])
            ->where('is_active', true)
            ->get();

        $working = [];
        $notWorking = [];
        $unavailable = [];

        foreach ($developers as $dev) {
            // Check if currently tracking time
            $activeTimer = TimeEntry::where('user_id', $dev->id)
                ->whereNull('ended_at')
                ->with('project')
                ->first();

            // Check if on vacation/unavailable
            $isUnavailable = false;
            if (class_exists(Availability::class)) {
                $isUnavailable = Availability::where('user_id', $dev->id)
                    ->where('date', Carbon::today())
                    ->where('status', '!=', 'available')
                    ->exists();
            }

            // Time logged today
            $todayMinutes = TimeEntry::where('user_id', $dev->id)
                ->whereDate('date', Carbon::today())
                ->sum('duration_minutes');

            if ($isUnavailable) {
                $unavailable[] = $dev;
            } elseif ($activeTimer) {
                $working[] = [
                    'user' => $dev,
                    'project' => $activeTimer->project?->name ?? 'Unknown',
                    'started' => Carbon::parse($activeTimer->started_at)->diffForHumans(),
                ];
            } else {
                $notWorking[] = [
                    'user' => $dev,
                    'today_minutes' => $todayMinutes,
                ];
            }
        }

        $lines = ["**📊 Team Availability**\n"];

        // Currently working
        if (count($working) > 0) {
            $lines[] = "### 🟢 Currently Working (" . count($working) . ")";
            foreach ($working as $w) {
                $lines[] = "• **{$w['user']->name}** — on {$w['project']} (started {$w['started']})";
            }
            $lines[] = "";
        }

        // Not tracking
        if (count($notWorking) > 0) {
            $lines[] = "### ⚪ Not Tracking Time (" . count($notWorking) . ")";
            foreach ($notWorking as $n) {
                $todayStr = $n['today_minutes'] > 0 
                    ? sprintf("logged %sh today", round($n['today_minutes'] / 60, 1))
                    : "no time logged today";
                $lines[] = "• **{$n['user']->name}** — {$todayStr}";
            }
            $lines[] = "";
        }

        // Unavailable
        if (count($unavailable) > 0) {
            $lines[] = "### 🔴 Unavailable (" . count($unavailable) . ")";
            foreach ($unavailable as $u) {
                $lines[] = "• **{$u->name}** — on leave";
            }
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
