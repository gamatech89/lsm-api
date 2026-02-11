<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\LsmService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class BulkWpActionTool extends Tool
{
    protected string $name = 'bulk-wp-action';

    protected string $description = <<<'MARKDOWN'
        Execute WordPress actions on MULTIPLE projects at once. Supports:
        - clear-cache: Clear cache on all/selected projects
        - enable-maintenance: Enable maintenance mode
        - disable-maintenance: Disable maintenance mode
        - update-plugins: Update all plugins on all/selected projects
        Much faster than running actions one by one!
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can do bulk WP actions
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can perform bulk WordPress actions.');
        }

        $action = $input['action'] ?? null;
        if (!$action) {
            return Response::error('Action is required. Use: clear-cache, enable-maintenance, disable-maintenance, update-plugins');
        }

        $mode = $input['mode'] ?? 'all'; // 'all', 'specific', 'online', 'offline'
        $projectIds = $input['project_ids'] ?? [];

        // Build project query based on mode
        $projectsQuery = Project::query();

        switch ($mode) {
            case 'online':
                $projectsQuery->where('health_status', 'online');
                break;
            case 'offline':
                $projectsQuery->where('health_status', 'offline');
                break;
            case 'maintenance':
                $projectsQuery->where('health_status', 'maintenance');
                break;
            case 'specific':
                if (!empty($projectIds)) {
                    $projectsQuery->whereIn('id', $projectIds);
                }
                break;
            // 'all' - no filter
        }

        $projects = $projectsQuery->get();

        if ($projects->isEmpty()) {
            return Response::error('No projects found matching criteria.');
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($projects as $project) {
            try {
                $lsmService = LsmService::for($project);
                $result = null;

                switch ($action) {
                    case 'clear-cache':
                        $result = $lsmService->clearCache();
                        break;
                    case 'enable-maintenance':
                        $result = $lsmService->enableMaintenance();
                        break;
                    case 'disable-maintenance':
                        $result = $lsmService->disableMaintenance();
                        break;
                    case 'update-plugins':
                        $result = $lsmService->updatePlugins();
                        break;
                    default:
                        $results['failed'][] = "{$project->name}: Unknown action";
                        continue 2;
                }

                if ($result !== null) {
                    $results['success'][] = $project->name;
                } else {
                    $results['failed'][] = "{$project->name}: No response from plugin";
                }
            } catch (\Exception $e) {
                $results['failed'][] = "{$project->name}: " . $e->getMessage();
            }
        }

        // Build summary
        $actionLabels = [
            'clear-cache' => 'Cache Cleared',
            'enable-maintenance' => 'Maintenance Enabled',
            'disable-maintenance' => 'Maintenance Disabled',
            'update-plugins' => 'Plugins Updated',
        ];

        $summary = "✅ **Bulk {$actionLabels[$action]}**\n\n";
        $summary .= "**Successful**: " . count($results['success']) . " projects\n";
        $summary .= "**Failed**: " . count($results['failed']) . " projects\n\n";

        if (!empty($results['success'])) {
            $summary .= "### ✅ Success\n";
            foreach (array_slice($results['success'], 0, 15) as $name) {
                $summary .= "- {$name}\n";
            }
            if (count($results['success']) > 15) {
                $summary .= "- ...and " . (count($results['success']) - 15) . " more\n";
            }
        }

        if (!empty($results['failed'])) {
            $summary .= "\n### ❌ Failed\n";
            foreach (array_slice($results['failed'], 0, 10) as $error) {
                $summary .= "- {$error}\n";
            }
            if (count($results['failed']) > 10) {
                $summary .= "- ...and " . (count($results['failed']) - 10) . " more\n";
            }
        }

        return Response::text($summary);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('WordPress action: clear-cache, enable-maintenance, disable-maintenance, update-plugins')
                ->enum(['clear-cache', 'enable-maintenance', 'disable-maintenance', 'update-plugins'])
                ->required(),
            'mode' => $schema->string()
                ->description('Which projects: "all", "specific" (use project_ids), "online", "offline", "maintenance"')
                ->enum(['all', 'specific', 'online', 'offline', 'maintenance']),
            'project_ids' => $schema->array()
                ->description('Specific project IDs (when mode=specific)')
                ->items($schema->integer()),
        ];
    }
}
