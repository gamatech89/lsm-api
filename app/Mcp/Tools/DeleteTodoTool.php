<?php

namespace App\Mcp\Tools;

use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class DeleteTodoTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'delete-todo';

    protected string $description = <<<'MARKDOWN'
        Delete a todo by ID. This action is permanent.
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

        $todo = Todo::with('project')->find($input['todo_id']);

        if (!$todo) {
            return Response::error("Todo with ID {$input['todo_id']} not found.");
        }

        // Check access
        if (!$this->canAccessProject($user, $todo->project)) {
            return Response::error('You do not have access to delete this todo.');
        }

        $todoTitle = $todo->title;
        $projectName = $todo->project->name ?? 'Unknown';

        $todo->delete();

        return Response::text(
            "🗑️ **Todo Deleted**\n\n" .
            "Deleted: \"{$todoTitle}\"\n" .
            "From project: {$projectName}"
        );
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'todo_id' => $schema->integer()
                ->description('The ID of the todo to delete')
                ->required(),
        ];
    }
}
