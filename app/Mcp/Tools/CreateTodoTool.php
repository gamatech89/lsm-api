<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateTodoTool extends Tool
{
    protected string $name = 'create-todo';

    protected string $description = <<<'MARKDOWN'
        Create a new todo for a project. Requires project ID and title.
        Optionally set priority (default: medium), description, and assignee (by ID or name).
        If assignee is not on the project team, will warn and optionally add them first.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        if (empty($input['title'])) {
            return Response::error('Title is required.');
        }

        $project = Project::with(['developers', 'manager'])->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        // Determine assignee - by ID, by name, or default to current user
        $assigneeId = $user->id;
        $assigneeName = 'you';
        $assignee = null;
        $addedToProject = false;

        if (!empty($input['assignee_id'])) {
            $assignee = User::find($input['assignee_id']);
            if ($assignee) {
                $assigneeId = $assignee->id;
                $assigneeName = $assignee->name;
            }
        } elseif (!empty($input['assignee_name'])) {
            $assignee = User::where('name', 'like', '%' . $input['assignee_name'] . '%')->first();
            if ($assignee) {
                $assigneeId = $assignee->id;
                $assigneeName = $assignee->name;
            } else {
                return Response::error("User '{$input['assignee_name']}' not found.");
            }
        }

        // Check if assignee is on the project team
        if ($assignee && $assignee->id !== $user->id) {
            $isOnProject = $this->isUserOnProject($assignee, $project);
            
            if (!$isOnProject) {
                // Check if we should auto-add them
                $autoAddToProject = $input['auto_add_to_project'] ?? false;
                
                if (!$autoAddToProject) {
                    // Return a helpful message asking if they want to add the person
                    $currentTeam = $this->getProjectTeamNames($project);
                    
                    return Response::error(
                        "⚠️ **{$assignee->name}** is not assigned to project **{$project->name}**!\n\n" .
                        "**Current project team:**\n" .
                        "- Manager: " . ($project->manager ? $project->manager->name : 'None') . "\n" .
                        "- Developers: " . ($currentTeam ?: 'None') . "\n\n" .
                        "**Options:**\n" .
                        "1. Use `update-project` to add {$assignee->name} as a developer first, then create the todo\n" .
                        "2. Call this tool again with `auto_add_to_project: true` to automatically add them\n" .
                        "3. Assign the todo to someone already on the project team"
                    );
                }
                
                // Auto-add developer to project
                $project->developers()->syncWithoutDetaching([$assignee->id]);
                $addedToProject = true;
            }
        }

        // Create the todo
        $todo = Todo::create([
            'project_id' => $project->id,
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'priority' => $input['priority'] ?? 'medium',
            'status' => 'pending',
            'assignee_id' => $assigneeId,
            'created_by' => $user->id,
        ]);

        $priorityEmoji = match ($todo->priority) {
            'urgent' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };

        $response = "✅ **Todo Created Successfully**\n\n" .
            "• **Project**: {$project->name} (ID: {$project->id})\n" .
            "• **Title**: {$todo->title}\n" .
            "• **Priority**: {$priorityEmoji} " . ucfirst($todo->priority) . "\n" .
            "• **Assigned to**: {$assigneeName}\n" .
            "• **Status**: Pending\n\n" .
            "Todo ID: {$todo->id}";

        if ($addedToProject) {
            $response .= "\n\n📌 **Note**: {$assigneeName} was automatically added to the project team.";
        }

        return Response::text($response);
    }

    private function isUserOnProject($user, $project): bool
    {
        // Check if user is manager
        if ($project->manager_id === $user->id) {
            return true;
        }
        
        // Check if user is a developer on the project
        if ($project->developers->contains('id', $user->id)) {
            return true;
        }
        
        // Admins can be assigned to anything
        if ($user->role === 'admin') {
            return true;
        }
        
        return false;
    }

    private function getProjectTeamNames($project): string
    {
        $developers = $project->developers->pluck('name')->toArray();
        return !empty($developers) ? implode(', ', $developers) : '';
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->manager_id === $user->id) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the project to create the todo in')
                ->required(),
            'title' => $schema->string()
                ->description('The title/name of the todo')
                ->required(),
            'description' => $schema->string()
                ->description('Detailed description of the todo'),
            'priority' => $schema->string()
                ->description('Priority level: low, medium, high, urgent (default: medium)'),
            'assignee_id' => $schema->integer()
                ->description('User ID to assign the todo to (default: yourself)'),
            'assignee_name' => $schema->string()
                ->description('User name to assign the todo to (will search for matching user)'),
            'auto_add_to_project' => $schema->boolean()
                ->description('If true and assignee is not on project, automatically add them as developer first'),
        ];
    }
}
