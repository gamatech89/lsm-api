<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Todo Resource
 * 
 * Transforms Todo model for API responses.
 */
class TodoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'completed' => $this->completed,
            'due_date' => $this->due_date?->toISOString(),
            
            // File attachment
            'file_path' => $this->file_path,
            'file_name' => $this->file_name,
            'has_attachment' => !empty($this->file_path),
            
            // Linked library resources
            'library_resources' => LibraryResourceResource::collection($this->whenLoaded('libraryResources')),

            // Time estimate
            'estimated_minutes' => $this->estimated_minutes,
            
            // Assignee
            'assignee_id' => $this->assignee_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            
            // Project reference (conditionally loaded)
            'project' => new ProjectResource($this->whenLoaded('project')),
            
            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
