<?php

namespace App\Mcp\Tools;

use App\Models\TimeEntry;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class CreateTimeEntryTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'create-time-entry';

    protected string $description = <<<'MARKDOWN'
        Manually create a time entry. Requires project, date, and duration.
        Use this for adding past time entries, not for current tracking (use start-timer instead).
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        $project = Project::find($input['project_id']);
        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Duration must be provided
        if (empty($input['duration_minutes']) && empty($input['hours'])) {
            return Response::error('Duration is required. Provide duration_minutes or hours.');
        }

        $durationMinutes = $input['duration_minutes'] ?? ($input['hours'] * 60);

        // Date defaults to today
        $date = !empty($input['date']) ? Carbon::parse($input['date']) : Carbon::today();

        // Start/end times
        $startTime = $input['start_time'] ?? '09:00';
        
        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'todo_id' => $input['todo_id'] ?? null,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => Carbon::parse($startTime)->addMinutes($durationMinutes)->format('H:i'),
            'duration_minutes' => $durationMinutes,
            'description' => $input['description'] ?? null,
            'billable' => $input['billable'] ?? true,
        ]);

        $hours = floor($durationMinutes / 60);
        $mins = $durationMinutes % 60;

        return Response::text(
            "✅ **Time Entry Created**\n\n" .
            "**ID**: {$entry->id}\n" .
            "**Project**: {$project->name}\n" .
            "**Date**: {$date->format('Y-m-d')}\n" .
            "**Duration**: {$hours}h {$mins}m\n" .
            ($entry->description ? "**Description**: {$entry->description}\n" : "") .
            "**Billable**: " . ($entry->billable ? 'Yes' : 'No')
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project ID to log time for')
                ->required(),
            'duration_minutes' => $schema->integer()
                ->description('Duration in minutes'),
            'hours' => $schema->number()
                ->description('Duration in hours (alternative to duration_minutes)'),
            'date' => $schema->string()
                ->description('Date of work (YYYY-MM-DD, defaults to today)'),
            'start_time' => $schema->string()
                ->description('Start time (HH:MM, defaults to 09:00)'),
            'description' => $schema->string()
                ->description('What was worked on'),
            'todo_id' => $schema->integer()
                ->description('Link to a specific todo'),
            'billable' => $schema->boolean()
                ->description('Is this billable time (default: true)'),
        ];
    }
}
