<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ResourceResource;
use App\Models\Project;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Resource Controller
 * 
 * Manages project resources (links and file attachments).
 */
class ResourceController extends Controller
{
    /**
     * Display a listing of resources for a project.
     *
     * @param Project $project
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Project $project)
    {
        Gate::authorize('view', $project);

        return ResourceResource::collection($project->resources);
    }

    /**
     * Display the specified resource.
     *
     * @param Resource $resource
     * @return ResourceResource
     */
    public function show(Resource $resource): ResourceResource
    {
        Gate::authorize('view', $resource->project);

        return new ResourceResource($resource);
    }

    /**
     * Store a newly created resource (link or file).
     *
     * @param Request $request
     * @param Project $project
     * @return JsonResponse
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:link,file',
            'url' => 'required_if:type,link|nullable|url|max:255',
            'file' => 'required_if:type,file|nullable|file|max:20480', // 20MB max
            'notes' => 'nullable|string',
            'is_quick_action' => 'boolean',
        ]);

        $validated['project_id'] = $project->id;

        // Handle file upload
        if ($validated['type'] === 'file' && $request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('resources', 'local');
            
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
        }

        unset($validated['file']); // Remove file from validated array before create

        $resource = Resource::create($validated);

        return $this->createdResponse(
            new ResourceResource($resource),
            'Resource created successfully'
        );
    }

    /**
     * Update the specified resource.
     *
     * @param Request $request
     * @param Resource $resource
     * @return ResourceResource
     */
    public function update(Request $request, Resource $resource): ResourceResource
    {
        Gate::authorize('update', $resource->project);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'url' => 'nullable|url|max:255',
            'file' => 'nullable|file|max:20480',
            'notes' => 'nullable|string',
            'is_quick_action' => 'boolean',
        ]);

        // Handle file replacement
        if ($request->hasFile('file')) {
            // Delete old file if exists
            if ($resource->file_path && Storage::disk('local')->exists($resource->file_path)) {
                Storage::disk('local')->delete($resource->file_path);
            }
            
            $file = $request->file('file');
            $path = $file->store('resources', 'local');
            
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
            $validated['type'] = 'file';
        }

        unset($validated['file']);

        $resource->update($validated);

        return new ResourceResource($resource);
    }

    /**
     * Remove the specified resource.
     *
     * @param Resource $resource
     * @return JsonResponse
     */
    public function destroy(Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource->project);

        // Delete associated file if exists
        if ($resource->file_path && Storage::disk('local')->exists($resource->file_path)) {
            Storage::disk('local')->delete($resource->file_path);
        }

        $resource->delete();

        return $this->successResponse(null, 'Resource deleted successfully');
    }

    /**
     * Download the file for a resource.
     *
     * @param Resource $resource
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function download(Resource $resource)
    {
        Gate::authorize('view', $resource->project);

        if ($resource->type !== 'file') {
            return $this->errorResponse('This resource is not a file', 400);
        }

        if (!$resource->file_path || !Storage::disk('local')->exists($resource->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        return response()->download(
            Storage::disk('local')->path($resource->file_path),
            $resource->file_name ?? 'download'
        );
    }
}
