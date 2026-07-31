<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateProjectTool extends Tool
{
    protected string $name = 'update-project';

    protected string $description = <<<'MARKDOWN'
        Update an existing project. Can change name, URL, manager, developers (multiple), health status, or security status. Can also REMOVE manager or developers.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        if (empty($input['project_id'])) {
            return Response::error('Project ID is required.');
        }

        $project = Project::find($input['project_id']);

        if (!$project) {
            return Response::error("Project with ID {$input['project_id']} not found.");
        }

        // Only admins and project managers can update projects
        if ($user->role !== 'admin' && !$project->isManagedBy($user)) {
            return Response::error('Only admins and project managers can update projects.');
        }

        $updates = [];

        if (isset($input['name'])) {
            $project->name = $input['name'];
            $updates[] = "name → {$input['name']}";
        }

        if (isset($input['url'])) {
            $url = $input['url'];
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
            }
            $project->url = $url;
            $updates[] = "URL → {$url}";
        }

        if (isset($input['health_status'])) {
            $valid = ['online', 'offline', 'maintenance', 'updating', 'down_error'];
            if (!in_array($input['health_status'], $valid)) {
                return Response::error("Invalid health status. Use: " . implode(', ', $valid));
            }
            $project->health_status = $input['health_status'];
            $updates[] = "health → {$input['health_status']}";
        }

        if (isset($input['security_status'])) {
            $valid = ['secure', 'monitoring', 'at_risk', 'compromised', 'hacked'];
            if (!in_array($input['security_status'], $valid)) {
                return Response::error("Invalid security status. Use: " . implode(', ', $valid));
            }
            $project->security_status = $input['security_status'];
            $updates[] = "security → {$input['security_status']}";
        }

        // Handle REMOVING manager (clear_manager = true)
        if (!empty($input['clear_manager'])) {
            $oldManagers = $project->managers->pluck('name')->toArray();
            $oldDisplay = !empty($oldManagers) ? implode(', ', $oldManagers) : 'None';
            $project->manager_id = null;
            $project->managers()->detach();
            $updates[] = "removed manager(s) ({$oldDisplay})";
        }

        // Handle manager assignment by ID
        if (isset($input['manager_id'])) {
            $manager = User::find($input['manager_id']);
            if (!$manager) {
                return Response::error("User with ID {$input['manager_id']} not found.");
            }
            if (!in_array($manager->role, ['admin', 'manager'])) {
                return Response::error("User with ID {$input['manager_id']} is not an admin or manager.");
            }
            $project->manager_id = $input['manager_id'];
            $project->managers()->sync([$input['manager_id']]);
            $updates[] = "manager → {$manager->name}";
        }

        // Handle manager assignment by name
        if (isset($input['manager_name'])) {
            $manager = User::where('name', 'like', '%' . $input['manager_name'] . '%')
                ->whereIn('role', ['admin', 'manager'])
                ->first();
            if (!$manager) {
                return Response::error("Manager '{$input['manager_name']}' not found.");
            }
            $project->manager_id = $manager->id;
            $project->managers()->sync([$manager->id]);
            $updates[] = "manager → {$manager->name}";
        }

        // Handle REMOVING all developers (clear_developers = true)
        if (!empty($input['clear_developers'])) {
            $oldDevs = $project->developers->pluck('name')->toArray();
            $oldDevsDisplay = !empty($oldDevs) ? implode(', ', $oldDevs) : 'None';
            $project->developers()->detach();
            $updates[] = "removed all developers ({$oldDevsDisplay})";
        }

        // Handle REMOVING specific developers by IDs
        if (isset($input['remove_developer_ids']) && is_array($input['remove_developer_ids'])) {
            $developerIds = $input['remove_developer_ids'];
            $removedDevs = User::whereIn('id', $developerIds)->get();
            
            if ($removedDevs->isNotEmpty()) {
                $project->developers()->detach($removedDevs->pluck('id')->toArray());
                $removedNames = $removedDevs->pluck('name')->toArray();
                $updates[] = "removed developers: " . implode(', ', $removedNames);
            }
        }

        // Handle REMOVING specific developers by names
        if (isset($input['remove_developer_names']) && is_array($input['remove_developer_names'])) {
            $removedDevs = [];
            
            foreach ($input['remove_developer_names'] as $devName) {
                $developer = User::where('name', 'like', '%' . $devName . '%')->first();
                if ($developer) {
                    $removedDevs[] = $developer;
                }
            }
            
            if (!empty($removedDevs)) {
                $project->developers()->detach(collect($removedDevs)->pluck('id')->toArray());
                $removedNames = collect($removedDevs)->pluck('name')->toArray();
                $updates[] = "removed developers: " . implode(', ', $removedNames);
            }
        }

        // Handle developer assignment by IDs (array) - REPLACES existing
        if (isset($input['developer_ids']) && is_array($input['developer_ids'])) {
            $developerIds = $input['developer_ids'];
            $validDevelopers = User::whereIn('id', $developerIds)->get();
            
            if ($validDevelopers->isEmpty()) {
                return Response::error("No valid developers found with the provided IDs.");
            }
            
            // Sync developers (replaces all existing developer assignments)
            $project->developers()->sync($validDevelopers->pluck('id')->toArray());
            $developerNames = $validDevelopers->pluck('name')->toArray();
            $updates[] = "developers → " . implode(', ', $developerNames);
        }

        // Handle developer assignment by names (array of names) - REPLACES existing
        if (isset($input['developer_names']) && is_array($input['developer_names'])) {
            $foundDevelopers = [];
            $notFound = [];
            
            foreach ($input['developer_names'] as $devName) {
                $developer = User::where('name', 'like', '%' . $devName . '%')
                    ->whereIn('role', ['developer', 'admin', 'manager'])
                    ->first();
                    
                if ($developer) {
                    $foundDevelopers[] = $developer;
                } else {
                    $notFound[] = $devName;
                }
            }
            
            if (empty($foundDevelopers)) {
                return Response::error("No developers found. Searched for: " . implode(', ', $input['developer_names']));
            }
            
            // Sync developers (replaces all existing developer assignments)
            $project->developers()->sync(collect($foundDevelopers)->pluck('id')->toArray());
            $developerNames = collect($foundDevelopers)->pluck('name')->toArray();
            $updates[] = "developers → " . implode(', ', $developerNames);
            
            if (!empty($notFound)) {
                $updates[] = "⚠️ Not found: " . implode(', ', $notFound);
            }
        }

        // Handle adding developers (without replacing existing ones)
        if (isset($input['add_developer_ids']) && is_array($input['add_developer_ids'])) {
            $developerIds = $input['add_developer_ids'];
            $validDevelopers = User::whereIn('id', $developerIds)->get();
            
            if ($validDevelopers->isNotEmpty()) {
                // Attach new developers without detaching existing ones
                $project->developers()->syncWithoutDetaching($validDevelopers->pluck('id')->toArray());
                $developerNames = $validDevelopers->pluck('name')->toArray();
                $updates[] = "added developers → " . implode(', ', $developerNames);
            }
        }

        // Handle adding developers by names (without replacing existing ones)
        if (isset($input['add_developer_names']) && is_array($input['add_developer_names'])) {
            $foundDevelopers = [];
            
            foreach ($input['add_developer_names'] as $devName) {
                $developer = User::where('name', 'like', '%' . $devName . '%')
                    ->whereIn('role', ['developer', 'admin', 'manager'])
                    ->first();
                    
                if ($developer) {
                    $foundDevelopers[] = $developer;
                }
            }
            
            if (!empty($foundDevelopers)) {
                // Attach new developers without detaching existing ones
                $project->developers()->syncWithoutDetaching(collect($foundDevelopers)->pluck('id')->toArray());
                $developerNames = collect($foundDevelopers)->pluck('name')->toArray();
                $updates[] = "added developers → " . implode(', ', $developerNames);
            }
        }

        if (empty($updates)) {
            return Response::error('No updates provided. Specify at least one field to update.');
        }

        $project->save();

        // Refresh relationships for the response
        $project->load(['developers', 'manager']);
        $currentDevs = $project->developers->pluck('name')->toArray();
        $developerDisplay = !empty($currentDevs) ? implode(', ', $currentDevs) : 'None';
        $managerDisplay = $project->manager ? $project->manager->name : 'None';

        return Response::text(
            "✅ **Project Updated**\n\n" .
            "**ID**: {$project->id}\n" .
            "**Name**: {$project->name}\n" .
            "**Manager**: {$managerDisplay}\n" .
            "**Developers**: {$developerDisplay}\n" .
            "**Changes**: " . implode(', ', $updates)
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The ID of the project to update')
                ->required(),
            'name' => $schema->string()
                ->description('New project name'),
            'url' => $schema->string()
                ->description('New project URL'),
            'health_status' => $schema->string()
                ->description('Health status: online, offline, maintenance, updating, down_error'),
            'security_status' => $schema->string()
                ->description('Security status: secure, monitoring, at_risk, compromised, hacked'),
            // Manager operations
            'manager_id' => $schema->integer()
                ->description('New manager user ID'),
            'manager_name' => $schema->string()
                ->description('New manager name (will search for matching user)'),
            'clear_manager' => $schema->boolean()
                ->description('Set to true to REMOVE the manager from this project'),
            // Developer SET operations (replaces existing)
            'developer_ids' => $schema->array()
                ->description('Array of developer user IDs to assign (REPLACES existing)')
                ->items($schema->integer()),
            'developer_names' => $schema->array()
                ->description('Array of developer names to assign (REPLACES existing)')
                ->items($schema->string()),
            // Developer ADD operations (keeps existing)
            'add_developer_ids' => $schema->array()
                ->description('Array of developer user IDs to ADD (keeps existing)')
                ->items($schema->integer()),
            'add_developer_names' => $schema->array()
                ->description('Array of developer names to ADD (keeps existing)')
                ->items($schema->string()),
            // Developer REMOVE operations
            'clear_developers' => $schema->boolean()
                ->description('Set to true to REMOVE ALL developers from this project'),
            'remove_developer_ids' => $schema->array()
                ->description('Array of developer user IDs to REMOVE')
                ->items($schema->integer()),
            'remove_developer_names' => $schema->array()
                ->description('Array of developer names to REMOVE')
                ->items($schema->string()),
        ];
    }
}
