<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListProjectsTool extends Tool
{
    protected string $name = 'list-projects';

    protected string $description = <<<'MARKDOWN'
        List WordPress projects you have access to. You can filter by health status 
        (online, offline, maintenance) or security status (secure, at_risk, hacked).
        Returns a summary of each project.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        $query = Project::with(['manager:id,name'])
            ->select(['id', 'name', 'url', 'health_status', 'security_status', 'manager_id']);

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $query->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager') {
            $query->where('manager_id', $user->id);
        }

        // Apply optional filters
        if (!empty($input['health_status'])) {
            $query->where('health_status', $input['health_status']);
        }

        if (!empty($input['security_status'])) {
            $status = $input['security_status'];
            // Handle "at_risk" as alias for compromised/hacked
            if (in_array($status, ['at_risk', 'at-risk', 'risk'])) {
                $query->whereIn('security_status', ['compromised', 'hacked']);
            } else {
                $query->where('security_status', $status);
            }
        }

        if (!empty($input['search'])) {
            $search = $input['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%");
            });
        }

        $totalCount = (clone $query)->count();
        $projects = $query->orderBy('name')->limit(200)->get();

        if ($projects->isEmpty()) {
            return Response::text("No projects found matching your criteria.");
        }

        $shownCount = $projects->count();
        $text = "**Found {$totalCount} project(s)**" . ($shownCount < $totalCount ? " (showing first {$shownCount})" : "") . ":\n\n";

        foreach ($projects as $project) {
            $statusEmoji = match($project->health_status) {
                'online' => '🟢',
                'offline', 'down_error' => '🔴',
                'maintenance', 'updating' => '🟡',
                default => '⚪',
            };

            $securityEmoji = match($project->security_status) {
                'secure' => '🔒',
                'at_risk' => '⚠️',
                'hacked' => '🚨',
                default => '',
            };

            $text .= "{$statusEmoji} **{$project->name}** (ID: {$project->id})\n";
            $text .= "   URL: {$project->url}\n";
            $text .= "   Health: {$project->health_status} {$securityEmoji}{$project->security_status}\n";
            if ($project->manager) {
                $text .= "   Manager: {$project->manager->name}\n";
            }
            $text .= "\n";
        }

        return Response::text($text);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'health_status' => $schema->string()
                ->description('Filter by health status: online, offline, maintenance, updating, down_error'),
            'security_status' => $schema->string()
                ->description('Filter by security status: secure, monitoring, at_risk, compromised, hacked'),
            'search' => $schema->string()
                ->description('Search projects by name or URL'),
        ];
    }
}
