<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class ListTodosTool extends Tool
{
    use HasRequiredScope;

    protected string $requiredScope = 'mcp:read';

    protected string $name = 'list-todos';

    protected string $description = <<<'MARKDOWN'
        List todos. By default shows your assigned pending todos across all projects.
        You can filter by project, status, or priority.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        $query = Todo::with(['project:id,name', 'assignee:id,name'])
            ->select(['id', 'project_id', 'title', 'priority', 'status', 'assignee_id', 'created_at']);

        // By default, show assigned todos
        if (empty($input['all'])) {
            $query->where('assignee_id', $user->id);
        } else {
            // If "all", filter by accessible projects
            if ($user->role === 'developer') {
                $query->whereHas('project.developers', fn($q) => $q->where('user_id', $user->id));
            } elseif ($user->role === 'manager') {
                $query->whereHas('project', fn($q) => $q->where('manager_id', $user->id)->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id)));
            }
        }

        // Filter by project
        if (!empty($input['project_id'])) {
            $query->where('project_id', $input['project_id']);
        }

        // Filter by status
        if (!empty($input['status'])) {
            $query->where('status', $input['status']);
        } else {
            // Default to non-completed
            $query->where('status', '!=', 'completed');
        }

        // Filter by priority
        if (!empty($input['priority'])) {
            $query->where('priority', $input['priority']);
        }

        $todos = $query
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        if ($todos->isEmpty()) {
            return Response::text("No todos found matching your criteria. 🎉");
        }

        $text = "**Todos ({$todos->count()}):**\n\n";

        foreach ($todos as $todo) {
            $priorityEmoji = match($todo->priority) {
                'urgent' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪',
            };

            $statusEmoji = match($todo->status) {
                'completed' => '✅',
                'in_progress' => '🔄',
                default => '⬜',
            };

            $text .= "{$priorityEmoji}{$statusEmoji} **{$todo->title}** (ID: {$todo->id})\n";
            $text .= "   Project: {$todo->project->name} | ";
            $text .= "Priority: {$todo->priority} | Status: {$todo->status}\n";
            if ($todo->assignee && $todo->assignee_id !== $user->id) {
                $text .= "   Assigned to: {$todo->assignee->name}\n";
            }
            $text .= "\n";
        }

        return Response::text($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Filter todos by project ID'),
            'status' => $schema->string()
                ->description('Filter by status: pending, in_progress, completed'),
            'priority' => $schema->string()
                ->description('Filter by priority: low, medium, high, urgent'),
            'all' => $schema->boolean()
                ->description('If true, show all todos in accessible projects, not just assigned to you'),
        ];
    }
}
