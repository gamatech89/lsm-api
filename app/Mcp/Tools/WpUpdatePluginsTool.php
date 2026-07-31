<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpUpdatePluginsTool extends Tool
{
    protected string $name = 'wp-update-plugins';

    protected string $description = <<<'MARKDOWN'
        Update all plugins on a WordPress site to their latest versions.
        This will update ALL plugins that have available updates.
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
            $result = $lsmService->updatePlugins();

            if ($result !== null) {
                $updatedCount = $result['updated'] ?? 0;
                $message = "✅ **Plugins Updated Successfully**\n\n" .
                    "**Project**: {$project->name}\n" .
                    "**URL**: {$project->url}\n";

                if (isset($result['plugins']) && is_array($result['plugins'])) {
                    $message .= "**Updated**: " . count($result['plugins']) . " plugins\n\n";
                    foreach (array_slice($result['plugins'], 0, 10) as $plugin) {
                        $name = $plugin['name'] ?? $plugin['slug'] ?? 'Unknown';
                        $message .= "- {$name}\n";
                    }
                    if (count($result['plugins']) > 10) {
                        $message .= "- ...and " . (count($result['plugins']) - 10) . " more\n";
                    }
                } else {
                    $message .= "All plugins are now up to date.";
                }

                return Response::text($message);
            }

            return Response::error('Failed to update plugins: No response from WordPress plugin.');
        } catch (\Exception $e) {
            return Response::error('Failed to update plugins: ' . $e->getMessage());
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
                ->description('The ID of the WordPress project to update plugins for')
                ->required(),
        ];
    }
}
