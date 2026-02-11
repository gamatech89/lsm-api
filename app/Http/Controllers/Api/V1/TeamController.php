<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Team Controller
 * 
 * Manages team members (users) and their project assignments.
 */
class TeamController extends Controller
{
    /**
     * Display a listing of team members.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $query = User::query()->with('tags')
            ->withCount(['managedProjects', 'assignedProjects']);

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('slug', $request->tag));
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->get();

        return UserResource::collection($users);
    }

    /**
     * Display the specified team member.
     *
     * @param User $user
     * @return UserResource
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * Store a newly created team member.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
            'role' => 'required|in:admin,manager,developer,viewer',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
        ]);

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        $validated['password'] = Hash::make($validated['password']);
        $validated['email_verified_at'] = now(); // Auto-verify for admin-created users

        $user = User::create($validated);

        // Sync tags
        if (!empty($tagIds)) {
            $user->tags()->sync($tagIds);
        }

        $user->load('tags');

        return $this->createdResponse(
            new UserResource($user),
            'Team member created successfully'
        );
    }

    /**
     * Update the specified team member.
     *
     * @param Request $request
     * @param User $user
     * @return UserResource
     */
    public function update(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => ['sometimes', 'nullable', Password::defaults()],
            'role' => 'sometimes|required|in:admin,manager,developer,viewer',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
        ]);

        $tagIds = $validated['tag_ids'] ?? null;
        unset($validated['tag_ids']);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        // Sync tags if provided
        if ($tagIds !== null) {
            $user->tags()->sync($tagIds);
        }

        $user->load('tags');

        return new UserResource($user);
    }

    /**
     * Remove the specified team member.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot delete your own account', 400);
        }

        // Prevent deleting the last admin
        if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
            return $this->errorResponse('Cannot delete the last admin user', 400);
        }

        $user->delete();

        return $this->successResponse(null, 'Team member deleted successfully');
    }

    /**
     * Get projects assigned to a team member.
     *
     * @param User $user
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function projects(User $user)
    {
        $projects = $user->role === 'manager'
            ? $user->managedProjects()->with(['developers:id,name'])->get()
            : $user->developerProjects()->with(['manager:id,name'])->get();

        return ProjectResource::collection($projects);
    }
}
