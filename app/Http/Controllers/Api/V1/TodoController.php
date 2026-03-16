<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TodoResource;
use App\Models\Project;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Todo Controller
 * 
 * Manages project todos with priority, status, and file attachments.
 */
class TodoController extends Controller
{
    /**
     * Get todos assigned to the current user across all projects.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function myTasks(Request $request)
    {
        $user = $request->user();
        
        $todos = Todo::with('project:id,name') // Eager load project name
            ->where('assignee_id', $user->id)
            ->where('status', '!=', 'completed')
            ->orderByRaw("
                CASE priority 
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END
            ")
            ->orderBy('due_date', 'asc')
            ->limit(20) // Limit to 20 for dashboard
            ->get();

        return TodoResource::collection($todos);
    }

    /**
     * Display a listing of todos for a project.
     *
     * @param Project $project
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $query = $project->todos()->with(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by assignee
        if ($request->filled('assignee_id')) {
            $query->where('assignee_id', $request->assignee_id);
        }

        // Hide completed by default
        if (!$request->boolean('include_completed', false)) {
            $query->where('status', '!=', 'completed');
        }

        $todos = $query->orderByRaw("
            CASE priority 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                ELSE 4 
            END
        ")->orderBy('due_date', 'asc')->get();

        return TodoResource::collection($todos);
    }

    /**
     * Display the specified todo.
     *
     * @param Todo $todo
     * @return TodoResource
     */
    public function show(Todo $todo): TodoResource
    {
        Gate::authorize('view', $todo->project);

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name']);

        return new TodoResource($todo);
    }

    /**
     * Store a newly created todo.
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
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,critical',
            'status' => 'sometimes|in:pending,in_progress,in_review,completed,cancelled',
            'due_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
            'file' => 'nullable|file|max:10240', // 10MB max
            'estimated_minutes' => 'nullable|integer|min:0',
            'library_resource_ids' => 'nullable|array',
            'library_resource_ids.*' => 'exists:library_resources,id',
        ]);

        $validated['project_id'] = $project->id;
        $validated['status'] = $validated['status'] ?? 'pending';

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('todos', 'local');
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
        }

        unset($validated['library_resource_ids']);

        $todo = Todo::create($validated);

        // Sync library resources if provided
        if ($request->has('library_resource_ids')) {
            $todo->libraryResources()->sync($request->input('library_resource_ids', []));
        }

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name']);

        // Notify assignee if set and not the creator (optional check, but good practice)
        // Here we just check if assignee exists
        if ($todo->assignee) {
            $todo->load('project'); // Ensure project is loaded for notification
            $todo->assignee->notify(new \App\Notifications\TodoAssignedNotification($todo));
        }

        return $this->createdResponse(
            new TodoResource($todo),
            'Todo created successfully'
        );
    }

    /**
     * Update the specified todo.
     *
     * @param Request $request
     * @param Todo $todo
     * @return TodoResource
     */
    public function update(Request $request, Todo $todo): TodoResource
    {
        Gate::authorize('update', $todo->project);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'status' => 'sometimes|in:pending,in_progress,in_review,completed,cancelled',
            'completed' => 'sometimes|boolean',
            'due_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
            'file' => 'nullable|file|max:10240',
            'estimated_minutes' => 'nullable|integer|min:0',
            'library_resource_ids' => 'nullable|array',
            'library_resource_ids.*' => 'exists:library_resources,id',
        ]);

        // Handle completed status
        if (isset($validated['completed'])) {
            $validated['status'] = $validated['completed'] ? 'completed' : 'pending';
            unset($validated['completed']);
        }

        // Handle file upload
        if ($request->hasFile('file')) {
            // Delete old file if exists
            if ($todo->file_path && Storage::disk('local')->exists($todo->file_path)) {
                Storage::disk('local')->delete($todo->file_path);
            }
            
            $file = $request->file('file');
            $path = $file->store('todos', 'local');
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
        }

        unset($validated['library_resource_ids']);
        $todo->update($validated);

        // Sync library resources if provided
        if ($request->has('library_resource_ids')) {
            $todo->libraryResources()->sync($request->input('library_resource_ids', []));
        }

        // Notify if assignee changed and is set
        if ($todo->wasChanged('assignee_id') && $todo->assignee_id) {
            $todo->load(['project', 'assignee']); 
            $todo->assignee->notify(new \App\Notifications\TodoAssignedNotification($todo));
        }

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name']);

        return new TodoResource($todo);
    }

    /**
     * Remove the specified todo.
     *
     * @param Todo $todo
     * @return JsonResponse
     */
    public function destroy(Todo $todo): JsonResponse
    {
        Gate::authorize('update', $todo->project);

        // Delete attached file if exists
        if ($todo->file_path && Storage::disk('local')->exists($todo->file_path)) {
            Storage::disk('local')->delete($todo->file_path);
        }

        $todo->delete();

        return $this->successResponse(null, 'Todo deleted successfully');
    }

    /**
     * Download the attached file for a todo.
     *
     * @param Todo $todo
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function download(Todo $todo)
    {
        Gate::authorize('view', $todo->project);

        if (!$todo->file_path || !Storage::disk('local')->exists($todo->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        return response()->download(
            Storage::disk('local')->path($todo->file_path),
            $todo->file_name ?? 'download'
        );
    }

    /**
     * Preview the attached file inline (for images).
     *
     * @param Todo $todo
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function preview(Todo $todo)
    {
        Gate::authorize('view', $todo->project);

        if (!$todo->file_path || !Storage::disk('local')->exists($todo->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        $path = Storage::disk('local')->path($todo->file_path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . ($todo->file_name ?? 'preview') . '"',
        ]);
    }
}
