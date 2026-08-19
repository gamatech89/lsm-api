<?php

namespace App\Mcp\Resources;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use App\Mcp\Concerns\HasRequiredScope;

class ProjectsResource extends Resource
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'projects';

    protected string $uri = 'lsm://projects';

    protected string $description = <<<'MARKDOWN'
        List of all WordPress projects you have access to, including their 
        health status, security status, and basic information. Results are 
        filtered by your role permissions.
    MARKDOWN;

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();

        $query = Project::with(['manager:id,name', 'tags:id,name'])
            ->select([
                'id', 'name', 'url', 'health_status', 'security_status',
                'manager_id', 'updated_at', 'last_health_check'
            ]);

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $query->whereHas('developers', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'manager' && !$user->canViewAllProjects()) {
            $query->where(function($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }
        // Admin sees all

        $projects = $query->orderBy('name')->get();

        $data = $projects->map(fn($project) => [
            'id' => $project->id,
            'name' => $project->name,
            'url' => $project->url,
            'health_status' => $project->health_status,
            'security_status' => $project->security_status,
            'manager' => $project->manager?->name,
            'tags' => $project->tags->pluck('name'),
            'last_health_check' => $project->last_health_check?->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ]);

        return Response::json([
            'count' => $data->count(),
            'projects' => $data,
        ]);
    }
}
