<?php

namespace App\Mcp\Tools;

use App\Models\Resource;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class ListResourcesTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'list-resources';

    protected string $description = <<<'MARKDOWN'
        List resources attached to a project. Resources are files, links, or documents.
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

        $resources = Resource::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($resources->isEmpty()) {
            return Response::text("No resources found for project **{$project->name}**.");
        }

        $lines = ["**📁 Resources for {$project->name} ({$resources->count()})**\n"];

        foreach ($resources as $resource) {
            $typeEmoji = match ($resource->type ?? 'file') {
                'link' => '🔗',
                'file' => '📄',
                'document' => '📝',
                default => '📦',
            };

            $lines[] = sprintf(
                "%s **%s**\n   %s",
                $typeEmoji,
                $resource->name ?? $resource->title ?? 'Untitled',
                $resource->url ?? $resource->path ?? 'No path'
            );
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project ID to list resources for')
                ->required(),
        ];
    }
}
