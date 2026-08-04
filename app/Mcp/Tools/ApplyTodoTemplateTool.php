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
use App\Mcp\Concerns\HasRequiredScope;

class ApplyTodoTemplateTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'apply-todo-template';

    protected string $description = <<<'MARKDOWN'
        Apply a predefined todo template to a project. Creates all todos from the template.
        Available templates: maintenance, security_audit, onboarding, performance.
        Optionally auto-assign to a specific user or the least busy developer.
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

        if (empty($input['template'])) {
            return Response::error('Template name is required. Use list-todo-templates to see available templates.');
        }

        $project = Project::find($input['project_id']);
        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Get template
        $templates = config('todo_templates', []);
        $templateKey = strtolower($input['template']);
        
        if (!isset($templates[$templateKey])) {
            $available = implode(', ', array_keys($templates));
            return Response::error("Template '{$input['template']}' not found. Available: {$available}");
        }

        $template = $templates[$templateKey];
        $todos = $template['todos'] ?? [];

        if (empty($todos)) {
            return Response::error("Template '{$templateKey}' has no todos defined.");
        }

        // Determine assignee
        $assigneeId = $user->id;
        $assigneeName = 'you';

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
            }
        } elseif (!empty($input['auto_assign']) && $input['auto_assign']) {
            // Find least busy developer
            $leastBusy = $this->findLeastBusyDeveloper();
            if ($leastBusy) {
                $assigneeId = $leastBusy->id;
                $assigneeName = $leastBusy->name . ' (auto-assigned, least busy)';
            }
        }

        // Create todos
        $createdTodos = [];
        foreach ($todos as $todoData) {
            $todo = Todo::create([
                'project_id' => $project->id,
                'title' => $todoData['title'],
                'description' => $todoData['description'] ?? null,
                'priority' => $todoData['priority'] ?? 'medium',
                'status' => 'pending',
                'assignee_id' => $assigneeId,
            ]);
            $createdTodos[] = $todo;
        }

        $lines = [
            "✅ **Template '{$template['name']}' Applied!**\n",
            "**Project**: {$project->name} (ID: {$project->id})",
            "**Created**: " . count($createdTodos) . " todos",
            "**Assigned to**: {$assigneeName}\n",
            "**Todos created:**",
        ];

        foreach ($createdTodos as $todo) {
            $priorityEmoji = match ($todo->priority) {
                'urgent' => '🔴',
                'critical' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪',
            };
            $lines[] = "  {$priorityEmoji} {$todo->title} (ID: {$todo->id})";
        }

        return Response::text(implode("\n", $lines));
    }

    private function findLeastBusyDeveloper(): ?User
    {
        return User::whereIn('role', ['developer', 'manager'])
            ->where('is_active', true)
            ->withCount(['todos' => function ($q) {
                $q->where('status', '!=', 'completed');
            }])
            ->orderBy('todos_count', 'asc')
            ->first();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project ID to apply template to')
                ->required(),
            'template' => $schema->string()
                ->description('Template name: maintenance, security_audit, onboarding, performance')
                ->required(),
            'assignee_id' => $schema->integer()
                ->description('User ID to assign all todos to'),
            'assignee_name' => $schema->string()
                ->description('User name to assign all todos to'),
            'auto_assign' => $schema->boolean()
                ->description('If true, auto-assign to the developer with fewest active todos'),
        ];
    }
}
