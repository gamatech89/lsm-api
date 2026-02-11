<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpClearCacheTool extends Tool
{
    protected string $name = 'wp-clear-cache';

    protected string $description = <<<'MARKDOWN'
        Clear all caches on a WordPress site. This includes object cache, 
        page cache, and any caching plugin caches. Use this after making 
        changes that aren't appearing on the live site.
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

        // Check access
        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        try {
            $lsmService = LsmService::for($project);
            $result = $lsmService->clearCache();

            if ($result !== null) {
                return Response::text(
                    "✅ **Cache Cleared Successfully**\n\n" .
                    "Project: {$project->name}\n" .
                    "URL: {$project->url}\n\n" .
                    "All caches have been cleared. The site should now show the latest content."
                );
            }

            return Response::error('Failed to clear cache: No response from WordPress plugin.');
        } catch (\Exception $e) {
            return Response::error('Failed to clear cache: ' . $e->getMessage());
        }
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
                ->description('The ID of the WordPress project to clear cache for')
                ->required(),
        ];
    }
}
