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
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'file' => 'required|file|max:51200', // 50MB max
            'notes' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $path = $file->store('library', 'local');

        $resource = LibraryResource::create([
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'notes' => $validated['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

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
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'file' => 'nullable|file|max:51200',
            'notes' => 'nullable|string',
        ]);

        // Handle file replacement
        if ($request->hasFile('file')) {
            // Delete old file
            if ($libraryResource->file_path && Storage::disk('local')->exists($libraryResource->file_path)) {
                Storage::disk('local')->delete($libraryResource->file_path);
            }

            $file = $request->file('file');
            $validated['file_path'] = $file->store('library', 'local');
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
        }

        unset($validated['file']);
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

        // Detach from all projects first
        $libraryResource->projects()->detach();
        
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
