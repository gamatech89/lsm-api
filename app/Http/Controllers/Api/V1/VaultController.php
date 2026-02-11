<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CredentialResource;
use App\Models\Credential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Vault Controller
 * 
 * Provides a global view of all accessible credentials across projects.
 */
class VaultController extends Controller
{
    /**
     * Display a listing of all accessible credentials.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Credential::with('project:id,name,url')
            ->whereHas('project', function ($q) use ($user) {
                // Filter by projects the user can access
                if ($user->role === 'developer') {
                    $q->where(function ($sub) use ($user) {
                        $sub->where('developer_id', $user->id)
                            ->orWhereHas('developers', fn($d) => $d->where('users.id', $user->id));
                    });
                } elseif ($user->role === 'manager') {
                    $q->where('manager_id', $user->id);
                }
                // Admin sees all - no filter
            });

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhereHas('project', fn($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'updated_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        if ($sortBy === 'project') {
            $query->join('projects', 'credentials.project_id', '=', 'projects.id')
                  ->orderBy('projects.name', $sortOrder)
                  ->select('credentials.*');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $credentials = $query->paginate($perPage);

        return CredentialResource::collection($credentials);
    }
}
