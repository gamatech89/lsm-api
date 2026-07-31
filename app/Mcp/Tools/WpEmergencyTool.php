<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WpEmergencyTool extends Tool
{
    protected string $name = 'wp-emergency';

    protected string $description = <<<'MARKDOWN'
        Emergency recovery actions for a WordPress site. Use when a site is broken or inaccessible.
        Actions: disable-plugins (deactivates all plugins), restore-plugins (reactivates them),
        emergency-recovery (full recovery: disables plugins, switches to default theme).
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        if (empty($input['action'])) {
            return Response::error('Action is required. Use: disable-plugins, restore-plugins, or emergency-recovery');
        }

        $project = Project::with('developers')->find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Only admins and managers can use emergency tools
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can use emergency recovery tools.');
        }

        if (!$this->canAccessProject($user, $project)) {
            return Response::error('You do not have access to this project.');
        }

        $action = $input['action'];
        
        try {
            $lsmService = LsmService::for($project);
            
            switch ($action) {
                case 'disable-plugins':
                    $result = $lsmService->disableAllPlugins();
                    $message = "⚠️ **All Plugins Disabled**\n\n" .
                        "**Project**: {$project->name}\n" .
                        "**URL**: {$project->url}\n\n" .
                        "All plugins have been deactivated. Use `restore-plugins` action to reactivate them.";
                    break;
                    
                case 'restore-plugins':
                    $result = $lsmService->restorePlugins();
                    $message = "✅ **Plugins Restored**\n\n" .
                        "**Project**: {$project->name}\n" .
                        "**URL**: {$project->url}\n\n" .
                        "Previously disabled plugins have been reactivated.";
                    break;
                    
                case 'emergency-recovery':
                    $result = $lsmService->emergencyRecovery();
                    $message = "🚨 **Emergency Recovery Executed**\n\n" .
                        "**Project**: {$project->name}\n" .
                        "**URL**: {$project->url}\n\n" .
                        "Recovery actions performed:\n" .
                        "- All plugins disabled\n" .
                        "- Switched to default theme\n" .
                        "- Caches cleared\n\n" .
                        "The site should now be accessible. Reactivate plugins one by one to find the issue.";
                    break;
                    
                default:
                    return Response::error("Unknown action: {$action}. Use: disable-plugins, restore-plugins, or emergency-recovery");
            }

            if ($result !== null) {
                return Response::text($message);
            }

            return Response::error("Failed to execute {$action}: No response from WordPress plugin.");
        } catch (\Exception $e) {
            return Response::error("Failed to execute {$action}: " . $e->getMessage());
        }
    }

    private function canAccessProject($user, $project): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->role === 'manager' && $project->isManagedBy($user)) return true;
        return false; // Developers can't use emergency tools
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the WordPress project')
                ->required(),
            'action' => $schema->string()
                ->description('Emergency action: disable-plugins, restore-plugins, or emergency-recovery')
                ->enum(['disable-plugins', 'restore-plugins', 'emergency-recovery'])
                ->required(),
        ];
    }
}
