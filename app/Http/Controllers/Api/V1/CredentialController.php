<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CredentialResource;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * Credential Controller
 * 
 * Manages project credentials with secure password handling.
 * Passwords are never returned by default - use the reveal endpoint.
 */
class CredentialController extends Controller
{
    /**
     * Display a listing of credentials for a project.
     *
     * @param Project $project
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Project $project)
    {
        Gate::authorize('view', $project);

        return CredentialResource::collection($project->credentials);
    }

    /**
     * Store a newly created credential.
     *
     * @param Request $request
     * @param Project $project
     * @return JsonResponse
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('manageCredentials', $project);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:wordpress,ssh,ftp,database,hosting,email,api,other',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'url' => 'nullable|url|max:255',
            'note' => 'nullable|string',
            'metadata' => 'nullable|array',
            'metadata.hostname' => 'nullable|string|max:255',
            'metadata.port' => 'nullable|integer',
            'metadata.database_name' => 'nullable|string|max:255',
        ]);

        // Encrypt password if provided
        if (!empty($validated['password'])) {
            $validated['password'] = Crypt::encryptString($validated['password']);
        }

        $validated['project_id'] = $project->id;
        $credential = Credential::create($validated);

        // Log the creation
        activity()
            ->causedBy(auth()->user())
            ->performedOn($credential)
            ->log('created credential');

        return $this->createdResponse(
            new CredentialResource($credential),
            'Credential created successfully'
        );
    }

    /**
     * Update the specified credential.
     *
     * @param Request $request
     * @param Credential $credential
     * @return CredentialResource
     */
    public function update(Request $request, Credential $credential): CredentialResource
    {
        Gate::authorize('manageCredentials', $credential->project);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:wordpress,ssh,ftp,database,hosting,email,api,other',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'url' => 'nullable|url|max:255',
            'note' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Encrypt password if provided (and not null)
        if (array_key_exists('password', $validated) && $validated['password'] !== null) {
            $validated['password'] = Crypt::encryptString($validated['password']);
        } elseif (array_key_exists('password', $validated) && $validated['password'] === null) {
            // Explicitly set to null means clear the password
            $validated['password'] = null;
        }

        $credential->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($credential)
            ->log('updated credential');

        return new CredentialResource($credential);
    }

    /**
     * Remove the specified credential.
     *
     * @param Credential $credential
     * @return JsonResponse
     */
    public function destroy(Credential $credential): JsonResponse
    {
        Gate::authorize('manageCredentials', $credential->project);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($credential)
            ->log('deleted credential');

        $credential->delete();

        return $this->successResponse(null, 'Credential deleted successfully');
    }

    /**
     * Reveal the password for a credential.
     * 
     * This is a separate endpoint for security - passwords are never
     * returned in normal responses. All access is logged.
     *
     * @param Credential $credential
     * @return JsonResponse
     */
    public function reveal(Credential $credential): JsonResponse
    {
        Gate::authorize('view', $credential->project);

        // Log every password reveal for audit trail
        activity()
            ->causedBy(auth()->user())
            ->performedOn($credential)
            ->withProperties(['ip' => request()->ip()])
            ->log('revealed credential password');

        // Use the withPassword factory method to include the password
        return $this->successResponse(
            CredentialResource::withPassword($credential)
        );
    }
}
