<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class BulkAssignDevelopersTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:write';
    }

    protected string $name = 'bulk-assign-developers';

    protected string $description = <<<'MARKDOWN'
        Bulk operations for project assignments. Can ASSIGN or REMOVE developers/managers from multiple projects at once. Use action="assign" to add or action="clear" to remove.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can do bulk operations
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can perform bulk operations.');
        }

        $action = $input['action'] ?? 'assign'; // 'assign' or 'clear'
        $target = $input['target'] ?? 'developers'; // 'developers', 'manager', or 'both'
        $mode = $input['mode'] ?? 'all'; // 'all', 'specific', 'unassigned', 'assigned'
        $projectIds = $input['project_ids'] ?? [];
        $developerIds = $input['developer_ids'] ?? [];
        $developerNames = $input['developer_names'] ?? [];
        $strategy = $input['strategy'] ?? 'round_robin';

        // Build project query based on mode
        $projectsQuery = Project::query();

        switch ($mode) {
            case 'unassigned':
                $projectsQuery->whereDoesntHave('developers');
                break;
            case 'assigned':
                $projectsQuery->whereHas('developers');
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

        // Handle CLEAR action (removing)
        if ($action === 'clear') {
            return $this->handleClear($projects, $target);
        }

        // Handle ASSIGN action
        return $this->handleAssign($projects, $developerIds, $developerNames, $strategy);
    }

    protected function handleClear($projects, string $target): Response
    {
        $clearedDevs = 0;
        $clearedManagers = 0;
        $projectNames = [];

        foreach ($projects as $project) {
            $projectNames[] = $project->name;

            if (in_array($target, ['developers', 'both'])) {
                $devCount = $project->developers->count();
                $project->developers()->detach();
                $clearedDevs += $devCount;
            }

            if (in_array($target, ['manager', 'both'])) {
                $managerCount = $project->managers->count();
                if ($managerCount > 0) {
                    $project->managers()->detach();
                    $clearedManagers += $managerCount;
                }
                if ($project->manager_id) {
                    $project->manager_id = null;
                    $project->save();
                    if ($managerCount === 0) $clearedManagers++;
                }
            }
        }

        $summary = "✅ **Bulk Removal Complete**\n\n";
        $summary .= "**Projects Affected**: " . count($projectNames) . "\n";
        
        if (in_array($target, ['developers', 'both'])) {
            $summary .= "**Developers Removed**: {$clearedDevs} total assignments\n";
        }
        if (in_array($target, ['manager', 'both'])) {
            $summary .= "**Managers Removed**: {$clearedManagers}\n";
        }

        $summary .= "\n**Projects**:\n";
        foreach (array_slice($projectNames, 0, 20) as $name) {
            $summary .= "- {$name}\n";
        }

        if (count($projectNames) > 20) {
            $summary .= "\n*...and " . (count($projectNames) - 20) . " more*";
        }

        return Response::text($summary);
    }

    protected function handleAssign($projects, array $developerIds, array $developerNames, string $strategy): Response
    {
        $skippedManagers = [];
        
        // Resolve developers by name if provided
        if (!empty($developerNames)) {
            foreach ($developerNames as $devName) {
                $user = User::where('name', 'like', '%' . $devName . '%')->first();
                if ($user) {
                    if ($user->role === 'manager') {
                        // This is a manager, not a developer!
                        $skippedManagers[] = $user->name;
                    } elseif (in_array($user->role, ['developer', 'admin'])) {
                        $developerIds[] = $user->id;
                    }
                }
            }
        }

        // If only managers were specified, return helpful error
        if (!empty($skippedManagers) && empty($developerIds)) {
            return Response::error(
                "⚠️ **Wrong tool for managers!**\n\n" .
                "Users specified are Project Managers: " . implode(', ', $skippedManagers) . "\n\n" .
                "**Use `bulk-assign-managers` instead** to assign PMs to projects.\n" .
                "`bulk-assign-developers` is only for developers (role=developer)."
            );
        }

        // If no developers specified, find all developers
        if (empty($developerIds)) {
            $developerIds = User::where('role', 'developer')
                ->pluck('id')
                ->toArray();
        }

        if (empty($developerIds)) {
            return Response::error('No developers found to assign.');
        }

        $developers = User::whereIn('id', $developerIds)->get();

        // Sort developers based on strategy
        if ($strategy === 'least_busy') {
            $developers = $developers->sortBy(function ($dev) {
                return $dev->assignedTodos()
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->count();
            })->values();
        } elseif ($strategy === 'random') {
            $developers = $developers->shuffle();
        }

        $assigned = [];
        $devIndex = 0;

        foreach ($projects as $project) {
            $developer = $developers[$devIndex % $developers->count()];
            $project->developers()->syncWithoutDetaching([$developer->id]);
            
            $assigned[] = [
                'project' => $project->name,
                'developer' => $developer->name,
            ];

            $devIndex++;
        }

        $summary = "✅ **Bulk Assignment Complete**\n\n";
        $summary .= "**Projects Updated**: " . count($assigned) . "\n";
        $summary .= "**Developers Used**: " . $developers->pluck('name')->implode(', ') . "\n";
        $summary .= "**Strategy**: {$strategy}\n\n";

        $summary .= "| Project | Assigned Developer |\n";
        $summary .= "|---------|--------------------|n";
        
        foreach (array_slice($assigned, 0, 20) as $item) {
            $summary .= "| {$item['project']} | {$item['developer']} |\n";
        }

        if (count($assigned) > 20) {
            $summary .= "\n*...and " . (count($assigned) - 20) . " more*";
        }

        return Response::text($summary);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: "assign" to add developers, "clear" to remove them')
                ->enum(['assign', 'clear']),
            'target' => $schema->string()
                ->description('What to clear (for action=clear): "developers", "manager", or "both"')
                ->enum(['developers', 'manager', 'both']),
            'mode' => $schema->string()
                ->description('Which projects: "all", "specific" (use project_ids), "unassigned" (no devs), "assigned" (has devs)')
                ->enum(['all', 'specific', 'unassigned', 'assigned']),
            'project_ids' => $schema->array()
                ->description('Specific project IDs (when mode=specific)')
                ->items($schema->integer()),
            'developer_ids' => $schema->array()
                ->description('Developer IDs to assign (for action=assign)')
                ->items($schema->integer()),
            'developer_names' => $schema->array()
                ->description('Developer names to assign (for action=assign)')
                ->items($schema->string()),
            'strategy' => $schema->string()
                ->description('Distribution strategy (for action=assign): "round_robin", "least_busy", "random"')
                ->enum(['round_robin', 'least_busy', 'random']),
        ];
    }
}
