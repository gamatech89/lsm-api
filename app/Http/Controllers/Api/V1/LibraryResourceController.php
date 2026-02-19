<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LibraryResourceResource;
use App\Models\LibraryResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Library Resource Controller
 * 
 * Manages global library resources (shared files that can be linked to multiple projects).
 */
class LibraryResourceController extends Controller
{
    /**
     * Display a listing of all library resources.
     */
    public function index(Request $request)
    {
        $query = LibraryResource::query()
            ->with('creator:id,name')
            ->withCount('projects');

        // Filter by type (file or link)
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Search by title
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        $resources = $query->orderBy('created_at', 'desc')->get();

        return LibraryResourceResource::collection($resources);
    }

    /**
     * Store a newly created library resource.
     */
    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type', 'file');

        $rules = [
            'title' => 'required|string|max:255',
            'type' => 'sometimes|in:file,link',
            'category' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];

        if ($type === 'link') {
            $rules['url'] = 'required|url|max:2048';
        } else {
            $rules['file'] = 'required|file|max:51200'; // 50MB max
        }

        $validated = $request->validate($rules);

        $data = [
            'title' => $validated['title'],
            'type' => $type,
            'category' => $validated['category'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => Auth::id(),
        ];

        if ($type === 'link') {
            $data['url'] = $validated['url'];
        } else {
            $file = $request->file('file');
            $data['file_path'] = $file->store('library', 'local');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
        }

        $resource = LibraryResource::create($data);

        return $this->createdResponse(
            new LibraryResourceResource($resource),
            'Library resource created successfully'
        );
    }

    /**
     * Display the specified library resource.
     */
    public function show(LibraryResource $libraryResource): LibraryResourceResource
    {
        $libraryResource->load('creator:id,name');
        $libraryResource->loadCount('projects');
        
        return new LibraryResourceResource($libraryResource);
    }

    /**
     * Update the specified library resource.
     */
    public function update(Request $request, LibraryResource $libraryResource): LibraryResourceResource
    {
        $type = $request->input('type', $libraryResource->type ?? 'file');

        $rules = [
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|in:file,link',
            'category' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];

        if ($type === 'link') {
            $rules['url'] = 'sometimes|required|url|max:2048';
        } else {
            $rules['file'] = 'nullable|file|max:51200';
        }

        $validated = $request->validate($rules);

        // If switching from file to link, clean up old file
        if ($type === 'link' && $libraryResource->isFile()) {
            if ($libraryResource->file_path && Storage::disk('local')->exists($libraryResource->file_path)) {
                Storage::disk('local')->delete($libraryResource->file_path);
            }
            $validated['file_path'] = null;
            $validated['file_name'] = null;
            $validated['file_size'] = null;
        }

        // If switching from link to file, clear URL
        if ($type === 'file' && $libraryResource->isLink()) {
            $validated['url'] = null;
        }

        // Handle file replacement/upload
        if ($request->hasFile('file')) {
            if ($libraryResource->file_path && Storage::disk('local')->exists($libraryResource->file_path)) {
                Storage::disk('local')->delete($libraryResource->file_path);
            }
            $file = $request->file('file');
            $validated['file_path'] = $file->store('library', 'local');
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
        }

        unset($validated['file']);
        $validated['type'] = $type;
        $libraryResource->update($validated);

        return new LibraryResourceResource($libraryResource);
    }

    /**
     * Remove the specified library resource.
     */
    public function destroy(LibraryResource $libraryResource): JsonResponse
    {
        // Delete file from storage
        if ($libraryResource->file_path && Storage::disk('local')->exists($libraryResource->file_path)) {
            Storage::disk('local')->delete($libraryResource->file_path);
        }

        // Detach from all projects and todos first
        $libraryResource->projects()->detach();
        $libraryResource->todos()->detach();
        
        $libraryResource->delete();

        return $this->successResponse(null, 'Library resource deleted successfully');
    }

    /**
     * Download the library resource file.
     */
    public function download(LibraryResource $libraryResource)
    {
        if (!$libraryResource->file_path || !Storage::disk('local')->exists($libraryResource->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        return response()->download(
            Storage::disk('local')->path($libraryResource->file_path),
            $libraryResource->file_name ?? 'download'
        );
    }

    /**
     * Link a library resource to a project.
     */
    public function linkToProject(Request $request, LibraryResource $libraryResource): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        Gate::authorize('update', $project);

        // Check if already linked
        if ($libraryResource->projects()->where('project_id', $project->id)->exists()) {
            return $this->errorResponse('Resource is already linked to this project', 422);
        }

        $libraryResource->projects()->attach($project->id);

        return $this->successResponse(null, 'Resource linked to project successfully');
    }

    /**
     * Unlink a library resource from a project.
     */
    public function unlinkFromProject(Request $request, LibraryResource $libraryResource): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        Gate::authorize('update', $project);

        $libraryResource->projects()->detach($project->id);

        return $this->successResponse(null, 'Resource unlinked from project successfully');
    }

    /**
     * Get available categories.
     */
    public function categories(): JsonResponse
    {
        $categories = LibraryResource::distinct()
            ->whereNotNull('category')
            ->pluck('category');

        return $this->successResponse([
            'categories' => $categories,
            'suggested' => ['guides', 'templates', 'security', 'documentation', 'checklists'],
        ]);
    }
}
