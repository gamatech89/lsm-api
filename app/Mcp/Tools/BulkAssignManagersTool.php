<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class BulkAssignManagersTool extends Tool
{
    protected string $name = 'bulk-assign-managers';

    protected string $description = <<<'MARKDOWN'
        Bulk operations for project managers. Can ASSIGN or REMOVE PMs from multiple projects.
        Use action="assign" to add PMs (with strategies), action="clear" to REMOVE all PMs.
        Supports weighted distribution (e.g., "give Vinzent 50% of projects"), round robin, random.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can do bulk PM assignments
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can perform bulk PM operations.');
        }

        $action = $input['action'] ?? 'assign'; // 'assign' or 'clear'
        $mode = $input['mode'] ?? 'unassigned'; // 'all', 'unassigned', 'specific', 'assigned'
        $projectIds = $input['project_ids'] ?? [];

        // Build project query
        $projectsQuery = Project::query();

        switch ($mode) {
            case 'unassigned':
                $projectsQuery->whereNull('manager_id');
                break;
            case 'assigned':
                $projectsQuery->whereNotNull('manager_id');
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

        // Handle CLEAR action (remove all PMs)
        if ($action === 'clear') {
            $count = $projects->count();
            foreach ($projects as $project) {
                $project->manager_id = null;
                $project->save();
                $project->managers()->detach();
            }
            return Response::text(
                "✅ **Bulk PM Removal Complete**\n\n" .
                "**Projects Updated**: {$count}\n" .
                "**Action**: All project managers removed"
            );
        }

        // Handle ASSIGN action
        $strategy = $input['strategy'] ?? 'round_robin'; // 'round_robin', 'random', 'weighted'
        $managerIds = $input['manager_ids'] ?? [];
        $managerNames = $input['manager_names'] ?? [];
        $weights = $input['weights'] ?? [];

        // Resolve manager IDs from names if provided
        if (!empty($managerNames) && empty($managerIds)) {
            $managers = User::where('role', 'manager')
                ->where(function ($q) use ($managerNames) {
                    foreach ($managerNames as $name) {
                        $q->orWhere('name', 'like', '%' . $name . '%');
                    }
                })
                ->get();
            
            if ($managers->isEmpty()) {
                return Response::error("No managers found matching: " . implode(', ', $managerNames));
            }
            
            $managerIds = $managers->pluck('id')->toArray();
            $managerNames = $managers->pluck('name')->toArray();
        } elseif (!empty($managerIds)) {
            $managers = User::whereIn('id', $managerIds)
                ->whereIn('role', ['admin', 'manager'])
                ->get();

            $rejectedIds = array_values(array_diff($managerIds, $managers->pluck('id')->all()));
            if (!empty($rejectedIds)) {
                return Response::error(
                    'The following user IDs are not admins or managers (or do not exist): ' . implode(', ', $rejectedIds)
                );
            }

            $managerIds = $managers->pluck('id')->toArray();
            $managerNames = $managers->pluck('name')->toArray();
        } else {
            // Use all managers
            $managers = User::where('role', 'manager')->get();
            if ($managers->isEmpty()) {
                return Response::error('No managers found in the system.');
            }
            $managerIds = $managers->pluck('id')->toArray();
            $managerNames = $managers->pluck('name')->toArray();
        }

        // Distribute managers using the chosen strategy
        $assignments = [];
        $projectCount = $projects->count();
        $managerCount = count($managerIds);

        if ($strategy === 'weighted' && !empty($weights)) {
            // Weighted distribution
            $assignments = $this->distributeWeighted($projects, $managerIds, $weights);
        } elseif ($strategy === 'random') {
            // Random assignment
            foreach ($projects as $project) {
                $randomIndex = array_rand($managerIds);
                $project->manager_id = $managerIds[$randomIndex];
                $project->save();
                $project->managers()->sync([$managerIds[$randomIndex]]);
                $assignments[$managerIds[$randomIndex]][] = $project->name;
            }
        } else {
            // Round robin (default)
            $index = 0;
            foreach ($projects as $project) {
                $managerId = $managerIds[$index % $managerCount];
                $project->manager_id = $managerId;
                $project->save();
                $project->managers()->sync([$managerId]);
                $assignments[$managerId][] = $project->name;
                $index++;
            }
        }

        // Build summary
        $summary = "✅ **Bulk PM Assignment Complete**\n\n";
        $summary .= "**Projects Updated**: {$projectCount}\n";
        $summary .= "**Strategy**: {$strategy}\n\n";
        
        $summary .= "### Assignments:\n";
        
        $managerLookup = User::whereIn('id', $managerIds)->pluck('name', 'id')->toArray();
        
        foreach ($assignments as $managerId => $projectNames) {
            $managerName = $managerLookup[$managerId] ?? "ID: {$managerId}";
            $count = count($projectNames);
            $percentage = round(($count / $projectCount) * 100);
            $summary .= "- **{$managerName}**: {$count} projects ({$percentage}%)\n";
        }

        return Response::text($summary);
    }

    private function distributeWeighted($projects, array $managerIds, array $weights): array
    {
        $assignments = [];
        $projectCount = $projects->count();
        
        // Normalize weights to ensure they sum to 100
        $totalWeight = array_sum($weights);
        if ($totalWeight === 0) $totalWeight = 100;
        
        // Calculate how many projects each manager gets
        $quotas = [];
        $assigned = 0;
        
        foreach ($managerIds as $i => $managerId) {
            $weight = $weights[$i] ?? (100 / count($managerIds));
            $count = (int) round(($weight / $totalWeight) * $projectCount);
            $quotas[$managerId] = $count;
            $assigned += $count;
        }
        
        // Handle rounding issues - give remainder to first manager
        $diff = $projectCount - $assigned;
        if ($diff > 0) {
            $quotas[$managerIds[0]] += $diff;
        }
        
        // Assign projects based on quotas
        $projectList = $projects->values();
        $idx = 0;
        
        foreach ($quotas as $managerId => $quota) {
            for ($i = 0; $i < $quota && $idx < $projectCount; $i++, $idx++) {
                $project = $projectList[$idx];
                $project->manager_id = $managerId;
                $project->save();
                $project->managers()->sync([$managerId]);
                $assignments[$managerId][] = $project->name;
            }
        }
        
        return $assignments;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: "assign" to add PMs, "clear" to REMOVE all PMs from projects')
                ->enum(['assign', 'clear']),
            'mode' => $schema->string()
                ->description('Which projects: "all", "unassigned" (no PM), "assigned" (has PM), or "specific"')
                ->enum(['all', 'unassigned', 'assigned', 'specific']),
            'project_ids' => $schema->array()
                ->description('Specific project IDs (when mode=specific)')
                ->items($schema->integer()),
            'manager_ids' => $schema->array()
                ->description('Manager user IDs to assign (for action=assign)')
                ->items($schema->integer()),
            'manager_names' => $schema->array()
                ->description('Manager names to assign (for action=assign)')
                ->items($schema->string()),
            'strategy' => $schema->string()
                ->description('Distribution strategy (for action=assign): round_robin, random, weighted')
                ->enum(['round_robin', 'random', 'weighted']),
            'weights' => $schema->array()
                ->description('Weight % for each manager (for strategy=weighted). E.g., [50, 50]')
                ->items($schema->integer()),
        ];
    }
}
