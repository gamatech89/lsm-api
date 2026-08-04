<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class ListTodoTemplatesTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'list-todo-templates';

    protected string $description = <<<'MARKDOWN'
        List available todo templates. Templates are predefined sets of todos 
        that can be applied to any project (e.g., maintenance, security audit).
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $templates = config('todo_templates', []);

        if (empty($templates)) {
            return Response::text("No todo templates are configured.");
        }

        $lines = ["**📋 Available Todo Templates**\n"];

        foreach ($templates as $key => $template) {
            $todoCount = count($template['todos'] ?? []);
            $lines[] = "### `{$key}` — {$template['name']}";
            $lines[] = $template['description'] ?? '';
            $lines[] = "**{$todoCount} todos**:";
            
            foreach ($template['todos'] as $todo) {
                $priorityEmoji = match ($todo['priority'] ?? 'medium') {
                    'urgent' => '🔴',
                    'critical' => '🔴',
                    'high' => '🟠',
                    'medium' => '🟡',
                    'low' => '🟢',
                    default => '⚪',
                };
                $lines[] = "  {$priorityEmoji} {$todo['title']}";
            }
            $lines[] = "";
        }

        $lines[] = "---";
        $lines[] = "Use `apply-todo-template` with a project ID and template name to add these todos.";

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
