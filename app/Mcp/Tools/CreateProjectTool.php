<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateProjectTool extends Tool
{
    protected string $name = 'create-project';

    protected string $description = <<<'MARKDOWN'
        Create a new WordPress project. Requires name and URL.
        Optionally assign a manager and set initial status.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        // Only admins can create projects
        if ($user->role !== 'admin') {
            return Response::error('Only admins can create projects.');
        }

        if (empty($input['name'])) {
            return Response::error('Project name is required.');
        }

        if (empty($input['url'])) {
            return Response::error('Project URL is required.');
        }

        // Normalize URL
        $url = $input['url'];
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        // Check for duplicate URL
        $existing = Project::where('url', $url)->first();
        if ($existing) {
            return Response::error("A project with URL {$url} already exists (ID: {$existing->id}).");
        }

        // Find manager if specified
        $managerId = null;
        if (!empty($input['manager_id'])) {
            $manager = User::find($input['manager_id']);
            if (!$manager) {
                return Response::error("Manager with ID {$input['manager_id']} not found.");
            }
            if (!in_array($manager->role, ['admin', 'manager'])) {
                return Response::error("User with ID {$input['manager_id']} is not an admin or manager.");
            }
            $managerId = $manager->id;
        } elseif (!empty($input['manager_name'])) {
            $manager = User::where('name', 'like', '%' . $input['manager_name'] . '%')
                ->whereIn('role', ['admin', 'manager'])
                ->first();
            if (!$manager) {
                return Response::error("Manager '{$input['manager_name']}' not found.");
            }
            $managerId = $manager->id;
        }

        $project = Project::create([
            'name' => $input['name'],
            'url' => $url,
            'manager_id' => $managerId,
            'health_status' => 'online',
            'security_status' => 'secure',
            'created_by' => $user->id,
        ]);
        // Sync manager to pivot table
        if ($managerId) {
            $project->managers()->sync([$managerId]);
        }

        $managerName = $managerId ? User::find($managerId)->name : 'Unassigned';

        return Response::text(
            "✅ **Project Created Successfully**\n\n" .
            "**ID**: {$project->id}\n" .
            "**Name**: {$project->name}\n" .
            "**URL**: {$project->url}\n" .
            "**Manager**: {$managerName}\n\n" .
            "You can now add todos, credentials, and resources to this project."
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The name of the project/client')
                ->required(),
            'url' => $schema->string()
                ->description('The WordPress site URL')
                ->required(),
            'manager_id' => $schema->integer()
                ->description('User ID of the project manager'),
            'manager_name' => $schema->string()
                ->description('Name of the project manager (will search for matching user)'),
        ];
    }
}
