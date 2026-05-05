<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TodoResource;
use App\Models\Project;
use App\Models\Todo;
use App\Models\TodoAttachment;
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

        $query = $project->todos()->with(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name', 'attachments', 'resources:id,title,type,url,file_name']);

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

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name', 'attachments', 'resources:id,title,type,url,file_name']);

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
            'file' => 'nullable|file|max:10240', // Legacy single file
            'files' => 'nullable|array|max:5',
            'files.*' => 'file|max:10240', // 10MB per file
            'estimated_minutes' => 'nullable|integer|min:0',
            'library_resource_ids' => 'nullable|array',
            'library_resource_ids.*' => 'exists:library_resources,id',
            'resource_ids' => 'nullable|array',
            'resource_ids.*' => 'exists:resources,id',
        ]);

        $validated['project_id'] = $project->id;
        $validated['status'] = $validated['status'] ?? 'pending';

        // Legacy single file support
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('todos', 'local');
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
        }

        unset($validated['library_resource_ids'], $validated['resource_ids'], $validated['files']);

        $todo = Todo::create($validated);

        // Handle multiple file attachments
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('todo-attachments', 'local');
                $todo->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        // Sync library resources if provided
        if ($request->has('library_resource_ids')) {
            $todo->libraryResources()->sync($request->input('library_resource_ids', []));
        }

        // Sync project resources if provided
        if ($request->has('resource_ids')) {
            $todo->resources()->sync($request->input('resource_ids', []));
        }

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name', 'attachments', 'resources:id,title,type,url,file_name']);

        // Notify assignee if set
        if ($todo->assignee) {
            $todo->load('project');
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

        $user = $request->user();
        $isDeveloper = $user->role === 'developer' && !$user->isAdmin();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'status' => $isDeveloper
                ? 'sometimes|in:pending,in_progress,in_review'
                : 'sometimes|in:pending,in_progress,in_review,completed,cancelled',
            'completed' => 'sometimes|boolean',
            'due_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
            'file' => 'nullable|file|max:10240',
            'files' => 'nullable|array|max:5',
            'files.*' => 'file|max:10240',
            'delete_attachment_ids' => 'nullable|array',
            'delete_attachment_ids.*' => 'integer',
            'estimated_minutes' => 'nullable|integer|min:0',
            'library_resource_ids' => 'nullable|array',
            'library_resource_ids.*' => 'exists:library_resources,id',
            'resource_ids' => 'nullable|array',
            'resource_ids.*' => 'exists:resources,id',
        ]);

        // Developers cannot change assignee
        if ($isDeveloper && isset($validated['assignee_id'])) {
            unset($validated['assignee_id']);
        }

        // Handle completed status
        if (isset($validated['completed'])) {
            $validated['status'] = $validated['completed'] ? 'completed' : 'pending';
            unset($validated['completed']);
        }

        // Prevent developers from using the `completed` boolean to bypass status restriction
        if ($isDeveloper && isset($validated['status']) && $validated['status'] === 'completed') {
            $validated['status'] = 'in_review';
        }

        // Handle legacy single file upload
        if ($request->hasFile('file')) {
            if ($todo->file_path && Storage::disk('local')->exists($todo->file_path)) {
                Storage::disk('local')->delete($todo->file_path);
            }
            $file = $request->file('file');
            $path = $file->store('todos', 'local');
            $validated['file_path'] = $path;
            $validated['file_name'] = $file->getClientOriginalName();
        }

        // Delete specified attachments
        if ($request->has('delete_attachment_ids')) {
            $attachmentsToDelete = $todo->attachments()
                ->whereIn('id', $request->input('delete_attachment_ids'))
                ->get();
            foreach ($attachmentsToDelete as $attachment) {
                if (Storage::disk('local')->exists($attachment->file_path)) {
                    Storage::disk('local')->delete($attachment->file_path);
                }
                $attachment->delete();
            }
        }

        unset($validated['library_resource_ids'], $validated['resource_ids'], $validated['files'], $validated['delete_attachment_ids']);
        $todo->update($validated);

        // Handle multiple file attachments
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('todo-attachments', 'local');
                $todo->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        // Sync library resources if provided
        if ($request->has('library_resource_ids')) {
            $todo->libraryResources()->sync($request->input('library_resource_ids', []));
        }

        // Sync project resources if provided
        if ($request->has('resource_ids')) {
            $todo->resources()->sync($request->input('resource_ids', []));
        }

        // Notify if assignee changed and is set
        if ($todo->wasChanged('assignee_id') && $todo->assignee_id) {
            $todo->load(['project', 'assignee']); 
            $todo->assignee->notify(new \App\Notifications\TodoAssignedNotification($todo));
        }

        $todo->load(['assignee:id,name,email', 'libraryResources:id,title,type,url,file_name', 'attachments', 'resources:id,title,type,url,file_name']);

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

        // Delete attached file if exists (legacy)
        if ($todo->file_path && Storage::disk('local')->exists($todo->file_path)) {
            Storage::disk('local')->delete($todo->file_path);
        }

        // Delete all attachments
        foreach ($todo->attachments as $attachment) {
            if (Storage::disk('local')->exists($attachment->file_path)) {
                Storage::disk('local')->delete($attachment->file_path);
            }
        }

        $todo->delete();

        return $this->successResponse(null, 'Todo deleted successfully');
    }

    /**
     * Download the attached file for a todo (legacy single file).
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
     * Preview the attached file inline (legacy single file).
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

    /**
     * Delete a specific attachment from a todo.
     */
    public function deleteAttachment(Todo $todo, TodoAttachment $attachment): JsonResponse
    {
        Gate::authorize('update', $todo->project);

        if ($attachment->todo_id !== $todo->id) {
            return $this->errorResponse('Attachment does not belong to this todo', 403);
        }

        if (Storage::disk('local')->exists($attachment->file_path)) {
            Storage::disk('local')->delete($attachment->file_path);
        }

        $attachment->delete();

        return $this->successResponse(null, 'Attachment deleted successfully');
    }

    /**
     * Download a specific attachment.
     */
    public function downloadAttachment(Todo $todo, TodoAttachment $attachment)
    {
        Gate::authorize('view', $todo->project);

        if ($attachment->todo_id !== $todo->id) {
            return $this->errorResponse('Attachment does not belong to this todo', 403);
        }

        if (!Storage::disk('local')->exists($attachment->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        return response()->download(
            Storage::disk('local')->path($attachment->file_path),
            $attachment->file_name
        );
    }

    /**
     * Preview a specific attachment inline.
     */
    public function previewAttachment(Todo $todo, TodoAttachment $attachment)
    {
        Gate::authorize('view', $todo->project);

        if ($attachment->todo_id !== $todo->id) {
            return $this->errorResponse('Attachment does not belong to this todo', 403);
        }

        if (!Storage::disk('local')->exists($attachment->file_path)) {
            return $this->notFoundResponse('File not found');
        }

        $path = Storage::disk('local')->path($attachment->file_path);
        $mimeType = $attachment->mime_type ?: (mime_content_type($path) ?: 'application/octet-stream');

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $attachment->file_name . '"',
        ]);
    }
}
