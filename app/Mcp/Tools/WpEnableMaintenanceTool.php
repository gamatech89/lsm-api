<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpEnableMaintenanceTool extends Tool
{
    protected string $name = 'wp-maintenance-on';

    protected string $description = <<<'MARKDOWN'
        Enable maintenance mode on a WordPress site. Visitors will see a 
        "Site under maintenance" message. Use this before making major 
        updates or changes.
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
            $result = $lsmService->enableMaintenance();

            if ($result !== null) {
                // Update local status using the is_maintenance column
                $project->update(['is_maintenance' => true]);

                return Response::text(
                    "🔧 **Maintenance Mode Enabled**\n\n" .
                    "Project: {$project->name}\n" .
                    "URL: {$project->url}\n\n" .
                    "✅ The site is now showing a maintenance page to visitors.\n" .
                    "Use `wp-maintenance-off` when you're done to restore normal access."
                );
            }

            return Response::error('Failed to enable maintenance mode: No response from WordPress plugin.');
        } catch (\Exception $e) {
            return Response::error('Failed to enable maintenance mode: ' . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->managers->contains('id', $user->id)) return true;
        if ($user->role === 'developer' && $project->developers->contains('id', $user->id)) return true;
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project to put in maintenance mode')
                ->required(),
        ];
    }
}
