<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Credential;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * Export Controller
 * 
 * Provides data export endpoints for projects and credentials.
 */
class ExportController extends Controller
{
    /**
     * Export all accessible projects as JSON.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function projects(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Project::with(['manager:id,name,email', 'developers:id,name,email', 'tags']);

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $query->where(function ($q) use ($user) {
                $q->where('developer_id', $user->id)
                  ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $user->id));
            });
        } elseif ($user->role === 'manager') {
            $query->where('manager_id', $user->id);
        }

        $projects = $query->get()->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'url' => $p->url,
            'client_email' => $p->client_email,
            'health_status' => $p->health_status,
            'security_status' => $p->security_status,
            'project_external_id' => $p->project_external_id,
            'maintenance_id' => $p->maintenance_id,
            'manager' => $p->manager?->name,
            'developers' => $p->developers->pluck('name')->toArray(),
            'tags' => $p->tags->pluck('name')->toArray(),
            'created_at' => $p->created_at->toISOString(),
            'updated_at' => $p->updated_at->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'exported_at' => now()->toISOString(),
            'exported_by' => $user->name,
            'count' => $projects->count(),
            'data' => $projects,
        ]);
    }

    /**
     * Export a single project with all details.
     *
     * @param Project $project
     * @return JsonResponse
     */
    public function project(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $project->load([
            'manager:id,name,email',
            'developers:id,name,email',
            'credentials',
            'todos',
            'resources',
            'tags',
            'maintenanceReports.user:id,name',
        ]);

        return response()->json([
            'success' => true,
            'exported_at' => now()->toISOString(),
            'data' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'url' => $project->url,
                    'client_email' => $project->client_email,
                    'notes' => $project->notes,
                    'health_status' => $project->health_status,
                    'security_status' => $project->security_status,
                    'project_external_id' => $project->project_external_id,
                    'maintenance_id' => $project->maintenance_id,
                ],
                'team' => [
                    'manager' => $project->manager?->name,
                    'developers' => $project->developers->pluck('name')->toArray(),
                ],
                'credentials' => $project->credentials->map(fn($c) => [
                    'title' => $c->title,
                    'type' => $c->type,
                    'username' => $c->username,
                    // Passwords NOT included in export for security
                    'url' => $c->url,
                ]),
                'todos' => $project->todos->map(fn($t) => [
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'due_date' => $t->due_date,
                ]),
                'resources' => $project->resources->map(fn($r) => [
                    'title' => $r->title,
                    'type' => $r->type,
                    'url' => $r->url,
                ]),
                'tags' => $project->tags->pluck('name')->toArray(),
                'maintenance_reports_count' => $project->maintenanceReports->count(),
            ],
        ]);
    }

    /**
     * Export all accessible credentials (without passwords).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function credentials(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Credential::with('project:id,name,url')
            ->whereHas('project', function ($q) use ($user) {
                if ($user->role === 'developer') {
                    $q->where(function ($sub) use ($user) {
                        $sub->where('developer_id', $user->id)
                            ->orWhereHas('developers', fn($d) => $d->where('users.id', $user->id));
                    });
                } elseif ($user->role === 'manager') {
                    $q->where('manager_id', $user->id);
                }
            });

        $credentials = $query->get()->map(fn($c) => [
            'project' => $c->project->name,
            'title' => $c->title,
            'type' => $c->type,
            'username' => $c->username,
            'url' => $c->url,
            // Passwords NOT included for security
        ]);

        return response()->json([
            'success' => true,
            'exported_at' => now()->toISOString(),
            'exported_by' => $user->name,
            'count' => $credentials->count(),
            'note' => 'Passwords are not included in exports for security reasons.',
            'data' => $credentials,
        ]);
    }
}
