<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpUpdateCoreTool extends Tool
{
    protected string $name = 'wp-update-core';

    protected string $description = <<<'MARKDOWN'
        Update WordPress core to the latest version. This updates the WordPress
        installation itself, not plugins or themes.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        $project = Project::with('developers')->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        try {
            $lsmService = LsmService::for($project);
            $result = $lsmService->updateCore();

            if ($result !== null) {
                $newVersion = $result['version'] ?? 'latest';
                return Response::text(
                    "✅ **WordPress Core Updated**\n\n" .
                    "**Project**: {$project->name}\n" .
                    "**URL**: {$project->url}\n" .
                    "**New Version**: {$newVersion}\n\n" .
                    "WordPress core has been updated successfully."
                );
            }

            return Response::error('Failed to update WordPress core: No response from plugin.');
        } catch (\Exception $e) {
            return Response::error('Failed to update WordPress core: ' . $e->getMessage());
        }
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
                ->description('The ID of the WordPress project to update core for')
                ->required(),
        ];
    }
}
