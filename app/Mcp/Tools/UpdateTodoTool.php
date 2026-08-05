<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Models\User;
use App\Mcp\Concerns\HasRequiredScope;

class UpdateTodoTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'update-todo';

    protected string $description = <<<'MARKDOWN'
        Update an existing todo. Can change status, priority, assignee, due date, or title/description.
        If new assignee is not on the project team, will warn and optionally add them first.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        if (empty($input['todo_id'])) {
            return Response::error('Todo ID is required.');
        }

        $todo = Todo::with(['project.developers', 'project.manager', 'assignee'])->find($input['todo_id']);

        if (!$todo) {
            return Response::error("Todo with ID {$input['todo_id']} not found.");
        }

        $project = $todo->project;

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this todo.');
        }

        $updates = [];
        $addedToProject = false;

        // Update fields if provided
        if (isset($input['title'])) {
            $todo->title = $input['title'];
            $updates[] = "title → {$input['title']}";
        }

        if (isset($input['description'])) {
            $todo->description = $input['description'];
            $updates[] = "description updated";
        }

        if (isset($input['status'])) {
            $validStatuses = ['pending', 'in_progress', 'completed'];
            if (!in_array($input['status'], $validStatuses)) {
                return Response::error("Invalid status. Use: " . implode(', ', $validStatuses));
            }
            $todo->status = $input['status'];
            $updates[] = "status → {$input['status']}";
        }

        if (isset($input['priority'])) {
            $validPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($input['priority'], $validPriorities)) {
                return Response::error("Invalid priority. Use: " . implode(', ', $validPriorities));
            }
            $todo->priority = $input['priority'];
            $updates[] = "priority → {$input['priority']}";
        }

        // Handle assignee by ID or name
        $newAssignee = null;
        
        if (isset($input['assignee_id'])) {
            $newAssignee = User::find($input['assignee_id']);
            if (!$newAssignee) {
                return Response::error("User with ID {$input['assignee_id']} not found.");
            }
        } elseif (isset($input['assignee_name'])) {
            $newAssignee = User::where('name', 'like', '%' . $input['assignee_name'] . '%')->first();
            if (!$newAssignee) {
                return Response::error("User '{$input['assignee_name']}' not found.");
            }
        }

        // If changing assignee, check project membership
        if ($newAssignee) {
            $isOnProject = $this->isUserOnProject($newAssignee, $project);
            
            if (!$isOnProject) {
                $autoAddToProject = $input['auto_add_to_project'] ?? false;
                
                if (!$autoAddToProject) {
                    // Get current team
                    $developers = $project->developers->pluck('name')->toArray();
                    $developersList = !empty($developers) ? implode(', ', $developers) : 'None';
                    
                    return Response::error(
                        "⚠️ **{$newAssignee->name}** is not assigned to project **{$project->name}**!\n\n" .
                        "**Current project team:**\n" .
                        "- Manager: " . ($project->manager ? $project->manager->name : 'None') . "\n" .
                        "- Developers: {$developersList}\n\n" .
                        "**Options:**\n" .
                        "1. Add {$newAssignee->name} to the project first using `update-project`\n" .
                        "2. Call this tool again with `auto_add_to_project: true` to automatically add them\n" .
                        "3. Assign the todo to someone already on the project team"
                    );
                }
                
                // Auto-add developer to project
                $project->developers()->syncWithoutDetaching([$newAssignee->id]);
                $addedToProject = true;
            }
            
            $todo->assignee_id = $newAssignee->id;
            $updates[] = "assigned to → {$newAssignee->name}";
        }

        if (isset($input['due_date'])) {
            $todo->due_date = $input['due_date'];
            $updates[] = "due date → {$input['due_date']}";
        }

        if (empty($updates)) {
            return Response::error('No updates provided. Specify at least one field to update.');
        }

        $todo->save();
        $todo->refresh();

        $response = "✅ **Todo Updated Successfully**\n\n" .
            "**ID**: {$todo->id}\n" .
            "**Title**: {$todo->title}\n" .
            "**Changes**: " . implode(', ', $updates) . "\n\n" .
            "Current status: {$todo->status} | Priority: {$todo->priority}";

        if ($addedToProject) {
            $response .= "\n\n📌 **Note**: {$newAssignee->name} was automatically added to the project team.";
        }

        return Response::text($response);
    }

    private function isUserOnProject($user, $project): bool
    {
        if ($project->isManagedBy($user)) return true;
        if ($project->developers->contains('id', $user->id)) return true;
        if ($user->role === 'admin') return true;
        return false;
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
            'todo_id' => $schema->integer()
                ->description('The ID of the todo to update')
                ->required(),
            'title' => $schema->string()
                ->description('New title for the todo'),
            'description' => $schema->string()
                ->description('New description for the todo'),
            'status' => $schema->string()
                ->description('New status: pending, in_progress, completed'),
            'priority' => $schema->string()
                ->description('New priority: low, medium, high, urgent'),
            'assignee_id' => $schema->integer()
                ->description('User ID to assign to'),
            'assignee_name' => $schema->string()
                ->description('User name to assign to (will search for matching user)'),
            'due_date' => $schema->string()
                ->description('Due date in YYYY-MM-DD format'),
            'auto_add_to_project' => $schema->boolean()
                ->description('If true and assignee is not on project, automatically add them as developer first'),
        ];
    }
}
