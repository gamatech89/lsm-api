<?php

namespace App\Mcp\Tools;

use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class CompleteTodoTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'complete-todo';

    protected string $description = <<<'MARKDOWN'
        Mark a todo as completed. You can only complete todos that are 
        assigned to you or todos in projects you manage.
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

        // Check if user can complete this todo
        $canComplete = false;
        
        if ($user->role === 'admin') {
            $canComplete = true;
        } elseif ($todo->assignee_id === $user->id) {
            $canComplete = true;
        } elseif ($user->role === 'manager' && $todo->project->isManagedBy($user)) {
            $canComplete = true;
        }

        if (!$canComplete) {
            return Response::error('You do not have permission to complete this todo.');
        }

        // Check if already completed
        if ($todo->status === 'completed') {
            return Response::text("ℹ️ This todo is already completed.");
        }

        // Complete the todo
        $todo->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return Response::text(
            "✅ **Todo Completed!**\n\n" .
            "**Title**: {$todo->title}\n" .
            "**Project**: {$todo->project->name}\n" .
            "**Completed at**: " . now()->format('Y-m-d H:i') . "\n\n" .
            "Great job! 🎉"
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'todo_id' => $schema->integer()
                ->description('The ID of the todo to mark as completed')
                ->required(),
        ];
    }
}
