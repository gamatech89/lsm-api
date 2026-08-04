<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class StartTimerTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'start-timer';

    protected string $description = <<<'MARKDOWN'
        Start tracking time for a project. Optionally link to a specific todo.
        Only one timer can be active at a time - starting a new timer will 
        automatically stop any running timer.
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

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        // Check for existing timer and stop it
        $existingTimer = TimeEntry::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if ($existingTimer) {
            $duration = $existingTimer->started_at->diffInMinutes(now());
            $existingTimer->update([
                'ended_at' => now(),
                'duration_minutes' => $duration,
            ]);
        }

        // Validate todo if provided
        $todo = null;
        if (!empty($input['todo_id'])) {
            $todo = Todo::where('id', $input['todo_id'])
                ->where('project_id', $project->id)
                ->first();

            if (!$todo) {
                return Response::error("Todo with ID {$input['todo_id']} not found in this project.");
            }
        }

        // Create new time entry
        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'todo_id' => $todo?->id,
            'description' => $input['description'] ?? null,
            'started_at' => now(),
            'billable' => true,
        ]);

        $text = "⏱️ **Timer Started**\n\n";
        $text .= "**Project**: {$project->name}\n";
        if ($todo) {
            $text .= "**Todo**: {$todo->title}\n";
        }
        if (!empty($input['description'])) {
            $text .= "**Description**: {$input['description']}\n";
        }
        $text .= "**Started at**: " . now()->format('H:i') . "\n";

        if ($existingTimer) {
            $text .= "\n*Previous timer stopped ({$duration} minutes logged).*";
        }

        $text .= "\n\nUse `stop-timer` when you're done.";

        return Response::text($text);
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the project to track time for')
                ->required(),
            'todo_id' => $schema->integer()
                ->description('Optional: Link the time entry to a specific todo'),
            'description' => $schema->string()
                ->description('What you are working on'),
        ];
    }
}
