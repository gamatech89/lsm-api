<?php

namespace App\Mcp\Resources;

use App\Models\TimeEntry;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use App\Mcp\Concerns\HasRequiredScope;

class TimeTodayResource extends Resource
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'time-today';

    protected string $uri = 'lsm://time/today';

    protected string $description = <<<'MARKDOWN'
        Your time entries for today, including any currently active timer.
        Shows total time logged and breakdown by project.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        // Get today's entries
        $entries = TimeEntry::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->with(['project:id,name', 'todo:id,title'])
            ->orderBy('started_at', 'desc')
            ->get();

        // Find active timer
        $activeTimer = $entries->whereNull('ended_at')->first();

        // Calculate totals
        $completedMinutes = $entries->whereNotNull('ended_at')->sum('duration_minutes');
        
        // Group by project
        $byProject = $entries->groupBy('project.name')->map(function ($projectEntries) {
            return [
                'project' => $projectEntries->first()->project->name,
                'entries' => $projectEntries->count(),
                'total_minutes' => $projectEntries->sum('duration_minutes'),
                'formatted' => $this->formatDuration($projectEntries->sum('duration_minutes')),
            ];
        })->values();

        $data = [
            'date' => today()->toDateString(),
            'total_minutes' => $completedMinutes,
            'total_formatted' => $this->formatDuration($completedMinutes),
            'entries_count' => $entries->count(),
            'active_timer' => $activeTimer ? [
                'id' => $activeTimer->id,
                'project' => $activeTimer->project->name,
                'todo' => $activeTimer->todo?->title,
                'description' => $activeTimer->description,
                'started_at' => $activeTimer->started_at->toIso8601String(),
                'running_minutes' => $activeTimer->started_at->diffInMinutes(now()),
            ] : null,
            'by_project' => $byProject,
            'entries' => $entries->map(fn($e) => [
                'id' => $e->id,
                'project' => $e->project->name,
                'todo' => $e->todo?->title,
                'description' => $e->description,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_minutes' => $e->duration_minutes,
                'is_running' => $e->ended_at === null,
            ]),
        ];

        return Response::json($data);
    }

    private function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%dh %dm', $hours, $mins);
    }
}
