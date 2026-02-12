<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetProjectTool extends Tool
{
    protected string $name = 'get-project';

    protected string $description = <<<'MARKDOWN'
        Get detailed information about a specific WordPress project including 
        health status, WordPress version, recent todos, and credential labels.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        $project = Project::with(['manager:id,name', 'developers:id,name', 'tags:id,name'])
            ->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Check access
        if ($user->role === 'developer') {
            if (!$project->developers->contains('id', $user->id)) {
                return Response::error('You do not have access to this project.');
            }
        } elseif ($user->role === 'manager') {
            if (!$project->managers->contains('id', $user->id)) {
                return Response::error('You do not have access to this project.');
            }
        }

        // Get recent todos
        $pendingTodos = $project->todos()
            ->where('status', '!=', 'completed')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(5)
            ->get(['id', 'title', 'priority', 'status']);

        // Get credential labels (not passwords)
        $credentials = $project->credentials()->get(['id', 'label', 'username']);

        $text = "# {$project->name}\n\n";
        $text .= "**URL**: {$project->url}\n";
        $text .= "**ID**: {$project->id}\n\n";

        $text .= "## Status\n";
        $text .= "- **Health**: {$project->health_status}\n";
        $text .= "- **Security**: {$project->security_status}\n";
        if ($project->last_health_check) {
            $text .= "- **Last Check**: {$project->last_health_check->diffForHumans()}\n";
        }
        $text .= "\n";

        if ($project->wp_version || $project->php_version) {
            $text .= "## WordPress Info\n";
            if ($project->wp_version) $text .= "- **WP Version**: {$project->wp_version}\n";
            if ($project->php_version) $text .= "- **PHP Version**: {$project->php_version}\n";
            if ($project->plugins_count) $text .= "- **Plugins**: {$project->plugins_count}\n";
            if ($project->outdated_plugins) $text .= "- **Outdated Plugins**: {$project->outdated_plugins}\n";
            $text .= "\n";
        }

        $text .= "## Team\n";
        $text .= "- **Manager**: " . ($project->manager?->name ?? 'None') . "\n";
        if ($project->developers->isNotEmpty()) {
            $text .= "- **Developers**: " . $project->developers->pluck('name')->join(', ') . "\n";
        }
        $text .= "\n";

        if ($pendingTodos->isNotEmpty()) {
            $text .= "## Pending Todos ({$pendingTodos->count()})\n";
            foreach ($pendingTodos as $todo) {
                $priority = strtoupper($todo->priority);
                $text .= "- [{$priority}] {$todo->title} (ID: {$todo->id})\n";
            }
            $text .= "\n";
        }

        if ($credentials->isNotEmpty()) {
            $text .= "## Credentials ({$credentials->count()})\n";
            foreach ($credentials as $cred) {
                $text .= "- {$cred->label}: {$cred->username} (ID: {$cred->id})\n";
            }
            $text .= "\n*Use reveal-credential tool to view passwords.*\n";
        }

        return Response::text($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the project to get details for')
                ->required(),
        ];
    }
}
