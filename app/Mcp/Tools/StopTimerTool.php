<?php

namespace App\Mcp\Tools;

use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class StopTimerTool extends Tool
{
    protected string $name = 'stop-timer';

    protected string $description = <<<'MARKDOWN'
        Stop the currently running timer and save the time entry.
        Optionally add notes about the work completed.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        // Find running timer
        $timer = TimeEntry::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->with(['project:id,name', 'todo:id,title'])
            ->first();

        if (!$timer) {
            return Response::text("ℹ️ No active timer running. Use `start-timer` to begin tracking time.");
        }

        // Calculate duration
        $duration = $timer->started_at->diffInMinutes(now());

        // Update timer
        $timer->update([
            'ended_at' => now(),
            'duration_minutes' => $duration,
            'description' => !empty($input['notes']) 
                ? ($timer->description ? $timer->description . ' - ' . $input['notes'] : $input['notes'])
                : $timer->description,
        ]);

        $hours = floor($duration / 60);
        $mins = $duration % 60;
        $formattedDuration = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";

        $text = "⏹️ **Timer Stopped**\n\n";
        $text .= "**Project**: {$timer->project->name}\n";
        if ($timer->todo) {
            $text .= "**Todo**: {$timer->todo->title}\n";
        }
        $text .= "**Duration**: {$formattedDuration}\n";
        $text .= "**Started**: {$timer->started_at->format('H:i')}\n";
        $text .= "**Ended**: " . now()->format('H:i') . "\n";
        
        if ($timer->description) {
            $text .= "**Notes**: {$timer->description}\n";
        }

        // Show today's total
        $todayTotal = TimeEntry::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->whereNotNull('ended_at')
            ->sum('duration_minutes');

        $todayHours = floor($todayTotal / 60);
        $todayMins = $todayTotal % 60;

        $text .= "\n📊 **Today's total**: {$todayHours}h {$todayMins}m";

        return Response::text($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'notes' => $schema->string()
                ->description('Optional notes about the work completed'),
        ];
    }
}
