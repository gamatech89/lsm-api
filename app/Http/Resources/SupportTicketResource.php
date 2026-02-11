<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
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
            'ticket_number' => $this->ticket_number,
            'project_id' => $this->project_id,
            
            // Ticket details
            'type' => $this->type,
            'type_label' => $this->type_label,
            'subject' => $this->subject,
            'message' => $this->message,
            
            // Client info
            'client_email' => $this->client_email,
            'client_name' => $this->client_name,
            'problem_page' => $this->problem_page,
            'site_url' => $this->site_url,
            
            // Status
            'status' => $this->status,
            'priority' => $this->priority,
            'is_open' => $this->is_open,
            
            // Read tracking
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toISOString(),
            
            // Resolution
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolution_notes' => $this->resolution_notes,
            
            // Linked todo
            'todo_id' => $this->todo_id,
            'todo' => $this->whenLoaded('todo', function () {
                return [
                    'id' => $this->todo->id,
                    'title' => $this->todo->title,
                    'status' => $this->todo->status,
                ];
            }),
            
            // Project (loaded for global view)
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'url' => $this->project->url,
                    'domain' => $this->project->domain,
                ];
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
