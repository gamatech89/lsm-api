<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\Credential;
use App\Models\CredentialAccess;
use App\Models\User;
use App\Notifications\CredentialAccessGrantedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Credential Access Controller
 *
 * Manages per-credential access grants for developers.
 * Only managers and admins can grant or revoke access.
 */
class CredentialAccessController extends Controller
{
    /**
     * List users who have been granted access to a credential.
     * Also returns all project developers so the UI can show checkboxes.
     */
    public function index(Credential $credential): JsonResponse
    {
        Gate::authorize('manageAccess', $credential);

        $credential->load('accessGrants.user:id,name,email');

        $grantedUserIds = $credential->accessGrants->pluck('user_id')->toArray();

        // All developers on the project for the selection list
        $projectDevelopers = User::whereHas('assignedProjects', fn($q) =>
            $q->where('projects.id', $credential->project_id)
        )->select('id', 'name', 'email')->orderBy('name')->get();

        return $this->successResponse([
            'granted_user_ids' => $grantedUserIds,
            'project_developers' => UserResource::collection($projectDevelopers),
        ]);
    }

    /**
     * Sync access grants — replaces the current grant list with the provided user IDs.
     * Pass an empty array to revoke all access.
     */
    public function sync(Request $request, Credential $credential): JsonResponse
    {
        Gate::authorize('manageAccess', $credential);

        $validated = $request->validate([
            'user_ids' => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $grantedBy = $request->user()->id;
        $userIds = collect($validated['user_ids'])->unique()->values();

        // Remove grants not in the new list
        CredentialAccess::where('credential_id', $credential->id)
            ->whereNotIn('user_id', $userIds)
            ->delete();

        // Add new grants and notify newly added users
        $credential->load('project');
        foreach ($userIds as $userId) {
            $grant = CredentialAccess::firstOrCreate(
                ['credential_id' => $credential->id, 'user_id' => $userId],
                ['granted_by' => $grantedBy]
            );
            if ($grant->wasRecentlyCreated) {
                User::find($userId)?->notify(new CredentialAccessGrantedNotification($credential));
            }
        }

        $grantedUserIds = CredentialAccess::where('credential_id', $credential->id)
            ->pluck('user_id');

        return $this->successResponse(
            ['granted_user_ids' => $grantedUserIds],
            'Access updated successfully'
        );
    }
}
