<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpOptimizeDatabaseTool extends Tool
{
    protected string $name = 'wp-optimize-database';

    protected string $description = <<<'MARKDOWN'
        Optimize the WordPress database. This cleans up overhead in database
        tables, removes orphaned data, and improves performance.
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
            $result = $lsmService->optimizeDatabase();

            if ($result !== null) {
                $tablesOptimized = $result['tables_optimized'] ?? 'all';
                $spaceRecovered = $result['space_recovered'] ?? 'unknown';
                
                return Response::text(
                    "✅ **Database Optimized**\n\n" .
                    "**Project**: {$project->name}\n" .
                    "**URL**: {$project->url}\n" .
                    "**Tables Optimized**: {$tablesOptimized}\n" .
                    "**Space Recovered**: {$spaceRecovered}\n\n" .
                    "Database optimization complete."
                );
            }

            return Response::error('Failed to optimize database: No response from plugin.');
        } catch (\Exception $e) {
            return Response::error('Failed to optimize database: ' . $e->getMessage());
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
                ->description('The ID of the WordPress project to optimize database for')
                ->required(),
        ];
    }
}
