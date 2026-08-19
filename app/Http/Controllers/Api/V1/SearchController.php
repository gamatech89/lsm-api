<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\CredentialResource;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search Controller
 * 
 * Provides global search across projects and credentials.
 */
class SearchController extends Controller
{
    /**
     * Search across projects and credentials.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $query = $request->q;
        $user = $request->user();
        $limit = min($request->integer('limit', 10), 50);

        // Search projects
        $projectsQuery = Project::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('url', 'like', "%{$query}%")
                  ->orWhere('client_email', 'like', "%{$query}%")
                  ->orWhere('project_external_id', 'like', "%{$query}%")
                  ->orWhere('maintenance_id', 'like', "%{$query}%");
            });

        // Apply role-based filtering
        if ($user->role === 'developer') {
            $projectsQuery->where(function ($q) use ($user) {
                $q->where('developer_id', $user->id)
                  ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $user->id));
            });
        } elseif ($user->role === 'manager' && !$user->canViewAllProjects()) {
            $projectsQuery->where(function($q) use ($user) {
                $q->where('manager_id', $user->id)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
            });
        }

        $projects = $projectsQuery->limit($limit)->get();

        // Search credentials
        $credentialsQuery = Credential::query()
            ->with('project:id,name,url')
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('username', 'like', "%{$query}%");
            })
            ->whereHas('project', function ($q) use ($user) {
                // Apply same role-based filtering
                if ($user->role === 'developer') {
                    $q->where(function ($sub) use ($user) {
                        $sub->where('developer_id', $user->id)
                            ->orWhereHas('developers', fn($d) => $d->where('users.id', $user->id));
                    });
                } elseif ($user->role === 'manager') {
                    $q->where(function($sub) use ($user) {
                        $sub->where('manager_id', $user->id)
                            ->orWhereHas('managers', fn($m) => $m->where('users.id', $user->id));
                    });
                }
            });

        $credentials = $credentialsQuery->limit($limit)->get();

        return $this->successResponse([
            'projects' => ProjectResource::collection($projects),
            'credentials' => CredentialResource::collection($credentials),
            'counts' => [
                'projects' => $projects->count(),
                'credentials' => $credentials->count(),
            ],
        ]);
    }
}
